<?php

namespace App\Services\TaggingServices;

use Orhanerday\OpenAi\OpenAi;
use App\Services\TaggingServices\Traits\TagExtractionTrait;
use Phalcon\Di;
use Exception;

class OpenAITaggingService extends AbstractTaggingService
{
    use TagExtractionTrait;

    private OpenAI $openaiClient;
    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = Di::getDefault()->get('config')->openai->api_key;
        $this->openaiClient = new OpenAI($this->apiKey);
    }

    /**
     * Generate tags for an image in both English and Arabic using OpenAI
     * @param string $imageUrl URL of the image
     * @return array Array with 'en' and 'ar' tags
     */
    public function generateTags(string $imageUrl): array
    {
        try {
            $response = $this->openaiClient->chat([
                'model' => 'gpt-4-turbo',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => 'Analyze this meme image to extract concise and relevant keywords each in English and Arabic,
                                            describing its visual content, text, context, emotions and humor,
                                             while outputting the results in JSON format {"en": [], "ar": []}. 
                                             If the image contains celebrities, include their names in the tags and put them at first.'
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

            // Process tags through our extraction trait
            $enTags = [];
            foreach ($tags['en'] ?? [] as $tag) {
                $enTags = array_merge($enTags, $this->extractTags($tag));
            }

            $arTags = [];
            foreach ($tags['ar'] ?? [] as $tag) {
                $arTags = array_merge($arTags, $this->extractTags($tag, 'ar'));
            }

            return [
                'en' => $this->validateTags($enTags),
                'ar' => $this->validateTags($arTags)
            ];
        } catch (Exception $e) {
            error_log("Error generating tags with OpenAI for image {$imageUrl}: " . $e->getMessage());
            return ['en' => [], 'ar' => []];
        }
    }
}
