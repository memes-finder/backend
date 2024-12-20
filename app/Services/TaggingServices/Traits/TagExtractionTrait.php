<?php

namespace App\Services\TaggingServices\Traits;

trait TagExtractionTrait
{
    protected array $stopWords = [
        'en' => ['the', 'and', 'for', 'that', 'this', 'with', 'was', 'are', 'what', 'when', 'where', 'who', 'how'],
        'ar' => ['من', 'في', 'على', 'الى', 'هذا', 'هذه', 'كان', 'كانت', 'مع', 'عن', 'لكن', 'ثم', 'او', 'أو']
    ];

    /**
     * Extract tags from text with language-specific stop words
     * @param string $text Text to extract tags from
     * @param string $language Language of the text
     * @return array Array of tags
     */
    protected function extractTags(string $text, string $language = 'en'): array
    {
        // Convert text to lowercase and split into words
        $words = preg_split('/\s+/', mb_strtolower(trim($text)));
        
        // Filter out stop words and empty strings
        $filteredWords = array_filter($words, function($word) use ($language) {
            return !empty($word) && 
                   !in_array($word, $this->stopWords[$language] ?? []) &&
                   mb_strlen($word) > 1;
        });

        // Remove special characters and numbers from each word
        $cleanedWords = array_map(function($word) {
            return trim(preg_replace('/[^\p{L}\s]/u', '', $word));
        }, $filteredWords);

        // Remove empty strings and duplicates
        return array_values(array_unique(array_filter($cleanedWords)));
    }

    /**
     * Remove any special character and keep spaces only
     *
     * @param $string
     * @return string
     */
    protected function filterTag($string): string
    {
        // Normalize spaces and remove duplicates
        $normalizedString = preg_replace('/\s+/u', ' ', $string);
        return trim($normalizedString);
    }
}
