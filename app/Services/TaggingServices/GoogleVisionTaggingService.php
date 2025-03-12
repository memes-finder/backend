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

            // Create feature objects - using all relevant detection types
            $features = [
                (new Feature())
                    ->setType(Type::WEB_DETECTION)
                    ->setMaxResults(50),
                (new Feature())
                    ->setType(Type::TEXT_DETECTION)
                    ->setMaxResults(50),
                (new Feature())
                    ->setType(Type::LABEL_DETECTION)
                    ->setMaxResults(50),
                (new Feature())
                    ->setType(Type::FACE_DETECTION)
                    ->setMaxResults(10),
                (new Feature())
                    ->setType(Type::IMAGE_PROPERTIES)
                    ->setMaxResults(50)
            ];

            // Perform the API request
            $response = $this->visionClient->annotateImage($image, $features);
            
            $tags = [];
            $scores = [];  // Track confidence scores for each tag
            
            // Process web detection results (higher priority)
            $webDetection = $response->getWebDetection();
            if ($webDetection) {
                // Best guess labels are usually very accurate
                foreach ($webDetection->getBestGuessLabels() as $label) {
                    $extracted = $this->extractTags($label->getLabel());
                    foreach ($extracted as $tag) {
                        $tags[] = $tag;
                        $scores[$tag] = 0.9;  // High confidence for best guesses
                    }
                }

                // Web entities with high confidence
                foreach ($webDetection->getWebEntities() as $entity) {
                    if ($entity->getScore() >= 0.7) {  // Increased threshold
                        $extracted = $this->extractTags($entity->getDescription());
                        foreach ($extracted as $tag) {
                            $tags[] = $tag;
                            $scores[$tag] = $entity->getScore();
                        }
                    }
                }
            }

            // Process text annotations
            $textAnnotations = $response->getTextAnnotations();
            if (!empty($textAnnotations[0])) {
                $fullText = $textAnnotations[0]->getDescription();
                $fullText = $this->filterTag($fullText);
                
                // Check if text contains Arabic
                $hasArabic = preg_match('/\p{Arabic}/u', $fullText);
                
                // Extract text-based tags
                $textTags = $this->extractTags($fullText, $hasArabic ? 'ar' : 'en');
                foreach ($textTags as $tag) {
                    $tags[] = $tag;
                    $scores[$tag] = 0.85;  // High confidence for detected text
                }
            }

            // Process label annotations
            foreach ($response->getLabelAnnotations() as $label) {
                if ($label->getScore() >= 0.8) {  // High threshold for labels
                    $extracted = $this->extractTags($label->getDescription());
                    foreach ($extracted as $tag) {
                        $tags[] = $tag;
                        $scores[$tag] = $label->getScore();
                    }
                }
            }

            // Process face annotations for emotion tags
            $faceAnnotations = $response->getFaceAnnotations();
            if (!empty($faceAnnotations)) {
                foreach ($faceAnnotations as $face) {
                    // Add emotion-related tags based on likelihood
                    $emotions = [
                        'joy' => $face->getJoyLikelihood(),
                        'sorrow' => $face->getSorrowLikelihood(),
                        'anger' => $face->getAngerLikelihood(),
                        'surprise' => $face->getSurpriseLikelihood()
                    ];

                    foreach ($emotions as $emotion => $likelihood) {
                        if ($likelihood >= 3) {  // LIKELY or VERY_LIKELY
                            $tags[] = $emotion;
                            $scores[$emotion] = 0.8;
                            
                            // Add Arabic equivalents
                            $arEmotions = [
                                'joy' => 'سعادة',
                                'sorrow' => 'حزن',
                                'anger' => 'غضب',
                                'surprise' => 'دهشة'
                            ];
                            if (isset($arEmotions[$emotion])) {
                                $tags[] = $arEmotions[$emotion];
                                $scores[$arEmotions[$emotion]] = 0.8;
                            }
                        }
                    }
                }
            }

            // Clean and validate English tags
            $enTags = [];
            $arTags = [];

            // Sort tags by confidence score
            arsort($scores);
            foreach ($scores as $tag => $score) {
                if (preg_match('/\p{Arabic}/u', $tag)) {
                    $arTags[] = $tag;
                } else {
                    $enTags[] = $tag;
                }
            }

            // Translate remaining English tags to Arabic
            foreach ($enTags as $tag) {
                try {
                    $result = $this->translateClient->translate($tag, [
                        'target' => 'ar',
                        'source' => 'en'
                    ]);
                    
                    // Only add translation if it's not already present
                    $translatedTag = $result['text'];
                    if (!in_array($translatedTag, $arTags)) {
                        $arTags[] = $translatedTag;
                    }
                } catch (Exception $e) {
                    error_log("Error translating tag '{$tag}': " . $e->getMessage());
                }
            }

            // Add meme-related tags if relevant
            if (str_contains(strtolower(implode(' ', $enTags)), 'meme')) {
                $enTags[] = 'meme';
                $arTags[] = 'ميم';
            }

            return [
                'en' => array_slice($this->validateTags($enTags), 0, 20),  // Limit to top 20 tags
                'ar' => array_slice($this->validateTags($arTags), 0, 20)
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
