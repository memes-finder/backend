<?php

namespace App\Controllers;

use App\Models\Memes;

class LambdaController extends BaseApiController
{
    /**
     * Create a new meme from video processed by Lambda
     * 
     * Expected JSON payload:
     * {
     *    file_name: "",
     *    file_path: "videos/...",
     *    thumbnail_url: `videos/.../thumbnail.jpg`,
     *    file_hash: "md5Hash",
     *    file_size, // in bytes
     *    duration, // in seconds
     *    mime_type: 'application/x-mpegURL'
     * }
     */
    public function createFromVideoAction()
    {
        try {
            // Verify request is from Lambda
            $authHeader = $this->request->getHeader('X-Lambda-Token');
            if (!$authHeader || $authHeader !== $_ENV['LAMBDA_API_TOKEN']) {
                return $this->response
                    ->setStatusCode(401)
                    ->setJsonContent(['error' => 'Unauthorized']);
            }

            // Get JSON payload
            $payload = $this->request->getJsonRawBody();
            if (!$payload) {
                return $this->response
                    ->setStatusCode(400)
                    ->setJsonContent(['error' => 'Invalid JSON payload']);
            }

            // Validate required fields
            $requiredFields = ['file_name', 'file_path', 'file_size', 'file_hash', 'mime_type', 'thumbnail_path', 'duration'];
            foreach ($requiredFields as $field) {
                if (!isset($payload->$field)) {
                    return $this->response
                        ->setStatusCode(400)
                        ->setJsonContent(['error' => "Missing required field: {$field}"]);
                }
            }

            // Check for duplicate by file hash
            if (Memes::findFirst([
                'conditions' => 'file_hash = :hash:',
                'bind' => ['hash' => $payload->file_hash]
            ])) {
                return $this->response
                    ->setStatusCode(409)
                    ->setJsonContent(['error' => 'Duplicate video']);
            }

            // Create meme record
            $meme = new Memes();
            $meme->file_path = $payload->filename;
            $meme->width = $payload->width;
            $meme->height = $payload->height;
            $meme->duration = $payload->duration;
            $meme->file_size = $payload->file_size;
            $meme->file_hash = $payload->file_hash;
            $meme->mime_type = $payload->mime_type;
            $meme->thumbnail = $payload->thumbnail_path;
            $meme->source_type = 'lambda_upload';
            $meme->status = Memes::STATUS_PENDING;
            $meme->created_at = date('Y-m-d H:i:s');

            if (!$meme->save()) {
                $errors = array_map(function($message) {
                    return $message->getMessage();
                }, $meme->getMessages());

                return $this->response
                    ->setStatusCode(400)
                    ->setJsonContent([
                        'error' => 'Failed to save meme',
                        'details' => implode(', ', $errors)
                    ]);
            }

            return $this->response
                ->setStatusCode(201)
                ->setJsonContent([
                    'success' => true,
                    'meme_id' => $meme->id
                ]);

        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                "Lambda API Error: %s\nTrace:\n%s",
                $e->getMessage(),
                $e->getTraceAsString()
            ));

            return $this->response
                ->setStatusCode(500)
                ->setJsonContent(['error' => 'Internal server error']);
        }
    }
}
