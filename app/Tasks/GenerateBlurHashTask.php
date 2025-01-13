<?php

namespace App\Tasks;

use App\Models\Memes;
use Phalcon\Cli\Task;
use kornrunner\Blurhash\Blurhash;

class GenerateBlurHashTask extends Task
{
    const COMPONENTS_X = 4;
    const COMPONENTS_Y = 3;
    const BATCH_SIZE = 5; // Reduced from 10
    const MAX_IMAGE_DIMENSION = 50; // Reduced from 100

    /**
     * Generate blur hashes for all memes that don't have them
     */
    public function mainAction()
    {
        echo "Starting blur hash generation...\n";

        $conditions = [];
        $params = [];

        // Only process memes that need blur hashes
        $conditions[] = '(meme_type IN ("image", "video"))';

        // Get last processed ID from command line
        $lastId = $this->dispatcher->getParam('last_id');
        if ($lastId) {
            $conditions[] = 'id > :last_id:';
            $params['last_id'] = $lastId;
        }

        $where = count($conditions) > 0 ? implode(' AND ', $conditions) : '';

        // Get total count for progress tracking
        $total = Memes::count([
            'conditions' => $where,
            'bind' => $params
        ]);

        if ($total === 0) {
            echo "No memes found that need blur hash generation.\n";
            return;
        }

        $processed = 0;
        $errors = 0;
        $lastProcessedId = $lastId ?? 0;

        // Process in batches to manage memory
        while ($processed < $total) {
            $memes = Memes::find([
                'conditions' => $where,
                'bind' => $params,
                'order' => 'id ASC',
                'limit' => self::BATCH_SIZE,
                'offset' => $processed
            ]);

            foreach ($memes as $meme) {
                try {
                    $processed++;
                    echo "Processing meme {$meme->id} ({$processed}/{$total})... ";

                    // For images, generate blur hash for the main file
                    if ($meme->meme_type === 'image' && empty($meme->blur_hash)) {
                        try {
                            $imagePath = $meme->getPublicUrl();
                            $meme->blur_hash = $this->generateBlurHash($imagePath);
                            echo "Generated blur hash for image.\n";
                        } catch (\Exception $e) {
                            echo "Error generating image blur hash: {$e->getMessage()}\n";
                            $errors++;
                            continue;
                        }
                    }

                    // For videos, generate blur hash for the thumbnail
                    if ($meme->meme_type === 'video' && empty($meme->blur_hash)) {
                        try {
                            $thumbnailPath = $meme->getThumbnailUrl();
                            $meme->blur_hash = $this->generateBlurHash($thumbnailPath);
                            echo "Generated blur hash for thumbnail.\n";
                        } catch (\Exception $e) {
                            echo "Error generating thumbnail blur hash: {$e->getMessage()}\n";
                            $errors++;
                            continue;
                        }
                    }

                    // Save the meme
                    if (!$meme->save()) {
                        $messages = [];
                        foreach ($meme->getMessages() as $message) {
                            $messages[] = $message->getMessage();
                        }
                        echo "Error saving meme: " . implode(", ", $messages) . "\n";
                        $errors++;
                    } else {
                        echo "Saved.\n";
                        $lastProcessedId = $meme->id;
                    }

                } catch (\Exception $e) {
                    echo "Error processing meme {$meme->id}: {$e->getMessage()}\n";
                    $errors++;
                }

                // Clean up memory
                gc_collect_cycles();
            }

            // Save progress after each batch
            echo "\nProgress: {$processed}/{$total} memes processed. Last ID: {$lastProcessedId}\n";
            echo "To continue from this point, run: php cli.php generate_blur_hash --last_id={$lastProcessedId}\n\n";

            // Clean up memory
            unset($memes);
            gc_collect_cycles();
        }

        echo "\nCompleted processing {$total} memes.\n";
        echo "Successful: " . ($processed - $errors) . "\n";
        echo "Errors: {$errors}\n";
        echo "Last processed ID: {$lastProcessedId}\n";
        echo "To continue from this point, run: php cli.php generate_blur_hash --last_id={$lastProcessedId}\n";
    }

    /**
     * Generate blur hash for a single image
     * 
     * @param string $imagePath URL of the image
     * @return string Generated blur hash
     * @throws \Exception
     */
    private function generateBlurHash(string $imagePath): string
    {
        // Download and load image
        $imageData = @file_get_contents($imagePath);
        if ($imageData === false) {
            throw new \Exception("Failed to download image: {$imagePath}");
        }

        $image = @imagecreatefromstring($imageData);
        if (!$image) {
            throw new \Exception("Failed to load image data");
        }

        try {
            // Get original dimensions
            $origWidth = imagesx($image);
            $origHeight = imagesy($image);

            // Calculate new dimensions while maintaining aspect ratio
            if ($origWidth > $origHeight) {
                $newWidth = self::MAX_IMAGE_DIMENSION;
                $newHeight = intval($origHeight * (self::MAX_IMAGE_DIMENSION / $origWidth));
            } else {
                $newHeight = self::MAX_IMAGE_DIMENSION;
                $newWidth = intval($origWidth * (self::MAX_IMAGE_DIMENSION / $origHeight));
            }

            // Create resized image
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);

            // Free original image memory
            imagedestroy($image);
            $image = $resized;

            // Create pixel array
            $width = imagesx($image);
            $height = imagesy($image);
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

            // Generate blur hash
            $blurHash = Blurhash::encode($pixels, self::COMPONENTS_X, self::COMPONENTS_Y);

            return $blurHash;
        } finally {
            // Always free image memory
            if (isset($image) && is_resource($image)) {
                imagedestroy($image);
            }
            if (isset($resized) && is_resource($resized)) {
                imagedestroy($resized);
            }

            // Clean up memory
            unset($pixels, $imageData);
            gc_collect_cycles();
        }
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
