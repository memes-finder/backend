<?php

namespace App\Tasks;

use Phalcon\Cli\Task;
use App\Models\Memes;
use App\Models\QueuedUploads;
use App\Services\ChunkUploadService;
use Aws\S3\S3Client;

class ProcessZipImagesTask extends Task
{
    private $s3;
    private $bucket;

    public function initialize()
    {
        $config = $this->getDI()->get('config');
        
        $this->s3 = new S3Client([
            'version' => 'latest',
            'region'  => $config->aws->region,
            'credentials' => [
                'key'    => $config->aws->key,
                'secret' => $config->aws->secret,
            ]
        ]);
        
        $this->bucket = $config->aws->bucket;
    }

    public function mainAction()
    {
        $this->println("Starting ZIP image processing...");

        try {
            // Get pending uploads
            $uploads = QueuedUploads::find([
                'conditions' => 'status = :status:',
                'bind' => ['status' => QueuedUploads::STATUS_PENDING],
                'order' => 'created_at ASC'
            ]);

            if (count($uploads) === 0) {
                $this->println("No pending uploads found.");
                return;
            }

            foreach ($uploads as $upload) {
                try {
                    $this->println("Processing upload ID: {$upload->id}");
                    
                    // Update status to processing
                    $upload->status = QueuedUploads::STATUS_PROCESSING;
                    $upload->save();
                    
                    $this->processUpload($upload);
                } catch (\Exception $e) {
                    $this->println("Error processing upload {$upload->id}: " . $e->getMessage());
                    $upload->status = QueuedUploads::STATUS_FAILED;
                    $upload->error_message = $e->getMessage();
                    $upload->save();
                }
            }

        } catch (\Exception $e) {
            $this->println("Error: " . $e->getMessage());
        }

        $this->println("ZIP image processing completed.");
    }

    private function processUpload($upload)
    {
        $extractDir = $upload->extract_path;
        if (!is_dir($extractDir)) {
            throw new \Exception("Extract directory not found");
        }

        $successCount = 0;
        $errorCount = 0;
        $errors = [];

        // Process all images in the ZIP
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webm', 'webp', 'avif'];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extractDir)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $extension = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
                if (in_array($extension, $allowedExtensions)) {
                    try {
                        $this->processImage($file->getPathname(), $file->getFilename());
                        $successCount++;
                    } catch (\Exception $e) {
                        $errorCount++;
                        $errors[] = $file->getFilename() . ': ' . $e->getMessage();
                    }
                }
            }
        }

        // Update upload status
        $upload->status = QueuedUploads::STATUS_COMPLETED;
        $upload->processed_count = $successCount;
        $upload->error_count = $errorCount;
        $upload->error_message = $errors ? implode("\n", $errors) : null;
        $upload->completed_at = date('Y-m-d H:i:s');
        $upload->save();

        // Clean up
        $chunkService = new ChunkUploadService();
        $chunkService->cleanupExtractedDir($extractDir);
    }

    private function processImage($tempPath, $originalName)
    {
        // Check if it's an image
        $imageInfo = getimagesize($tempPath);
        if (!$imageInfo) {
            throw new \Exception('Invalid image file');
        }

        // Generate unique filename
        $extension = image_type_to_extension($imageInfo[2], false);
        $filename = sprintf(
            '%s/%s.%s',
            date('Y/m'),
            md5(uniqid()),
            $extension
        );

        // Create meme record
        $meme = new Memes();
        $meme->file_path = $filename;
        $meme->mime_type = $imageInfo['mime'];
        $meme->width = $imageInfo[0];
        $meme->height = $imageInfo[1];
        $meme->status = Memes::STATUS_PENDING;
        $meme->created_at = date('Y-m-d H:i:s');
        $meme->file_hash = md5(file_get_contents($tempPath));

        // Upload to S3
        try {
            $this->s3->putObject([
                'Bucket' => $this->bucket,
                'Key'    => $filename,
                'Body'   => fopen($tempPath, 'rb'),
                'ContentType' => $imageInfo['mime'],
                'ACL'    => 'public-read'
            ]);
        } catch (\Exception $e) {
            throw new \Exception('Failed to upload to S3: ' . $e->getMessage());
        }

        // Save meme record
        if (!$meme->save()) {
            // Try to delete from S3 if database save fails
            try {
                $this->s3->deleteObject([
                    'Bucket' => $this->bucket,
                    'Key'    => $filename
                ]);
            } catch (\Exception $e) {
                // Log the error but don't throw since the original save already failed
                error_log("Failed to delete from S3 after failed save: " . $e->getMessage());
            }

            $messages = [];
            foreach ($meme->getMessages() as $message) {
                $messages[] = $message->getMessage();
            }
            throw new \Exception(implode(', ', $messages));
        }
    }

    private function println($message)
    {
        echo $message . PHP_EOL;
    }
}
