<?php

namespace App\Services;

use App\Models\Memes;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Phalcon\Di\Injectable;
use Phalcon\Logger;

class VectorSearchService extends Injectable
{
    private string $searchServiceUrl;
    private Client $httpClient;
    private Logger $logger;
    
    public function __construct()
    {
        $this->logger = $this->getDI()->getLogger();
        
        // Get search service URL from environment or use default
        $searchHost = getenv('SEARCH_HOST') ?: 'search';
        $searchPort = getenv('SEARCH_PORT') ?: '5051';
        $this->searchServiceUrl = "{$searchHost}:{$searchPort}";
        
        $this->httpClient = new Client([
            'base_uri' => $this->searchServiceUrl,
            'timeout' => 30,
        ]);
    }
    
    /**
     * Index a meme in the vector search service
     * 
     * @param Memes $meme
     * @return bool
     */
    public function indexMeme(Memes $meme): bool
    {
        try {
            // Get tags in both languages
            $englishTags = array_column($meme->getTagsByLanguage('english')->toArray(), 'tag');
            $arabicTags = array_column($meme->getTagsByLanguage('arabic')->toArray(), 'tag');
            
            // Combine tags, removing duplicates
            $allTags = array_unique(array_merge($englishTags, $arabicTags));
            
            $response = $this->httpClient->post('/index_meme', [
                'json' => [
                    'id' => $meme->id,
                    'media_url' => $meme->getPublicUrl(),
                    'tags' => $allTags,
                    'caption' => $meme->caption ?? '',
                    'meme_type' => $meme->meme_type
                ]
            ]);
            
            if ($response->getStatusCode() === 200) {
                $meme->embedding_status = 'indexed';
                $meme->last_indexed_at = date('Y-m-d H:i:s');
                $meme->save();
                return true;
            }
            
            $this->logger->error("Failed to index meme {$meme->id}: " . $response->getBody());
            return false;
            
        } catch (GuzzleException $e) {
            $this->logger->error("Error indexing meme {$meme->id}: " . $e->getMessage());
            $meme->embedding_status = 'failed';
            $meme->save();
            return false;
        }
    }
    
