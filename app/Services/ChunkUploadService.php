<?php

namespace App\Services;

use Phalcon\Di\Injectable;

class ChunkUploadService extends Injectable
{
    private $chunkDir;
    private $maxChunkSize = 5 * 1024 * 1024; // 5MB chunks

    public function __construct()
    {
        $this->chunkDir = BASE_PATH . '/storage/chunks/';
        if (!is_dir($this->chunkDir)) {
            mkdir($this->chunkDir, 0777, true);
        }
    }

    public function handleChunk($fileId, $chunk, $totalChunks, $chunkNumber, $originalName, $mimeType)
    {
        $chunkPath = $this->chunkDir . $fileId . '_' . $chunkNumber;
        
        // Save the chunk
        if (!move_uploaded_file($chunk->getTempName(), $chunkPath)) {
            throw new \Exception("Failed to save chunk");
        }

        // Check if all chunks are uploaded
        if ($this->areAllChunksUploaded($fileId, $totalChunks)) {
            return $this->mergeChunks($fileId, $totalChunks, $originalName, $mimeType);
        }

        return null;
    }

    private function areAllChunksUploaded($fileId, $totalChunks)
    {
        for ($i = 0; $i < $totalChunks; $i++) {
            if (!file_exists($this->chunkDir . $fileId . '_' . $i)) {
                return false;
            }
        }
        return true;
    }

    private function mergeChunks($fileId, $totalChunks, $originalName, $mimeType)
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'upload_');
        $out = fopen($tmpFile, 'wb');

        // Merge all chunks
        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkPath = $this->chunkDir . $fileId . '_' . $i;
            $in = fopen($chunkPath, 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            unlink($chunkPath); // Clean up chunk
        }
        fclose($out);

        if (strpos($mimeType, 'zip') !== false) {
            // For ZIP files, extract and process images
            $extractDir = $this->chunkDir . $fileId . '_extracted';
            mkdir($extractDir, 0777, true);

            $zip = new \ZipArchive();
            if ($zip->open($tmpFile) === true) {
                $zip->extractTo($extractDir);
                $zip->close();

                // Clean up the temporary ZIP file
                unlink($tmpFile);
                return [
                    'type' => 'zip',
                    'path' => $extractDir
                ];
            } else {
                throw new \Exception('Failed to open ZIP file');
            }
        }

        return [
            'type' => 'file',
            'path' => $tmpFile
        ];
    }

    public function cleanupExtractedDir($path)
    {
        if (is_dir($path)) {
            $this->rrmdir($path);
        }
    }

    private function rrmdir($dir)
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (is_dir($dir . "/" . $object)) {
                        $this->rrmdir($dir . "/" . $object);
                    } else {
                        unlink($dir . "/" . $object);
                    }
                }
            }
            rmdir($dir);
        }
    }

    public function getMaxChunkSize()
    {
        return $this->maxChunkSize;
    }
}
