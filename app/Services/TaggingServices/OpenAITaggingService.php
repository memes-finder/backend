<?php

namespace App\Services\TaggingServices;

use App\Models\MemeTags;
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
     * Get the origin identifier for this service
     * @return string
     */
    public function getOrigin(): string
    {
        return MemeTags::ORIGIN_OPENAI;
    }

    /**
     * Convert image to base64
     * @throws \RuntimeException
     */
    private function getImageAsBase64(string $imageUrl): string
    {
        // Get image content
        $imageContent = @file_get_contents($imageUrl);
        if ($imageContent === false) {
            throw new \RuntimeException("Failed to download image from URL");
        }

        // Get mime type
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($imageContent);
        if (!$mimeType || !str_starts_with($mimeType, 'image/')) {
            throw new \RuntimeException("Invalid image type: {$mimeType}");
        }

        // Encode as base64
        return 'data:' . $mimeType . ';base64,' . base64_encode($imageContent);
    }

    /**
     * Generate tags for an image in both English and Arabic using OpenAI
     * @param string $imageUrl URL of the image
     * @return array Array with 'en' and 'ar' tags
     */
    public function generateTags(string $imageUrl): array
    {
        try {
            // Validate image URL
            if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                throw new \InvalidArgumentException("Invalid image URL provided");
            }

            // Convert image to base64
            $base64Image = $this->getImageAsBase64($imageUrl);

            // Try to verify image exists and is accessible
            $headers = get_headers($imageUrl, 1);
            if (!$headers || strpos($headers[0], '200') === false) {
                throw new \RuntimeException("Image URL is not accessible");
            }

            $response = $this->openaiClient->chat([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => 'Analyze this meme image to extract concise and relevant keywords each in English and Arabic,
                                            describing its visual content, text, context, emotions and humor,
                                             while outputting the results in JSON format {"en": [], "ar": []}.'
                            ],
                            [
                                'type' => 'image_url',
                                'image_url' => [
                                    'url' => $base64Image
                                ]
                            ]
                        ]
                    ]
                ],
                'max_tokens' => 300
            ]);

            if (empty($response)) {
                throw new \RuntimeException("Empty response received from OpenAI API");
            }

            $decodedResponse = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException("Invalid JSON response: " . json_last_error_msg());
            }

            if (!isset($decodedResponse['choices'][0]['message']['content'])) {
                throw new \RuntimeException("Unexpected response format from OpenAI API");
            }

            $content = $decodedResponse['choices'][0]['message']['content'];
            $cleanedContent = preg_replace('/^```json|```$/m', '', trim($content));
            
            $tags = json_decode($cleanedContent, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException("Invalid JSON in content: " . json_last_error_msg());
            }

            if (!isset($tags['en']) || !isset($tags['ar'])) {
                throw new \RuntimeException("Missing required language tags in response");
            }

            // Process tags through our extraction trait
            $enTags = [];
            foreach ($tags['en'] ?? [] as $tag) {
                $enTags = array_merge($enTags, $this->extractTags($tag));
            }

            $arTags = [];
            foreach ($tags['ar'] ?? [] as $tag) {
                $arTags = array_merge($arTags, $this->extractTags($tag, 'ar'));
            }

            $result = [
                'en' => $this->validateTags($enTags),
                'ar' => $this->validateTags($arTags)
            ];

            // Log success
            $this->getDI()->get('logger')->info(
                "Successfully generated tags for image",
                [
                    'url' => $imageUrl,
                    'tag_count' => [
                        'en' => count($result['en']),
                        'ar' => count($result['ar'])
                    ]
                ]
            );

            return $result;

        } catch (\InvalidArgumentException $e) {
            // Log validation errors
            $this->getDI()->get('logger')->warning(
                "Validation error in OpenAI tagging",
                [
                    'url' => $imageUrl,
                    'error' => $e->getMessage()
                ]
            );
            return ['en' => [], 'ar' => []];

        } catch (\RuntimeException $e) {
            // Log runtime errors
            $this->getDI()->get('logger')->error(
                "Runtime error in OpenAI tagging",
                [
                    'url' => $imageUrl,
                    'error' => $e->getMessage()
                ]
            );
            return ['en' => [], 'ar' => []];

        } catch (\Exception $e) {
            // Log unexpected errors
            $this->getDI()->get('logger')->critical(
                "Unexpected error in OpenAI tagging",
                [
                    'url' => $imageUrl,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]
            );
            return ['en' => [], 'ar' => []];
        }
    }
}
