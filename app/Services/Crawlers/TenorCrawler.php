<?php

namespace App\Services\Crawlers;

use App\Services\CrawlerService;
use GuzzleHttp\Client;

class TenorCrawler extends CrawlerService
{
    private const ITEMS_PER_PAGE = 50;
    private const MAX_PAGES = 10; // Maximum pages per keyword
    private const API_BASE = 'https://tenor.googleapis.com/v2/';
    private const MAX_RESULTS_PER_KEYWORD = 500; // 50 pages * 50 items per page

    protected string $sourceName = 'tenor';

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
//            'en' => [
//                'gangster',
//                'babies',
//                'musician',
//                'work memes'
//            ]
        ];

        return $searchQueries;
    }

    /**
     * Crawl memes from Tenor
     */
    public function crawl(): void
    {
        try {
            $queries = $this->getSearchQueries();

            $client = new Client([
                'base_uri' => self::API_BASE,
                'timeout' => 30,
            ]);

            foreach ($queries as $language => $keywords) {
                foreach ($keywords as $keyword) {
                    $pos = null;
                    $resultsCount = 0;
                    
                    for ($page = 1; $page <= self::MAX_PAGES; $page++) {
                        echo "Fetching page $page for keyword '$keyword' ($language)...\n";
                        
                        $queryParams = [
                            'q' => $keyword,
                            'key' => $this->config->tenor->api_key,
                            'client_key' => 'memes',
                            'media_filter' => 'gif',
                            'limit' => self::ITEMS_PER_PAGE
                        ];

                        // Add pos parameter for pagination if we have it
                        if ($pos !== null) {
                            $queryParams['pos'] = $pos;
                        }

                        $response = $client->get('search', [
                            'query' => $queryParams
                        ]);

                        $data = json_decode($response->getBody(), true);
                        if (empty($data['results'])) {
                            echo "No more results for keyword '$keyword'\n";
                            break;
                        }

                        // Get the next position for pagination
                        $pos = $data['next'] ?? null;
                        if ($pos === null) {
                            echo "No next page available for keyword '$keyword'\n";
                            break;
                        }

                        foreach ($data['results'] as $result) {
                            $this->stats['total_processed']++;
                            $resultsCount++;

                            try {
                                // Get the GIF URL
                                if (empty($result['media_formats']['gif']['url'])) {
                                    continue;
                                }

                                $gifUrl = $result['media_formats']['gif']['url'];

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
                                $tags = array_combine($tags, array_fill(0, count($tags), 0.9));

                                $imageContent = file_get_contents($gifUrl);
                                $imageHash = md5($imageContent);

                                // Save meme
                                if ($meme = $this->saveMeme($gifUrl, $tags, $this->sourceName, $language, $imageContent, $imageHash)) {
                                    $this->stats['total_saved']++;
                                    echo "Meme $meme->id saved successfully!\n";
                                }
                            } catch (\Exception $e) {
                                $this->logger->error(
                                    sprintf(
                                        "[Tenor Crawler] Failed to process result: %s\nTrace:\n%s",
                                        $e->getMessage(),
                                        $e->getTraceAsString()
                                    ),
                                    [
                                        'query' => $keyword,
                                        'page' => $page,
                                        'language' => $language,
                                        'result' => $result
                                    ]
                                );
                                $this->stats['total_errors']++;
                            }

                            // Check if we've reached the maximum results for this keyword
                            if ($resultsCount >= self::MAX_RESULTS_PER_KEYWORD) {
                                echo "Reached maximum results limit for keyword '$keyword'\n";
                                break 2; // Break both foreach and for loops
                            }
                        }

                        // Respect rate limits
                        sleep(1);
                    }
                    
                    echo "Finished processing keyword '$keyword' ($resultsCount results)\n";
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
                    'language' => $language ?? null
                ]
            );
            throw $e;
        }
    }
}
