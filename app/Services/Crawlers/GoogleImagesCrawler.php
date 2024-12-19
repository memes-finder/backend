<?php

namespace App\Services\Crawlers;

use App\Services\CrawlerService;
use App\Models\CrawlerState;
use App\Models\Memes;
use Google\Client;
use Google\Service\CustomSearchAPI;

class GoogleImagesCrawler extends CrawlerService
{
    protected string $sourceName = 'google_images';
    private CustomSearchAPI $searchService;
    private array $stopWords = [
        'en' => ['the', 'and', 'for', 'that', 'this', 'with', 'was', 'are', 'what', 'when', 'where', 'who', 'how'],
        'ar' => ['من', 'في', 'على', 'الى', 'هذا', 'هذه', 'كان', 'كانت', 'مع', 'عن', 'لكن', 'ثم', 'او', 'أو']
    ];
    private const DAILY_LIMIT = 500;
    private const ITEMS_PER_PAGE = 10;

    public function __construct()
    {
        parent::__construct();

        // Initialize Google API client
        $client = new Client();
        $client->setApplicationName('Memes Search Engine');
        $client->setDeveloperKey($this->config->google->api_key);
        
        $this->searchService = new CustomSearchAPI($client);
    }

    /**
     * Display progress information
     */
    private function displayProgress(string $query, string $language, int $page, int $remainingRequests): void
    {
        $dailyLimit = $this::DAILY_LIMIT;

        echo "\n=== Crawler Progress ===\n";
        echo "Query: {$query} (Language: {$language}, Page: {$page}/10)\n";
        echo "Remaining API Requests: $remainingRequests/{$dailyLimit}\n";
        echo "Stats:\n";
        echo "- Total Processed: {$this->stats['total_processed']}\n";
        echo "- Successfully Saved: {$this->stats['total_saved']}\n";
        echo "- Duplicates Skipped: {$this->stats['total_skipped']}\n";
        echo "- Errors: {$this->stats['total_errors']}\n";
        echo "=====================\n\n";
    }

    /**
     * Crawl Google Images for memes
     */
    public function crawl(): void
    {
        $searchQueries = [
            'ar' => [
                'ميمز عربي',
                'ميمز مضحك',
                'ميمز مصري',
                'ميمز خليجي',
                'ميمز شعبي',
                'ميمز اردني',
                'ميمز سعودي',
                'ميمز كوميدي',
                'ميمز شغل',
                'ميمز عمل'
            ]
        ];

        // Get today's request count
        $today = date('Y-m-d');
        $requestCount = CrawlerState::count([
            'conditions' => 'DATE(created_at) = :date:',
            'bind' => ['date' => $today]
        ]);

        // Calculate remaining requests for today
        $remainingRequests = self::DAILY_LIMIT - $requestCount;
        if ($remainingRequests <= 0) {
            echo "Daily limit reached. Try again tomorrow.\n";
            return;
        }

        echo "Starting crawler with {$remainingRequests} requests available...\n\n";

        foreach ($searchQueries as $language => $queries) {
            foreach ($queries as $query) {
                // Check if we have a state for this query
                $state = CrawlerState::findFirst([
                    'conditions' => 'language = :language: AND query = :query:',
                    'bind' => ['language' => $language, 'query' => $query]
                ]);

                if (!$state) {
                    $state = new CrawlerState();
                    $state->language = $language;
                    $state->query = $query;
                    $state->last_page = 0;
                    $state->completed = 0;
                    $state->save();
                }

                // Calculate how many pages we can fetch with remaining requests
                $pagesToFetch = min(
                    floor($remainingRequests / self::ITEMS_PER_PAGE),
                    10 - $state->last_page // Google only allows up to 10 pages
                );

                if ($pagesToFetch <= 0) {
                    echo "No more requests available for today.\n";
                    continue;
                }

                for ($page = $state->last_page + 1; $page <= $state->last_page + $pagesToFetch; $page++) {
                    try {
                        $this->displayProgress($query, $language, $page, $remainingRequests);
                        $this->searchAndSave($query, $language, $page);
                        
                        $state->last_page = $page;
                        $state->save();
                        
                        // Update remaining requests
                        $remainingRequests -= self::ITEMS_PER_PAGE;
                        
                        // Sleep to respect rate limits
                        sleep(2);
                    } catch (\Exception $e) {
                        $this->stats['total_errors']++;
                        error_log("Error crawling page {$page} for query '{$query}': " . $e->getMessage());
                        continue;
                    }
                }

                // Mark as completed if we've reached page 10
                if ($state->last_page >= 10) {
                    $state->completed = 1;
                    $state->save();
                }

                if ($remainingRequests <= 0) {
                    echo "\nDaily limit reached. Crawler completed.\n";
                    echo "Final Stats:\n";
                    echo "- Total Processed: {$this->stats['total_processed']}\n";
                    echo "- Successfully Saved: {$this->stats['total_saved']}\n";
                    echo "- Duplicates Skipped: {$this->stats['total_skipped']}\n";
                    echo "- Errors: {$this->stats['total_errors']}\n";
                    return;
                }
            }
        }
    }