    /**
     * Batch index multiple memes in the vector search service
     * 
     * @param array $memes Array of meme data to index
     * @return bool
     */
    public function batchIndexMemes(array $memes): bool
    {
        try {
            $response = $this->httpClient->post('/batch_index_memes', [
                'json' => [
                    'memes' => $memes
                ],
                'timeout' => 300  // 5 minute timeout for large batches
            ]);
            
            if ($response->getStatusCode() === 200) {
                return true;
            }
            
            $this->logger->error("Failed to batch index memes: " . $response->getBody());
            return false;
            
        } catch (GuzzleException $e) {
            $this->logger->error("Error batch indexing memes: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Search memes using vector similarity
     * 
     * @param string $query
     * @param int $limit
     * @param array $filters
     * @return array
     */
    public function search(string $query, int $limit = 10, array $filters = []): array
    {
        try {
            $response = $this->httpClient->post('/search', [
                'json' => [
                    'query' => $query,
                    'limit' => $limit
                ]
            ]);
            
            $data = json_decode($response->getBody(), true);
            return $data['results'] ?? [];
        } catch (\Exception $e) {
            $this->logger->error('Vector search failed: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Hybrid search for memes
     * 
     * @param string $query Query string
     * @param array $filters Additional filters
     * @param int $page Page number (0-based)
     * @param int $limit Results per page
     * @param float $minScore Minimum score threshold (0-1)
     * @return array Returns ['items' => array, 'total' => int]
     */
    public function hybridSearch(string $query, array $filters = [], int $page = 0, int $limit = 20, float $minScore = 0): array
    {
        try {
            // Calculate how many results we need for all pages up to current
            $totalNeeded = ($page + 1) * $limit;
            
            // Get vector search results
            $vectorResults = $this->search($query, $totalNeeded * 2);  // Get more results to account for filtering
            
            // Create score mapping
            $vectorScores = [];
            foreach ($vectorResults as $result) {
                $vectorScores[$result['id']] = $result['score'];
            }

            // Get text search results with scores from Python service
            try {
                $response = $this->httpClient->post('/text_search', [
                    'json' => [
                        'query' => $query,
                        'filters' => $filters,
                        'limit' => $totalNeeded * 2  // Get more results to account for filtering
                    ]
                ]);
                
                $textSearchData = json_decode($response->getBody(), true);
                $textResults = $textSearchData['results'] ?? [];
            } catch (\Exception $e) {
                $this->logger->error('Text search failed: ' . $e->getMessage());
                $textResults = [];
            }
            
            // Combine and normalize scores
            $combinedResults = [];
            $processedIds = [];
            
            // Handle empty results
            if (empty($vectorScores) && empty($textResults)) {
                return [
                    'items' => [],
                    'total' => 0
                ];
            }
            
            // Calculate max scores safely
            $maxVectorScore = !empty($vectorScores) ? max($vectorScores) : 1;
            
            // Detect if query is Arabic
            $isArabic = preg_match('/\p{Arabic}/u', $query);
            
            // Process text search results first
            foreach ($textResults as $result) {
                if (!isset($result['id']) || !isset($result['score'])) {
                    continue;
                }
                
                $id = $result['id'];
                $normalizedVectorScore = isset($vectorScores[$id]) ? ($vectorScores[$id] / $maxVectorScore) : 0;
                
                // Text score is already normalized and includes semantic + text match components
                $textScore = $result['score'];
                
                // For Arabic queries, prioritize text matches more heavily
                if ($isArabic) {
                    $combinedScore = ($textScore * 0.8) + ($normalizedVectorScore * 0.2);
                } else {
                    $combinedScore = ($textScore * 0.6) + ($normalizedVectorScore * 0.4);
                }
                
                // Skip results below threshold
                if ($combinedScore < $minScore) {
                    continue;
                }
                
                $resultData = [
                    'id' => $id,
                    'score' => $combinedScore,
                    'vector_score' => $vectorScores[$id] ?? 0,
                    'text_score' => $textScore,
                    'semantic_similarity' => $result['semantic_similarity'] ?? 0,
                    'text_match_score' => $result['text_match_score'] ?? 0,
                    'caption_score' => $result['caption_score'] ?? 0,
                    'tag_score' => $result['best_tag_score'] ?? 0
                ];
                
                $combinedResults[] = $resultData;
                $processedIds[$id] = true;
            }
            
            // Process remaining vector results that weren't in text results
            foreach ($vectorResults as $result) {
                $id = $result['id'];
                
                // Skip if already processed
                if (isset($processedIds[$id])) {
                    continue;
                }
                
                $normalizedVectorScore = $result['score'] / $maxVectorScore;
                
                // For vector-only results, use a lower weight
                $combinedScore = $normalizedVectorScore * ($isArabic ? 0.2 : 0.4);
                
                // Skip results below threshold
                if ($combinedScore < $minScore) {
                    continue;
                }
                
                $resultData = [
                    'id' => $id,
                    'score' => $combinedScore,
                    'vector_score' => $result['score'],
                    'text_score' => 0,
                    'semantic_similarity' => 0,
                    'text_match_score' => 0,
                    'caption_score' => 0,
                    'tag_score' => 0
                ];
                
                $combinedResults[] = $resultData;
            }
            
            // Sort by combined score
            if (!empty($combinedResults)) {
                usort($combinedResults, function($a, $b) {
                    return $b['score'] <=> $a['score'];
                });
            }
            
            // Calculate total results that meet the score threshold
            $total = count($combinedResults);
            
            // Get the slice for current page
            $offset = $page * $limit;
            $items = array_slice($combinedResults, $offset, $limit);
            
            return [
                'items' => $items,
                'total' => $total
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Hybrid search failed: ' . $e->getMessage());
            return [
                'items' => [],
                'total' => 0
            ];
        }
    }
    
    /**
     * Reindex all memes that need indexing
     * 
     * @param int $batchSize
     * @return array
     */
    public function reindexPendingMemes(int $batchSize = 100): array
    {
        $stats = ['success' => 0, 'failed' => 0];
        
        $memes = Memes::find([
            'conditions' => 'embedding_status IN ({statuses:array})',
            'bind' => [
                'statuses' => ['pending', 'failed']
            ],
            'limit' => $batchSize
        ]);
        
        foreach ($memes as $meme) {
            if ($this->indexMeme($meme)) {
                $stats['success']++;
            } else {
                $stats['failed']++;
            }
        }
        
        return $stats;
    }
}
