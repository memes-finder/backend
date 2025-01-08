<?php

namespace App\Services;

use App\Enums\CacheIndexesEnum;
use App\Models\Redis\DocumentMapper;
use Ehann\RediSearch\Index;
use Ehann\RediSearch\Suggestion;
use Phalcon\Di\Injectable;
use App\Models\Memes;
use Phalcon\Logger;

class SearchService extends Injectable
{
    const SUGGESTION_KEY_EN = 'memes_suggestions_en';
    const SUGGESTION_KEY_AR = 'memes_suggestions_ar';

    public string $indexName = CacheIndexesEnum::MEME_INDEX_NAME;

    /** @var Index */
    private $redisIndexing;

    /** @var Suggestion */
    private $redisSuggesting;

    /** @var \Redis */
    private $redis;

    /**
     * @var Logger
     */
    protected $logger;

    public function __construct()
    {
        $this->logger = $this->getDI()->getLogger();
        $this->redis = $this->getDI()->getCache()['adapter']->redis;
        $this->redisIndexing = $this->getDI()->get('redisIndex');
        $this->redisSuggesting = $this->getDI()->get('redisSuggest');
    }

    /**
     * Create or recreate the search index with stemming support
     */
    private function createIndex()
    {
        try {
            // Drop existing index if it exists
            try {
                $this->redisIndexing->drop();
            } catch (\Exception $e) {
                // Index doesn't exist, which is fine
            }

            // Create new index with stemming
            $this->redisIndexing
                ->addTextField('tag', 1.0, true)
                ->addTextField('tag_normalized', 1.0, true)
                ->addTagField('language', true)
                ->addTagField('meme_type', true)
                ->addNumericField('meme_id')
                ->addNumericField('confidence')
                ->addTextField('created_at')
                ->create();

            return true;
        } catch (\Exception $e) {
            error_log("Error creating index: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Check if index exists and create if it doesn't
     */
    private function ensureIndex(): bool
    {
        try {
            if (!$this->indexExists()) {
                return $this->createIndex();
            }
            return true;
        } catch (\Exception $e) {
            error_log("Error ensuring index: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if RedisSearch index exists
     *
     * @return bool
     */
    private function indexExists(): bool
    {
        try {
            $this->redisIndexing->info();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Normalize Arabic text by:
     * 1. Removing diacritics
     * 2. Normalizing alef variations
     * 3. Removing prefix/suffix for better matching
     */
    private function normalizeArabicText(string $text): string
    {
        // Remove diacritics (tashkeel)
        $text = preg_replace('/[\x{064B}-\x{065B}]/u', '', $text);
        
        // Normalize alef variations to plain alef
        $text = preg_replace('/[\x{0622}\x{0623}\x{0625}]/u', 'ا', $text);
        
        // Remove common prefixes (ال, لل, بال, etc)
        $text = preg_replace('/^(ال|لل|بال|كال|فال|وال|لل)/u', '', $text);
        
        // Remove common suffixes (ين, ون, ات, etc)
        $text = preg_replace('/(ين|ون|ات|ية|يه|تين|ان|تان|ه|ها|هم|هن|نا|كم|تم)$/u', '', $text);

        // Replace dotted characters with simple form
        $text = preg_replace(['/[أإآا]/u', '/[ة]/u', '/[ى]/u', '/[ؤ]/u'], ['ا', 'ه', 'ي', 'و'], $text);
        
        return trim($text);
    }

    /**
     * Index a tag in RediSearch
     */
    public function indexTag(int $memeId, string $tag, string $language, float $confidence, string $createdAt, string $type): bool
    {
        try {
            $this->ensureIndex();

            // For Arabic tags, store both original and normalized versions
            if (preg_match('/\p{Arabic}/u', $tag)) {
                $normalizedTag = $this->normalizeArabicText($tag);
            } else {
                $normalizedTag = $this->normalizeTag($tag);
            }

            $documentId = sprintf("meme:$memeId:%s", uniqid('', true));
            $document = new DocumentMapper($documentId);
            $document = $document->makeDocument($memeId, $tag, $normalizedTag, $language, $type);

            return $this->redisIndexing->add($document);
        } catch (\Exception $e) {
            $this->logger->error(
                'Indexing failed: ' . $e->getMessage(),
                ['exception' => $e, 'args' => func_get_args()]
            );
            throw $e;
        }
    }

    /**
     * Search memes by query text and tags
     *
     * @param string|null $query Text query
     * @param array|null $tags Array of tags to search for
     * @param string|null $language
     * @param int $limit
     * @param int $offset
     * @param string|null $type
     * @return array
     */
    public function search(?string $query, ?array $tags = [], string $language = null, int $limit = 10, int $offset = 0, ?string $type = null): array
    {
        try {
            // Ensure index exists with stemming
            $this->ensureIndex();

            $searchParts = [];

            // Add text query if provided
            if (!empty($query)) {
                // For Arabic text, apply normalization
                if (preg_match('/\p{Arabic}/u', $query)) {
                    $normalizedQuery = $this->normalizeArabicText($query);
                } else {
                    $normalizedQuery = $this->normalizeTag($query);
                }

                // Build text search query with multiple variations and stemming
                $searchTerms = [
                    $query,              // Exact match
                    $normalizedQuery . '*' // Normalized prefix match
                ];

                $searchParts[] = '@tag:(' . implode('|', array_map(function($term) {
                    return '~' . addslashes($term);
                }, $searchTerms)) . ') @tag_normalized:(' . $normalizedQuery . ')';
            }

            // Add tag search if provided
            if (!empty($tags)) {
                $tagQueries = [];
                foreach ($tags as $tag) {
                    // Normalize tag based on language
                    if (preg_match('/\p{Arabic}/u', $tag)) {
                        $normalizedTag = $this->normalizeArabicText($tag);
                    } else {
                        $normalizedTag = $this->normalizeTag($tag);
                    }

                    $tagQueries[] = '@tag:(~' . addslashes($tag) . ') | @tag_normalized:(~' . $normalizedTag . ')';
                }
                $searchParts[] = '(' . implode(' | ', $tagQueries) . ')';
            }

            // If no search criteria provided, return empty result
            if (empty($searchParts)) {
                return [];
            }

            // Add type filter if provided
            if ($type) {
                $searchParts[] = "@meme_type:{" . $type . "}";
            }

            // Build final query
            $searchQuery = implode(' ', $searchParts);
            if (empty($searchQuery)) {
                return [];
            }

            // Execute search
            $results = $this->redisIndexing
                ->limit($offset, $limit)
                ->search($searchQuery, true);

            // Extract meme IDs from results
            $memeIds = [];
            foreach ($results->getDocuments() as $document) {
                if (isset($document['meme_id'])) {
                    $memeIds[] = (int) $document['meme_id'];
                }
            }

            if (empty($memeIds)) {
                return [];
            }

            return array_unique($memeIds);
        } catch (\Exception $e) {
            $this->logger->error(
                'Search failed: ' . $e->getMessage(),
                ['exception' => $e, 'query' => $query, 'tags' => $tags, 'language' => $language]
            );
            return [];
        }
    }

    /**
     * Remove a meme and its tags from the search index
     *
     * @param int $memeId
     * @throws \Exception
     */
    public function removeMeme(int $memeId)
    {
        try {
            if (empty($memeId)) {
                throw new \Exception('Missing meme ID');
            }

            // Delete all documents with this meme_id
            $this->redisIndexing->delete("meme:{$memeId}", true);

            // Delete all tag documents for this meme
            $pattern = "tag:{$memeId}:*";
            $keys = $this->redis->keys($pattern);
            if (!empty($keys)) {
                $this->redis->del($keys);
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to remove meme from search index: ' . $e->getMessage(),
                ['exception' => $e, 'memeId' => $memeId]
            );
            throw $e;
        }
    }

    /**
     * Delete a meme tag
     *
     * @param int $memeId
     * @throws \Exception
     */
    public function delete(int $memeId)
    {
        try {
            if (empty($memeId)) {
                throw new \Exception('Missing meme ID');
            }
            $this->redisIndexing->delete("meme:{$memeId}");
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to delete meme: ' . $e->getMessage(),
                ['exception' => $e, 'memeId' => $memeId]
            );
            return;
        }
    }

    /**
     * Get tag suggestions
     *
     * @param string $prefix
     * @param string|null $language
     * @param int $limit
     * @return array
     * @throws \Exception
     */
    public function getSuggestions(string $prefix, ?string $language = null, int $limit = 5)
    {
        try {
            $suggestionKey = $language === 'ar' ? self::SUGGESTION_KEY_AR : self::SUGGESTION_KEY_EN;
            return $this->redisSuggesting
                ->fuzzy()
                ->get($prefix, $limit);
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to get suggestions: ' . $e->getMessage(),
                ['exception' => $e, 'prefix' => $prefix, 'language' => $language]
            );
            return [];
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

    public function getIndexName(): string
    {
        return 'memes_idx';
    }
}