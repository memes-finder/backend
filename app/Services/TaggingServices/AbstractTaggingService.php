<?php

namespace App\Services\TaggingServices;

abstract class AbstractTaggingService implements TaggingServiceInterface
{
    protected const MAX_TAGS = 6;

    /**
     * Validate and clean tags
     * @param array $tags Array of tags
     * @return array Cleaned tags
     */
    protected function validateTags(array $tags): array
    {
        $cleanedTags = array_filter(array_map(function ($tag) {
            // Remove hashtags, special characters, and trim
            $tag = trim(str_replace('#', '', $tag));
            return trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $tag));
        }, $tags));

        // Limit to MAX_TAGS
        return array_slice($cleanedTags, 0, static::MAX_TAGS);
    }
}
