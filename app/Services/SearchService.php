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
                ->addTextField('tag', 1.0, true)  // Combined tags field
                ->addTextField('tag_normalized', 1.0, true)  // Combined normalized tags
                ->addTagField('language', true)
                ->addTagField('meme_type', true)
                ->addNumericField('meme_id')
                ->addNumericField('confidence')  // Add confidence field for sorting
                ->create();

            return true;
        } catch (\Exception $e) {
            $this->logger->error("Error creating index: " . $e->getMessage());
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
            $this->logger->error("Error ensuring index: " . $e->getMessage());
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
     * Normalize language code to database format (english, arabic)
     */
    private function normalizeLanguage(string $language): string
    {
        // Convert from API format
        if ($language === 'ar') {
            return 'arabic';
        }
        if ($language === 'en') {
            return 'english';
        }

        // Convert from database format
        if (in_array($language, ['arabic', 'english'])) {
            return $language;
        }

        // Default to English for unsupported languages
        $this->logger->warning("Unsupported language code: {$language}, defaulting to 'english'");
        return 'english';
    }

    /**
     * Index a meme's tags in RediSearch
     * 
     * @param bool $replace If true, replace existing document instead of adding new one
     */
    public function indexTags(int $memeId, array $tags, string $language, string $type, bool $replace = false, float $confidence = 1.0): bool
    {
        try {
            $this->ensureIndex();

            // Normalize language
            $language = $this->normalizeLanguage($language);

            $normalizedTags = [];
            $originalTags = [];

            foreach ($tags as $tag) {
                // For Arabic tags, store both original and normalized versions
                if (preg_match('/\p{Arabic}/u', $tag)) {
                    $normalizedTags[] = $this->normalizeArabicText($tag);
                } else {
                    $normalizedTags[] = $this->normalizeTag($tag);
                }
                $originalTags[] = $tag;
            }

            $documentId = sprintf("meme:%d", $memeId);
            $document = new DocumentMapper($documentId);
            $document = $document->makeDocument($memeId, $originalTags, $normalizedTags, $language, $type, $confidence);

            $existingDocument = $this->di->getCache()['instance']->hgetall($documentId);
            if (!empty($existingDocument)) {
                $replace = true;
            }

            return $replace ? $this->redisIndexing->replace($document) : $this->redisIndexing->add($document);
        } catch (\Exception $e) {
            $this->logger->error(
                'Indexing failed: ' . $e->getMessage(),
                ['exception' => $e, 'memeId' => $memeId, 'tags' => $tags]
            );
            throw $e;
        }
    }

    /**
     * Search memes by query text and tags
     */
    public function search(?string $query, ?array $tags = [], string $language = null, int $limit = 10, int $offset = 0, ?string $type = null): array
    {
        try {
            $this->ensureIndex();

            $searchParts = [
                'tags' => []
            ];

            // Add text query if provided
            if (!empty($query)) {
                // For Arabic text, apply normalization
                if (preg_match('/\p{Arabic}/u', $query)) {
                    $normalizedQuery = $this->normalizeArabicText($query);
                } else {
                    $normalizedQuery = $this->normalizeTag($query);
                }

                // Search in both original and normalized tags
                $searchParts['tags'] = [
                    $this->escapeQuery($query),
                    $this->escapeQuery($normalizedQuery)
                ];
            }

            // Add tag search if provided
            if (!empty($tags)) {
                foreach ($tags as $tag) {
                    if (preg_match('/\p{Arabic}/u', $tag)) {
                        $normalizedTag = $this->normalizeArabicText($tag);
                    } else {
                        $normalizedTag = $this->normalizeTag($tag);
                    }

                    $searchParts['tags'] = array_merge($searchParts['tags'], [
                        $this->escapeQuery($tag),
                        $this->escapeQuery($normalizedTag)
                    ]);
                }
            }

            $searchParts['tags'] = array_unique($searchParts['tags']);

            $searchParts = [
                'query' => '('. implode(' | ', array_map(function ($tag) {
                                return sprintf('@tag:"%s" | @tag_normalized:"%s"', $tag, $tag);
                        }, $searchParts['tags'])) . ')'
            ];

            // Add language filter if provided
//            if (0 && $language) {
//                $language = $this->normalizeLanguage($language);
//                $searchParts['language'] = "@language:{" . $language . "}";
//            }

            // Add type filter if provided
            if ($type) {
                $searchParts['type'] = "@meme_type:{" . $type . "}";
            }

            // Build final query
            $searchQuery = implode(' ', $searchParts);
            if (empty($searchQuery)) {
                return [];
            }

            $this->logger->debug("Search query: " . $searchQuery);

            // Execute search with sorting by confidence
            $results = $this->redisIndexing
                ->limit($offset, $limit)
                ->sortBy('confidence', 'DESC') // Sort by confidence in descending order
                ->search($searchQuery, true);

            // Extract meme IDs from results
            $memeIds = [];
            foreach ($results->getDocuments() as $document) {
                if (isset($document['meme_id'])) {
                    $memeIds[] = (int) $document['meme_id'];
                }
            }

            return array_unique($memeIds);

        } catch (\Exception $e) {
            $this->logger->error(
                'Search failed: ' . $e->getMessage(),
                ['exception' => $e, 'query' => $query, 'tags' => $tags]
            );
            return [];
        }
    }

    /**
     * Escape special characters in search query
     */
    private function escapeQuery(string $query): string
    {
        return str_replace(
            ['\\', '"', '@', '~', '&', '|', '<', '>', '{', '}', '[', ']', '(', ')', '^', '*', '?', ':', '=', '-', '+'],
            ['\\\\', '\\"', '\\@', '\\~', '\\&', '\\|', '\\<', '\\>', '\\{', '\\}', '\\[', '\\]', '\\(', '\\)', '\\^', '\\*', '\\?', '\\:', '\\=', '\\-', '\\+'],
            $query
        );
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

    /**
     * Add a single tag to an existing meme document
     */
    public function addTag(int $memeId, string $tag, string $language, float $confidence = 1.0): bool
    {
        try {
            $this->ensureIndex();
            
            // Normalize language
            $language = $this->normalizeLanguage($language);

            // Get existing document
            $documentId = sprintf("meme:%d", $memeId);
            $existingDoc = (object) $this->di->getCache()['instance']->hgetall($documentId);
            
            if (!$existingDoc) {
                // If no document exists, create new one
                return $this->indexTags($memeId, [$tag], $language, $this->getMemeType($memeId), false, $confidence);
            }

            // Get existing tags
            $existingTags = array_filter(explode(' | ', $existingDoc->tag ?? ''));
            $existingNormalizedTags = array_filter(explode(' | ', $existingDoc->tag_normalized ?? ''));

            // Add new tag
            if (preg_match('/\p{Arabic}/u', $tag)) {
                $normalizedTag = $this->normalizeArabicText($tag);
            } else {
                $normalizedTag = $this->normalizeTag($tag);
            }

            // Only add if tag doesn't exist
            if (!in_array($tag, $existingTags)) {
                $existingTags[] = $tag;
                $existingNormalizedTags[] = $normalizedTag;
            }

            // Create updated document
            $document = new DocumentMapper($documentId);
            $document = $document->makeDocument(
                $memeId,
                $existingTags,
                $existingNormalizedTags,
                $language,
                $existingDoc->meme_type,
                max($confidence, (float)($existingDoc->confidence ?? 1.0)) // Keep highest confidence
            );

            // Replace the document
            return $this->redisIndexing->replace($document);

        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to add tag: ' . $e->getMessage(),
                ['exception' => $e, 'memeId' => $memeId, 'tag' => $tag]
            );
            throw $e;
        }
    }

    /**
     * Get meme type from database
     */
    private function getMemeType(int $memeId): string
    {
        $meme = \App\Models\Memes::findFirst($memeId);
        if (!$meme) {
            throw new \RuntimeException("Meme not found: {$memeId}");
        }
        return $meme->meme_type;
    }
}