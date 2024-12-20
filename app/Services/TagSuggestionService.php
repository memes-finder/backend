<?php

namespace App\Services;

use App\Models\MemeTags;
use Phalcon\Di;

class TagSuggestionService
{
    private const MIN_TAG_FREQUENCY = 2;
    private const MAX_SUGGESTIONS = 10;

    /**
     * Get suggested tags based on frequency of usage
     * @param string $language Language code ('en' or 'ar')
     * @return array Array of tags with their usage counts
     */
    public function getSuggestions(string $language): array
    {
        // Convert language code to full name
        $languageName = $language === 'ar' ? 'arabic' : 'english';

        // Get tags with their usage count
        $tags = MemeTags::query()
            ->columns([
                'tag',
                'COUNT(*) as usage_count',
                'AVG(confidence) as avg_confidence'
            ])
            ->where('language = :language:')
            ->groupBy('tag')
            ->having('COUNT(*) >= :min_count:')
            ->orderBy('rand()')
            ->limit(self::MAX_SUGGESTIONS)
            ->bind([
                'language' => $languageName,
                'min_count' => self::MIN_TAG_FREQUENCY
            ])
            ->execute()
            ->toArray();

        // Format the response
        return array_map(function($tag) {
            return [
                'tag' => $tag['tag'],
                'usage_count' => (int)$tag['usage_count'],
                'confidence' => round((float)$tag['avg_confidence'], 2)
            ];
        }, $tags);
    }

    /**
     * Get trending tags (most used in the last week)
     * @param string $language Language code ('en' or 'ar')
     * @param int $limit Number of tags to return
     * @return array Array of trending tags
     */
    public function getTrendingTags(string $language, int $limit = 10): array
    {
        $languageName = $language === 'ar' ? 'arabic' : 'english';
        $lastWeek = date('Y-m-d H:i:s', strtotime('-1 week'));

        $tags = MemeTags::query()
            ->columns([
                'tag',
                'COUNT(*) as usage_count'
            ])
            ->where('language = :language:', ['language' => $languageName])
            ->andWhere('created_at >= :last_week:', ['last_week' => $lastWeek])
            ->groupBy('tag')
            ->orderBy('rand()')
            ->limit($limit)
            ->execute()
            ->toArray();

        return array_map(function($tag) {
            return [
                'tag' => $tag['tag'],
                'usage_count' => (int)$tag['usage_count']
            ];
        }, $tags);
    }
}
