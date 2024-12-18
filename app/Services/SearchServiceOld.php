<?php

namespace App\Services;

use Predis\Client;
use Phalcon\Di;
use Phalcon\Logger;

class SearchServiceOld
{
    private Client $redis;
    private const INDEX_NAME = 'memes_idx';
    private const SUGGESTION_KEY_EN = 'memes_suggestions_en';
    private const SUGGESTION_KEY_AR = 'memes_suggestions_ar';

    public function __construct()
    {
        $config = Di::getDefault()->get('config')->redis;
        $this->redis = new Client([
            'scheme' => 'tcp',
            'host'   => $config->host,
            'port'   => $config->port,
            'password' => $config->password
        ]);

        $this->ensureIndex();
    }

    /**
     * Ensure RediSearch index exists
     */
    private function ensureIndex()
    {
        try {
            // Try to drop existing index if it exists
            try {
                $this->redis->executeRaw(['FT.DROPINDEX', self::INDEX_NAME]);
            } catch (\Exception $e) {
                // Index doesn't exist, which is fine
            }

            // Create new index
            $this->redis->executeRaw([
                'FT.CREATE', self::INDEX_NAME,
                'ON', 'HASH',
                'PREFIX', '1', 'meme:',
                'SCHEMA',
                'tag', 'TEXT', 'WEIGHT', '5.0', 'SORTABLE',
                'language', 'TAG', 'SORTABLE',
                'meme_id', 'NUMERIC', 'SORTABLE',
                'tag_normalized', 'TEXT', 'NOSTEM', 'SORTABLE'
            ]);
            
            error_log("Successfully created RediSearch index: " . self::INDEX_NAME);
        } catch (\Exception $e) {
            error_log("Error creating RediSearch index: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Index a meme tag
     */
    public function indexTag(int $memeId, string $tag, string $language)
    {
        try {
            $normalizedTag = $this->normalizeTag($tag);
            $key = "meme:{$memeId}";

            // Store basic fields
            $this->redis->hmset($key, [
                'tag' => $tag,
                'tag_normalized' => $normalizedTag,
                'language' => $language,
                'meme_id' => $memeId
            ]);

            error_log("Successfully indexed tag: $tag for meme: $memeId");
            return true;
        } catch (\Exception $e) {
            error_log("Error indexing tag: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Search memes by tag with fuzzy matching
     */
    public function search(string $query, string $language = null, int $limit = 20, int $offset = 0)
    {
        try {
            $normalizedQuery = $this->normalizeTag($query);
            
            // Build search query
            $searchQuery = "@tag_normalized:$normalizedQuery";
            
            if ($language) {
                $searchQuery .= " @language:$language";
            }

            error_log("Executing search with query: " . $searchQuery);

            $results = $this->redis->executeRaw([
                'FT.SEARCH',
                self::INDEX_NAME,
                $searchQuery,
                'WITHSCORES',
                'LIMIT',
                $offset,
                $limit
            ]);

            return $this->parseSearchResults($results);
        } catch (\Exception $e) {
            error_log("Error searching: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get search suggestions with fuzzy matching
     */
    public function getSuggestions(string $prefix, string $language = null, int $limit = 5)
    {
        try {
            $suggestionKey = $language === 'ar' ? self::SUGGESTION_KEY_AR : self::SUGGESTION_KEY_EN;
            
            // Get suggestions with fuzzy matching
            $suggestions = $this->redis->executeRaw([
                'FT.SUGGET',
                $suggestionKey,
                $prefix,
                'FUZZY',
                'MAX', '3',
                'WITHSCORES'
            ]);

            // Format suggestions
            $result = [];
            if ($suggestions) {
                for ($i = 0; $i < count($suggestions); $i += 2) {
                    $suggestion = $suggestions[$i];
                    $score = $suggestions[$i + 1];
                    
                    // Only include suggestions with good scores
                    if ($score > 0.3) {
                        $result[] = [
                            'text' => $suggestion,
                            'score' => $score
                        ];
                    }
                }

                // Sort by score
                usort($result, function($a, $b) {
                    return $b['score'] <=> $a['score'];
                });

                // Limit results
                $result = array_slice($result, 0, $limit);
            }

            return $result;
        } catch (\Exception $e) {
            error_log("Error getting suggestions: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Normalize tag for better search
     */
    private function normalizeTag(string $tag): string
    {
        // For Arabic text, we don't do much normalization to preserve meaning
        if (preg_match('/\p{Arabic}/u', $tag)) {
            return trim($tag);
        }

        // For English text, normalize to improve matching
        return strtolower(trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $tag)));
    }

    /**
     * Parse RediSearch results
     */
    private function parseSearchResults(array $results): array
    {
        $parsedResults = [
            'total' => $results[0],
            'items' => []
        ];

        // Start parsing from the second element
        for ($i = 1; $i < count($results); $i += 2) {
            $key = $results[$i];
            $attributes = $results[$i + 1];

            if (is_array($attributes)) {
                $mappedAttributes = [];
                for ($j = 0; $j < count($attributes); $j += 2) {
                    $mappedAttributes[$attributes[$j]] = $attributes[$j + 1];
                }

                $parsedResults['items'][] =  $mappedAttributes;
            }
        }

        return $parsedResults;
    }
}