    /**
     * Search Google Images and save results
     *
     * @param string $query Search query
     * @param string $language Language of the query
     * @param int $page Page number
     */
    private function searchAndSave(string $query, string $language, int $page = 1): void
    {
        try {
            $optParams = [
                'cx' => $this->config->google->cx,
                'searchType' => 'image',
                'num' => self::ITEMS_PER_PAGE,
                'safe' => 'active',
                'q' => $query,
                'lr' => $language === 'ar' ? 'lang_ar' : 'lang_en',
                'gl' => $language === 'ar' ? 'JO' : 'US',
                'start' => ($page - 1) * self::ITEMS_PER_PAGE + 1,
                'filter' => 1
            ];

            $results = $this->searchService->cse->listCse($optParams);

            if ($results->getItems()) {
                foreach ($results->getItems() as $item) {
                    try {
                        $this->stats['total_processed']++;
                        
                        // Download image to check hash
                        $response = $this->httpClient->get($item->getLink());
                        $imageContent = $response->getBody()->getContents();
                        $imageHash = md5($imageContent);

                        // Extract tags from title and snippet
                        $tags = array_unique(array_merge(
                            $this->extractTags($item->getTitle(), $language),
                            $this->extractTags($item->getSnippet(), $language)
                        ));

                        // Save meme with extracted tags and hash
                        if ($this->saveMeme($item->getLink(), $tags, $language, $imageContent, $imageHash)) {
                            echo "✓ Successfully saved meme from URL: {$item->getLink()}\n";
                            $this->stats['total_saved']++;
                        }
                    } catch (\Exception $e) {
                        $this->stats['total_errors']++;
                        error_log("Error saving meme from '{$item->getLink()}': " . $e->getMessage());
                        continue;
                    }
                }
            }
        } catch (\Exception $e) {
            error_log("Error crawling Google Images for query '{$query}' page {$page}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Extract tags from text with language-specific stop words
     *
     * @param string $text Text to extract tags from
     * @param string $language Language of the text
     * @return array Array of tags with confidence scores
     */
    private function extractTags(string $text, string $language): array
    {
        // Remove special characters and split into words
        $words = preg_split('/[\s,]+/', preg_replace('/[^\p{L}\s,]/u', '', $text));
        
        $tags = [];
        $stopWords = $this->stopWords[$language] ?? $this->stopWords['en'];
        
        foreach ($words as $word) {
            $word = trim($word);
            if (strlen($word) > 2 && !in_array(strtolower($word), $stopWords)) {
                // Assign higher confidence to longer words
                $confidence = min(0.8, 0.5 + (strlen($word) / 20));
                $tags[$word] = $confidence;
            }
        }
        
        return $tags;
    }
}
