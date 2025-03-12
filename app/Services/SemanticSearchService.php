<?php

namespace App\Services;

use Phalcon\Di\Injectable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class SemanticSearchService extends Injectable
{
    private const SERVICE_URL = 'http://host.docker.internal:5050';
    private $httpClient;
    private $logger;

    public function __construct()
    {
        $this->logger = $this->getDI()->getLogger();
        $this->httpClient = new Client([
            'timeout' => 0,
            'connect_timeout' => 3.0
        ]);
    }

    /**
     * Index a tag for semantic search
     *
     * @param int $memeId
     * @param string $tag
     * @return bool
     */
    public function indexTag(int $memeId, string $tag): bool
    {
        try {
            $response = $this->httpClient->post(self::SERVICE_URL . '/index-tag', [
                'json' => [
                    'meme_id' => $memeId,
                    'tag' => $tag
                ],
                'headers' => ['Content-Type' => 'application/json']
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            return isset($result['status']) && $result['status'] === 'success';
        } catch (GuzzleException $e) {
            $this->logger->error(
                'Failed to index tag: ' . $e->getMessage(),
                ['exception' => $e, 'meme_id' => $memeId, 'tag' => $tag]
            );
            return false;
        }
    }

    /**
     * Perform semantic search for memes
     *
     * @param string $query Search query in natural language
     * @param float $minSimilarity Minimum similarity threshold (0-1)
     * @param int $limit Maximum number of results to return
     * @return array Array of meme IDs with their similarity scores
     */
    public function semanticSearch(string $query, float $minSimilarity = 0.3, int $limit = 20): array
    {
        try {
            $response = $this->httpClient->post(self::SERVICE_URL . '/semantic-search', [
                'json' => [
                    'query' => $query,
                    'min_similarity' => $minSimilarity,
                    'limit' => $limit
                ],
                'headers' => ['Content-Type' => 'application/json']
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($result['results'])) {
                return [];
            }

            // Transform results to array of meme IDs with scores
            $memeScores = [];
            foreach ($result['results'] as $item) {
                $memeId = $item['meme_id'];
                // If we have multiple matches for the same meme, keep the highest similarity score
                if (!isset($memeScores[$memeId]) || $item['similarity'] > $memeScores[$memeId]) {
                    $memeScores[$memeId] = $item['similarity'];
                }
            }

            return $memeScores;
        } catch (GuzzleException $e) {
            $this->logger->error(
                'Semantic search failed: ' . $e->getMessage(),
                ['exception' => $e, 'query' => $query]
            );
            return [];
        }
    }
}
