<?php

namespace App\Services\TaggingServices;

use App\Models\MemeTags;
use Google\Cloud\Vision\V1\ImageAnnotatorClient;
use Google\Cloud\Vision\V1\Feature\Type;
use Google\Cloud\Vision\V1\Image;
use Google\Cloud\Vision\V1\Feature;
use Google\Cloud\Vision\V1\ImageSource;
use Google\Cloud\Translate\V2\TranslateClient;
use App\Services\TaggingServices\Traits\TagExtractionTrait;
use Phalcon\Di;
use Exception;

class GoogleVisionTaggingService extends AbstractTaggingService
{
    use TagExtractionTrait;

    private ImageAnnotatorClient $visionClient;
    private TranslateClient $translateClient;

    public function __construct()
    {
        $config = Di::getDefault()->get('config')->google;
        
        // Configure Vision API with API key
        $this->visionClient = new ImageAnnotatorClient([
            'credentials' => $config->service_account
        ]);

        // Configure Translate API with same API key
        $this->translateClient = new TranslateClient([
            'key' => $config->api_key
        ]);
    }

    /**
     * Get the origin identifier for this service
     * @return string
     */
    public function getOrigin(): string
    {
        return MemeTags::ORIGIN_GOOGLE_VISION;
    }

    /**
     * Generate tags for an image in both English and Arabic using Google Vision API
     * @param string $imageUrl URL of the image
     * @return array Array with 'en' and 'ar' tags
     */
    public function generateTags(string $imageUrl): array
    {
        try {
            $imageSource = new ImageSource();
            $imageSource->setImageUri($imageUrl);

            // Create image object
            $image = (new Image())
                ->setSource($imageSource);

            // Create feature objects
            $webDetectionFeature = (new Feature())
                ->setType(Type::WEB_DETECTION)
                ->setMaxResults(50);

            $textDetectionFeature = (new Feature())
                ->setType(Type::TEXT_DETECTION)
                ->setMaxResults(50);

            $features = [$textDetectionFeature, $webDetectionFeature];

            // Perform the API request
            $response = $this->visionClient->annotateImage($image, $features);
            
            $tags = [];
            
            // Add web entities
            $webDetection = $response->getWebDetection();
            if ($webDetection) {
                foreach ($webDetection->getBestGuessLabels() as $label) {
                    $tags = array_merge($tags, $this->extractTags($label->getLabel()));
                }
                foreach ($webDetection->getWebEntities() as $entity) {
                    if ($entity->getScore() >= 0.5) {
                        $tags = array_merge($tags, $this->extractTags($entity->getDescription()));
                    }
                }
            }

            // Add text content
            $textAnnotations = $response->getTextAnnotations();
            if (!empty($textAnnotations[0])) {
                $fullText = $textAnnotations[0]->getDescription();
                $fullText = $this->filterTag($fullText);
                $tags = array_merge($tags, $this->extractTags($fullText));
            }

            // Clean and validate English tags
            $enTags = array_unique(array_merge($tags, $this->validateTags(array_unique($tags))));

            // Translate to Arabic
            $arTags = [];
            foreach ($enTags as $index => $tag) {
                if (!preg_match('/\p{Arabic}/u', $tag)) {
                    try {
                        $result = $this->translateClient->translate($tag, [
                            'target' => 'ar'
                        ]);
                        $arTags = array_merge($arTags, $this->extractTags($result['text'], 'ar'));
                    } catch (Exception $e) {
                        error_log("Error translating tag '{$tag}': " . $e->getMessage());
                    }
                } else {
                    unset($enTags[$index]);
                    $arTags[] = $tag;
                }
            }

            return [
                'en' => $enTags,
                'ar' => $this->validateTags($arTags)
            ];
        } catch (Exception $e) {
            error_log("Error generating tags with Google Vision for image {$imageUrl}: " . $e->getMessage());
            return ['en' => [], 'ar' => []];
        }
    }

    public function __destruct()
    {
        $this->visionClient->close();
    }
}
