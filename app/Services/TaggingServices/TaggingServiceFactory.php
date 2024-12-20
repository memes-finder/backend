<?php

namespace App\Services\TaggingServices;

use Exception;

class TaggingServiceFactory
{
    public const SERVICE_OPENAI = 'openai';
    public const SERVICE_GOOGLE_VISION = 'google_vision';

    /**
     * Create a tagging service instance
     * @param string $type Type of service (openai or google_vision)
     * @return TaggingServiceInterface
     * @throws Exception
     */
    public static function create(string $type): TaggingServiceInterface
    {
        switch ($type) {
            case self::SERVICE_OPENAI:
                return new OpenAITaggingService();
            case self::SERVICE_GOOGLE_VISION:
                return new GoogleVisionTaggingService();
            default:
                throw new Exception("Unknown tagging service type: {$type}");
        }
    }
}
