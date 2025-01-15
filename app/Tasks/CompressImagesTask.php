<?php

namespace App\Tasks;

use App\Models\Memes;
use Phalcon\Cli\Task;

class CompressImagesTask extends Task
{
    const MAX_SIZE_BYTES = 1024 * 1024; // 1MB
    const BATCH_SIZE = 10;
    const JPEG_QUALITY = 75;
    const PNG_COMPRESSION = 9;
    const TEMP_DIR = 'temp/compression';

    public function mainAction()
    {
        echo "Starting image compression task...\n";

        // Create temp directory if it doesn't exist
        if (!is_dir($this->getTempDir())) {
            mkdir($this->getTempDir(), 0777, true);
        }

        $conditions = [];
        $params = [];

        // Get last processed ID from command line
        $lastId = $this->dispatcher->getParams()[0] ?? null;
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
            echo "No memes found to process.\n";
            return;
        }

        $processed = 0;
        $compressed = 0;
        $errors = 0;
        $lastProcessedId = $lastId ?? 0;
        $savedSpace = 0;

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
                    $lastProcessedId = $meme->id;
                    echo "Processing meme {$meme->id} ({$processed}/{$total})... ";

                    // Get the appropriate URL based on meme type
                    $url = $meme->meme_type === 'video' ? $meme->getThumbnailUrl() : $meme->getPublicUrl();
                    $tempPath = $this->downloadImage($url);
                    
                    if (!$tempPath) {
                        echo "Failed to download image.\n";
                        $errors++;
                        continue;
                    }

                    $fileSize = filesize($tempPath);
                    if ($fileSize <= self::MAX_SIZE_BYTES) {
                        echo "Size OK ({$this->formatSize($fileSize)}), skipping.\n";
                        unlink($tempPath);
                        continue;
                    }

                    echo "Original size: {$this->formatSize($fileSize)}, compressing... ";

                    // For video memes, we're always compressing a thumbnail image
                    $compressedPath = $this->compressImage($tempPath);
                    if (!$compressedPath) {
                        echo "Compression failed.\n";
                        $errors++;
                        continue;
                    }

                    $newSize = filesize($compressedPath);
                    $savedSpace += ($fileSize - $newSize);

                    echo "New size: {$this->formatSize($newSize)} (saved {$this->formatSize($fileSize - $newSize)}).\n";

                    // Upload to S3 and update the meme record
                    $s3Key = $meme->meme_type === 'video' ? $meme->thumbnail : $meme->file_path;
                    if ($this->uploadToS3($compressedPath, $s3Key)) {
                        echo "Successfully uploaded compressed file to S3.\n";
                    } else {
                        echo "Failed to upload compressed file to S3.\n";
                        $errors++;
                    }

                    $compressed++;

                    // Cleanup
                    unlink($tempPath);
                    unlink($compressedPath);

                } catch (\Exception $e) {
                    echo "Error: {$e->getMessage()}\n";
                    $errors++;
                }

                // Clean up memory
                gc_collect_cycles();
            }

            // Save progress after each batch
            echo "\nProgress: {$processed}/{$total} memes processed.\n";
            echo "Compressed: {$compressed}, Errors: {$errors}\n";
            echo "Total space saved: {$this->formatSize($savedSpace)}\n";
            echo "To continue from this point, run: php cli.php compress_images --last_id={$lastProcessedId}\n\n";

            // Clean up memory
            unset($memes);
            gc_collect_cycles();
        }

        // Final cleanup
        $this->cleanup();
    }

    private function downloadImage(string $url): ?string
    {
        $tempPath = tempnam($this->getTempDir(), 'img_');
        
        $ch = curl_init($url);
        $fp = fopen($tempPath, 'wb');
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $success = curl_exec($ch);
        curl_close($ch);
        fclose($fp);

        return $success ? $tempPath : null;
    }

    private function compressImage(string $inputPath): ?string
    {
        // Detect image type from file content
        $imageInfo = getimagesize($inputPath);
        if (!$imageInfo) {
            throw new \Exception("Could not detect image type");
        }

        // Map MIME type to extension
        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif'
        ];

        $extension = $mimeToExt[$imageInfo['mime']] ?? null;
        if (!$extension) {
            throw new \Exception("Unsupported image type: {$imageInfo['mime']}");
        }

        $outputPath = tempnam($this->getTempDir(), 'compressed_') . '.' . $extension;

        try {
            // Check if it's a GIF first
            if ($extension === 'gif') {
                return $this->compressGif($inputPath, $outputPath);
            }

            // Handle other image types
            switch ($extension) {
                case 'jpg':
                case 'jpeg':
                    return $this->compressJpeg($inputPath, $outputPath);
                
                case 'png':
                    return $this->compressPng($inputPath, $outputPath);
                
                default:
                    throw new \Exception("Unsupported image type: {$extension}");
            }
        } catch (\Exception $e) {
            if (file_exists($outputPath)) {
                unlink($outputPath);
            }
            throw $e;
        }
    }

    private function compressGif(string $input, string $output): ?string
    {
        // Use gifsicle for GIF compression
        exec("gifsicle -O3 --lossy=80 -o \"{$output}\" \"{$input}\"", $cmdOutput, $returnVar);
        
        if ($returnVar !== 0) {
            return null;
        }

        return $output;
    }

    private function compressJpeg(string $input, string $output): ?string
    {
        $image = imagecreatefromjpeg($input);
        if (!$image) {
            return null;
        }

        if (!imagejpeg($image, $output, self::JPEG_QUALITY)) {
            imagedestroy($image);
            return null;
        }

        imagedestroy($image);
        return $output;
    }

    private function compressPng(string $input, string $output): ?string
    {
        $image = imagecreatefrompng($input);
        if (!$image) {
            return null;
        }

        // Preserve transparency
        imagealphablending($image, false);
        imagesavealpha($image, true);

        if (!imagepng($image, $output, self::PNG_COMPRESSION)) {
            imagedestroy($image);
            return null;
        }

        imagedestroy($image);
        return $output;
    }

    private function getTempDir(): string
    {
        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . self::TEMP_DIR;
    }

    private function cleanup(): void
    {
        $tempDir = $this->getTempDir();
        if (is_dir($tempDir)) {
            $files = glob($tempDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($tempDir);
        }
    }

    private function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    private function uploadToS3(string $localPath, string $s3Key): bool
    {
        try {
            /** @var S3Client $s3 */
            $s3 = $this->di->get('s3Client');
            $bucket = $this->di->get('config')->aws->bucket;

            $s3->putObject([
                'Bucket' => $bucket,
                'Key'    => $s3Key,
                'Body'   => fopen($localPath, 'rb'),
                'ACL'    => 'public-read',
                'ContentType' => mime_content_type($localPath)
            ]);

            return true;
        } catch (\Exception $e) {
            $this->di->get('logger')->error(
                "Failed to upload file to S3: " . $e->getMessage()
            );
            return false;
        }
    }
}
