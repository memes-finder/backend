<?php

namespace App\Services;

use Orhanerday\OpenAi\OpenAi;
use Phalcon\Di;
use Exception;

class AITaggingService
{
    private OpenAI $openaiClient;
    private string $apiKey;
    private const MAX_TAGS = 6;

    public function __construct()
    {
        $this->apiKey = Di::getDefault()->get('config')->openai->api_key;
        $this->openaiClient = new OpenAI($this->apiKey);
    }

    /**
     * Generate tags for an image in both English and Arabic
     * @param string $imageUrl URL of the image
     * @return array Array with 'en' and 'ar' tags
     */
    public function generateTags(string $imageUrl): array
    {
        try {
            $response = $this->openaiClient->chat([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => 'Analyze a meme image to extract concise and relevant keywords each in English and Arabic, describing its visual content, text, context, emotions and humor, while outputting the results in JSON format {"en": [], "ar": []}. If the image contains celebrities, include their names in the tags.'
                            ],
                            [
                                'type' => 'image_url',
                                'image_url' => ['url' => $imageUrl]
                            ]
                        ]
                    ]
                ],
                'max_tokens' => 300
            ]);

            $content = json_decode($response, true)['choices'][0]['message']['content'];
            $cleanedContent = preg_replace('/^```json|```$/m', '', trim($content));
            $tags = json_decode($cleanedContent, true);

            // Validate and ensure we have exactly MAX_TAGS tags for each language
            return [
                'en' => array_slice($this->validateTags($tags['en'] ?? []), 0, self::MAX_TAGS),
                'ar' => array_slice($this->validateTags($tags['ar'] ?? []), 0, self::MAX_TAGS)
            ];
        } catch (Exception $e) {
            // Log the error
            error_log("Error generating tags for image {$imageUrl}: " . $e->getMessage());

            // Return empty arrays if there's an error
            return ['en' => [], 'ar' => []];
        }
    }

    /**
     * Validate and clean tags
     * @param array $tags Array of tags
     * @return array Cleaned tags
     */
    private function validateTags(array $tags): array
    {
        return array_filter(array_map(function ($tag) {
            // Remove hashtags, special characters, and trim
            $tag = trim(str_replace('#', '', $tag));
            return trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $tag));
        }, $tags));
    }
}
