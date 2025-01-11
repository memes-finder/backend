<?php

namespace App\Tasks;

use App\Models\Memes;
use Phalcon\Cli\Task;
use kornrunner\Blurhash\Blurhash;

class GenerateBlurHashTask extends Task
{
    const COMPONENTS_X = 4;
    const COMPONENTS_Y = 3;

    /**
     * Generate blur hashes for all memes that don't have them
     */
    public function mainAction()
    {
        echo "Starting blur hash generation...\n";

        /**
         * Get all memes without blur hash
         * @var Memes[] $memes
         */
        $memes = Memes::find();

        $total = count($memes);
        $processed = 0;
        $errors = 0;

        foreach ($memes as $meme) {
            try {
                $processed++;
                echo "Processing meme {$meme->id} ({$processed}/{$total})... ";

                // For images, generate blur hash for the main file
                if ($meme->meme_type === 'image' && empty($meme->blur_hash)) {
                    $imagePath = $meme->getPublicUrl();
                    $meme->blur_hash = $this->generateBlurHash($imagePath);
                    echo "Generated blur hash for meme ID $meme->id.\n";
                }

                // For videos, generate blur hash for the thumbnail
                if ($meme->meme_type === 'video' && empty($meme->thumbnail_blur_hash)) {
                    $thumbnailPath = $meme->getThumbnailUrl();
                    $meme->blur_hash = $this->generateBlurHash($thumbnailPath);
                    echo "Generated blur hash for meme ID $meme->id.\n";
                }

                // Save the meme
                if (!$meme->save()) {
                    $messages = [];
                    foreach ($meme->getMessages() as $message) {
                        $messages[] = $message->getMessage();
                    }
                    var_dump($messages);
                    $errors++;
                }

                echo "Saved.\n";

            } catch (\Exception $e) {
                echo "Error: {$e->getMessage()}\n";
                $errors++;
            }
        }

        echo "\nCompleted processing {$total} memes.\n";
        echo "Successful: " . ($processed - $errors) . "\n";
        echo "Errors: {$errors}\n";
    }

    /**
     * Generate blur hash for a single image
     * 
     * @param string $imagePath Absolute path to image file
     * @return string Generated blur hash
     * @throws \Exception
     */
    private function generateBlurHash(string $imagePath): string
    {
        // Load image
        $image = imagecreatefromstring(file_get_contents($imagePath));
        if (!$image) {
            throw new \Exception("Failed to load image: {$imagePath}");
        }

        // Get image dimensions
        $width = imagesx($image);
        $height = imagesy($image);

        // Create pixel array
        $pixels = [];
        for ($y = 0; $y < $height; $y++) {
            $row = [];
            for ($x = 0; $x < $width; $x++) {
                $color = imagecolorat($image, $x, $y);
                $row[] = [
                    ($color >> 16) & 0xFF, // Red
                    ($color >> 8) & 0xFF,  // Green
                    $color & 0xFF          // Blue
                ];
            }
            $pixels[] = $row;
        }

        // Free memory
        imagedestroy($image);

        // Generate blur hash
        return Blurhash::encode($pixels, self::COMPONENTS_X, self::COMPONENTS_Y);
    }

    /**
     * Convert public URL to absolute file path
     */
    private function getPublicPath(string $url): string
    {
        // Remove domain and query string if present
        $path = parse_url($url, PHP_URL_PATH);
        return PUBLIC_PATH . $path;
    }
}
