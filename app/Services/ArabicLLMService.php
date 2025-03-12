<?php

namespace App\Services;

use GuzzleHttp\Client;
use Phalcon\Di\Injectable;

class ArabicLLMService extends Injectable
{
    private $client;
    private $apiKey;
    private $baseUrl = 'https://api-inference.huggingface.co/models/CAMeL-Lab/bert-base-arabic-camelbert-ca';

    public function __construct()
    {
        $this->apiKey = getenv('HUGGINGFACE_API_KEY');
        $this->client = new Client([
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ]
        ]);
    }

    /**
     * Get embeddings for Arabic text
     */
    public function getEmbeddings(string $text): array
    {
        try {
            $response = $this->client->post($this->baseUrl, [
                'json' => [
                    'inputs' => $text,
                    'options' => ['wait_for_model' => true]
                ]
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            $this->getDI()->getLogger()->error(
                'Error getting embeddings: ' . $e->getMessage(),
                ['exception' => $e]
            );
            return [];
        }
    }

    /**
     * Get semantically similar variations of an Arabic word
     */
    public function getSimilarWords(string $word): array
    {
        try {
            $response = $this->client->post($this->baseUrl . '/fill-mask', [
                'json' => [
                    'inputs' => str_replace('_', '[MASK]', $word),
                ]
            ]);

            $results = json_decode($response->getBody()->getContents(), true);
            return array_map(function($item) {
                return $item['token_str'];
            }, $results);
        } catch (\Exception $e) {
            $this->getDI()->getLogger()->error(
                'Error getting similar words: ' . $e->getMessage(),
                ['exception' => $e]
            );
            return [];
        }
    }

    /**
     * Expand Arabic search query with semantically similar terms
     */
    public function expandSearchQuery(string $query): array
    {
        $words = explode(' ', trim($query));
        $expandedTerms = [];

        foreach ($words as $word) {
            if (preg_match('/\p{Arabic}/u', $word)) {
                $similarWords = $this->getSimilarWords($word);
                $expandedTerms = array_merge($expandedTerms, $similarWords);
            }
        }

        return array_unique(array_merge($words, $expandedTerms));
    }
}
