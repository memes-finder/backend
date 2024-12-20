<?php

namespace App\Services\TaggingServices;

abstract class AbstractTaggingService implements TaggingServiceInterface
{

    /**
     * Validate and clean tags
     * @param array $tags Array of tags
     * @return array Cleaned tags
     */
    protected function validateTags(array $tags): array
    {
        return array_filter(array_map(function ($tag) {
            // Remove hashtags, special characters, and trim
            $tag = trim(str_replace('#', '', $tag));
            return trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $tag));
        }, $tags));
    }
}
