<?php

namespace App\Services\Crawlers;

use App\Services\CrawlerService;
use GuzzleHttp\Client;

class TenorCrawler extends CrawlerService
{
    private const ITEMS_PER_PAGE = 50;
    private const MAX_PAGES = 10;
    private const API_BASE = 'https://tenor.googleapis.com/v2/';

    /**
     * Get search queries by language
     */
    protected function getSearchQueries(): array
    {
        $searchQueries = [
            'ar' => [
                'حسن حسني',
                'تامر حسني',
                'محمد هنيدي',
                'طلعت زكريا',
                'عادل امام',
                'عادل امام',
            ],
            'en' => [
                'gangster',
                'babies',
                'musician',
                'work memes'
            ]
        ];

        return $searchQueries;
    }

    /**
     * Crawl memes from Tenor
     */
    public function crawl(string $language = 'en'): void
    {
        try {
            $queries = $this->getSearchQueries();
            if (!isset($queries[$language])) {
                throw new \Exception("No search queries defined for language: $language");
            }

            $client = new Client([
                'base_uri' => self::API_BASE,
                'timeout' => 30,
            ]);

            foreach ($queries[$language] as $query) {
                for ($page = 1; $page <= self::MAX_PAGES; $page++) {
                    $response = $client->get('search', [
                        'query' => [
                            'q' => $query,
                            'key' => $this->config->tenor->api_key,
                            'client_key' => 'memes',
                            'media_filter' => 'gif',
                            'limit' => self::ITEMS_PER_PAGE,
                            'pos' => ($page - 1) * self::ITEMS_PER_PAGE
                        ]
                    ]);

                    $data = json_decode($response->getBody(), true);
                    if (empty($data['results'])) {
                        break;
                    }

                    foreach ($data['results'] as $result) {
                        $this->stats['total_processed']++;

                        try {
                            // Get the GIF URL
                            if (empty($result['media_formats']['gif']['url'])) {
                                continue;
                            }

                            $gifUrl = $result['media_formats']['gif']['url'];
                            $width = $result['media_formats']['gif']['dims'][0] ?? 0;
                            $height = $result['media_formats']['gif']['dims'][1] ?? 0;

                            // Extract tags
                            $tags = [];
                            if (!empty($result['tags'])) {
                                $tags = array_merge($tags, $result['tags']);
                            }
                            if (!empty($result['content_description'])) {
                                $tags = array_merge(
                                    $tags,
                                    $this->extractTags($result['content_description'], $language)
                                );
                            }
                            $tags = array_unique(array_filter($tags));

                            $imageContent = $response->getBody()->getContents();
                            $imageHash = md5($imageContent);

                            // Save meme
                            if ($this->saveMeme($gifUrl, $tags, $language, $language, $imageContent, $imageHash)) {
                                $this->stats['total_saved']++;
                            }
                        } catch (\Exception $e) {
                            $this->logger->error(
                                sprintf(
                                    "[Tenor Crawler] Failed to process result: %s\nTrace:\n%s",
                                    $e->getMessage(),
                                    $e->getTraceAsString()
                                ),
                                [
                                    'query' => $query,
                                    'page' => $page,
                                    'language' => $language,
                                    'result' => $result
                                ]
                            );
                            $this->stats['total_errors']++;
                        }
                    }

                    // Respect rate limits
                    sleep(1);
                }
            }
        } catch (\Exception $e) {
            $this->di->getLogger()->error(
                sprintf(
                    "[Tenor Crawler] Crawl failed: %s\nTrace:\n%s",
                    $e->getMessage(),
                    $e->getTraceAsString()
                ),
                [
                    'language' => $language
                ]
            );
            throw $e;
        }
    }
}
