<?php

namespace App\Services\TaggingServices;

interface TaggingServiceInterface
{
    /**
     * Generate tags for an image in both English and Arabic
     * @param string $imageUrl URL of the image
     * @return array Array with 'en' and 'ar' tags
     */
    public function generateTags(string $imageUrl): array;

    /**
     * Get the origin identifier for this service
     * @return string
     */
    public function getOrigin(): string;
}
