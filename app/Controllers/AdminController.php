<?php

namespace App\Controllers;

use App\Models\Memes;
use App\Models\MemeTags;
use App\Models\QueuedUploads;
use App\Services\TaggingServices\TaggingServiceFactory;
use App\Services\TaggingServices\TaggingServiceInterface;

class AdminController extends ControllerBase
{
    public function initialize()
    {
        parent::initialize();
        
        // Set isLoggedIn flag for view
        $this->view->isLoggedIn = $this->session->has('admin_token') && 
            $this->session->get('admin_token') === $_ENV['ADMIN_TOKEN'];
        
        // Add admin layout
        $this->view->setLayout('admin');
        $this->view->title = 'Admin Panel';
        $this->view->flash = $this->flash;

        // Get meme counts by status
        if ($this->view->isLoggedIn) {
            $counts = [
                'total' => Memes::count(),
                'pending' => Memes::count(['conditions' => 'status = "pending"']),
                'approved' => Memes::count(['conditions' => 'status = "approved"'])
            ];
            $this->view->memeCounts = $counts;
        }
    }

    public function indexAction()
    {
        try {
            $currentPage = $this->request->getQuery('page', 'int', 1);
            $status = $this->request->getQuery('status', 'string', '');
            $searchQuery = $this->request->getQuery('q', 'string', '');
            $limit = 20;

            // Build query conditions
            $conditions = [];
            $binds = [];
            $parameters = [];
            
            // Add status condition if selected
            if ($status) {
                $conditions[] = 'status = :status:';
                $binds['status'] = $status;
            }

            // If search query exists, get meme IDs from search service
            if ($searchQuery) {
                /** @var \App\Services\SearchService $searchService */
                $searchService = $this->di->get('searchService');
                // Only exclude status filter if no status is selected
                $memeIds = array_values($searchService->search($searchQuery, [], null, 1000));

                if (!empty($memeIds)) {
                    $conditions[] = 'id IN ({meme_ids:array})';
                    $binds['meme_ids'] = $memeIds;
                } else {
                    // No results found
                    $conditions[] = 'id = 0'; // Force no results
                }
            }

            $parameters['conditions'] = !empty($conditions) ? implode(' AND ', $conditions) : null;
            $parameters['bind'] = $binds;
            $parameters['order'] = 'created_at DESC';

            // Now set up pagination
            $paginator = new \Phalcon\Paginator\Adapter\Model([
                'model' => Memes::class,
                'parameters' => $parameters,
                'limit' => $limit,
                'page' => $currentPage
            ]);

            $page = $paginator->paginate();

            $this->view->memes = $page;
            $this->view->status = $status;
            $this->view->searchQuery = $searchQuery;
        } catch (\Exception $e) {
            // Log general error
            $this->di->get('logger')->error(
                sprintf(
                    "[Admin Error] %s\nFile: %s\nLine: %d\nTrace:\n%s",
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                    $e->getTraceAsString()
                ),
                [
                    'page' => $currentPage ?? null,
                    'status' => $status ?? null,
                    'query' => $searchQuery ?? null
                ]
            );

            $this->flash->error('An error occurred while loading memes. Please try again.');
            return $this->response->redirect('/admin');
        }
    }

    public function memeAction($id = null)
    {
        if (!$id) {
            $this->flash->error('Meme ID is required');
            return $this->response->redirect('/admin');
        }

        $meme = Memes::findFirst($id);
        if (!$meme) {
            $this->flash->error('Meme not found');
            return $this->response->redirect('/admin');
        }

        if ($this->request->isPost()) {
            $action = $this->request->getPost('action');
            
            switch ($action) {
                case 'approve':
                    $meme->status = Memes::STATUS_APPROVED;
                    break;
                case 'pending':
                    $meme->status = Memes::STATUS_PENDING;
                    break;
                case 'delete':
                    $meme->delete();
                    $this->flash->success('Meme deleted successfully');
                    return $this->response->redirect('/admin');
            }

            if ($meme->save()) {
                // If it's an AJAX request, return JSON response
                if ($this->request->isAjax()) {
                    return $this->response
                        ->setStatusCode(200)
                        ->setJsonContent(['success' => true]);
                }
                $this->flash->success('Meme updated successfully');
            } else {
                $messages = [];
                foreach ($meme->getMessages() as $message) {
                    $messages[] = $message->getMessage();
                }
                // If it's an AJAX request, return JSON response
                if ($this->request->isAjax()) {
                    return $this->response
                        ->setJsonContent([
                            'success' => false,
                            'message' => implode(', ', $messages)
                        ]);
                }
                $this->flash->error(implode(', ', $messages));
            }
        }

        $this->view->meme = $meme;
        $this->view->tags = $meme->getTags();
    }

    public function deleteAction($id)
    {
        if (!$id) {
            $this->flash->error("Meme ID is required");
            return $this->response->redirect('/admin');
        }

        $meme = Memes::findFirst($id);
        if (!$meme) {
            $this->flash->error("Meme not found");
            return $this->response->redirect('/admin');
        }

        try {
            if ($meme->delete()) {
                $this->flash->success("Meme deleted successfully");
            } else {
                $messages = [];
                foreach ($meme->getMessages() as $message) {
                    $messages[] = $message->getMessage();
                }
                $this->flash->error("Failed to delete meme: " . implode(", ", $messages));
            }
        } catch (\Exception $e) {
            $this->flash->error("Error deleting meme: " . $e->getMessage());
        }

        // Get the referer URL or default to admin page
        $referer = $this->request->getServer('HTTP_REFERER');
        $returnUrl = $referer ? $referer : '/admin';
        
        // If we're deleting from the meme's own page, return to the main admin page
        if (strpos($referer, "/admin/meme/{$id}") !== false || 
            strpos($referer, "/admin/tags/{$id}") !== false) {
            $returnUrl = '/admin';
        }

        return $this->response->redirect($returnUrl);
    }

    public function tagsAction($memeId = null)
    {
        if (!$memeId) {
            $this->flash->error("Meme ID is required");
            return $this->response->redirect('/admin');
        }

        $meme = Memes::findFirst($memeId);
        if (!$meme) {
            $this->flash->error("Meme not found");
            return $this->response->redirect('/admin');
        }

        // Handle POST requests
        if ($this->request->isPost()) {
            $action = $this->request->getPost('action');
            
            if ($action === 'delete') {
                $tagId = $this->request->getPost('tag_id');
                $tag = MemeTags::findFirst($tagId);
                if ($tag) {
                    // Delete from database
                    if ($tag->delete()) {
                        // Delete from search index
                        try {
                            $this->di->get('searchService')->delete($tag->meme_id);
                            $this->flash->success("Tag deleted successfully");
                        } catch (\Exception $e) {
                            error_log("Error deleting tag from index: " . $e->getMessage());
                            $this->flash->notice("Tag deleted from database but there was an error updating the search index");
                        }
                    } else {
                        $messages = [];
                        foreach ($tag->getMessages() as $message) {
                            $messages[] = $message->getMessage();
                        }
                        $this->flash->error($messages ? implode(', ', $messages) : 'Failed to delete tag');
                    }
                }
            } elseif ($action === 'add') {
                $tag = new MemeTags();
                $tag->meme_id = $memeId;
                $tag->tag = $this->request->getPost('tag');
                $tag->language = $this->request->getPost('language');
                $tag->confidence = 1.0;
                $tag->created_at = date('Y-m-d H:i:s');
                
                if ($tag->save()) {
                    // Index the new tag
                    try {
                        $this->di->get('searchService')->indexTag(
                            $tag->meme_id,
                            $tag->tag,
                            $tag->language,
                            $tag->confidence,
                            $tag->created_at
                        );
                        $this->flash->success("Tag added successfully");
                    } catch (\Exception $e) {
                        error_log("Error indexing new tag: " . $e->getMessage());
                        $this->flash->notice("Tag saved to database but there was an error updating the search index");
                    }
                } else {
                    $messages = [];
                    foreach ($tag->getMessages() as $message) {
                        $messages[] = $message->getMessage();
                    }
                    $this->flash->error(implode(', ', $messages));
                }
            }
            
            // Redirect to refresh the page after changes
            return $this->response->redirect("/admin/tags/{$memeId}");
        }

        // Get tags grouped by language
        $tags = $meme->getTags();
        $englishTags = [];
        $arabicTags = [];

        foreach ($tags as $tag) {
            if ($tag->language === 'english') {
                $englishTags[] = $tag;
            } elseif ($tag->language === 'arabic') {
                $arabicTags[] = $tag;
            }
        }

        $this->view->meme = $meme;
        $this->view->englishTags = $englishTags;
        $this->view->arabicTags = $arabicTags;
    }

    public function generateTagsAction($memeId)
    {
        $taggingService = $this->request->getQuery('service', 'string');

        if (!$memeId) {
            $this->flash->error("Meme ID is required");
            return $this->response->redirect('/admin');
        }

        $meme = Memes::findFirst($memeId);
        if (!$meme) {
            $this->flash->error("Meme not found");
            return $this->response->redirect('/admin');
        }

        try {
            /** @var TaggingServiceInterface $aiService */
            $aiService = TaggingServiceFactory::create($taggingService);
            
            // Generate tags using AI
            $tags = $aiService->generateTags($meme->getPublicUrl());
            $now = date('Y-m-d H:i:s');
            
            // Save generated tags
            foreach ($tags as $language => $langTags) {
                foreach ($langTags as $tag) {
                    $memeTag = new MemeTags();
                    $memeTag->meme_id = $meme->id;
                    $memeTag->tag = $tag;
                    $memeTag->language = $language === 'ar' ? 'arabic' : 'english';
                    $memeTag->confidence = 0.9; // AI tags get high confidence
                    $memeTag->origin = $aiService->getOrigin();
                    $memeTag->created_at = $now;
                    
                    if ($memeTag->save()) {
                        // Index the new tag
                        try {
                            $this->di->get('searchService')->indexTag(
                                $memeTag->meme_id,
                                $memeTag->tag,
                                $memeTag->language,
                                $memeTag->confidence,
                                $memeTag->created_at
                            );
                        } catch (\Exception $e) {
                            error_log("Error indexing AI tag: " . $e->getMessage());
                        }
                    }
                }
            }
            
            $this->flash->success("AI tags generated successfully");
        } catch (\Exception $e) {
            $this->flash->error("Error generating AI tags: " . $e->getMessage());
        }

        return $this->response->redirect("/admin/tags/{$memeId}");
    }

    public function uploadAction()
    {
        if (!$this->view->isLoggedIn) {
            return $this->response->redirect('/admin/login');
        }
    }

    public function uploadChunkAction()
    {
        if (!$this->view->isLoggedIn) {
            return $this->response->setStatusCode(401)
                ->setJsonContent(['error' => 'Unauthorized']);
        }

        try {
            if (!$this->request->hasFiles()) {
                throw new \Exception('No chunk uploaded');
            }

            $fileId = $this->request->getPost('fileId');
            $totalChunks = (int)$this->request->getPost('totalChunks');
            $chunkNumber = (int)$this->request->getPost('chunkNumber');
            $originalName = $this->request->getPost('originalName');
            $mimeType = $this->request->getPost('mimeType');

            if (!$fileId || !$totalChunks || !isset($chunkNumber)) {
                throw new \Exception('Missing chunk information');
            }

            $chunk = $this->request->getUploadedFiles()[0];
            $chunkService = new \App\Services\ChunkUploadService();
            
            // Process the chunk
            $result = $chunkService->handleChunk($fileId, $chunk, $totalChunks, $chunkNumber, $originalName, $mimeType);
            
            if ($result) {
                if ($result['type'] === 'zip') {
                    // Queue the ZIP for processing
                    $upload = new \App\Models\QueuedUploads();
                    $upload->original_name = $originalName;
                    $upload->extract_path = $result['path'];
                    $upload->save();

                    return $this->response->setJsonContent([
                        'status' => 'complete',
                        'success' => true,
                        'message' => 'ZIP file queued for processing. You will be notified when processing is complete.'
                    ]);
                } else {
                    // Process single image immediately
                    $successCount = 0;
                    $errorCount = 0;
                    $errors = [];
                    
                    $this->processImage($result['path'], $originalName, $successCount, $errorCount, $errors);
                    unlink($result['path']); // Clean up temporary file
                    
                    return $this->response->setJsonContent([
                        'status' => 'complete',
                        'success' => $successCount > 0,
                        'message' => $successCount > 0 ? 
                            "Successfully uploaded {$successCount} " . ($successCount === 1 ? 'meme' : 'memes') : 
                            implode(', ', $errors)
                    ]);
                }
            }

            return $this->response->setJsonContent([
                'status' => 'chunk_received',
                'chunk' => $chunkNumber
            ]);

        } catch (\Exception $e) {
            $this->logger->error(
                sprintf(
                    "%s\nTrace:\n%s",
                    $e->getMessage(),
                    $e->getTraceAsString()
                )
            );
            return $this->response->setStatusCode(400)
                ->setJsonContent(['error' => $e->getMessage()]);
        }
    }

    public function uploadStatusAction()
    {
        if (!$this->view->isLoggedIn) {
            return $this->response->setStatusCode(401)
                ->setJsonContent(['error' => 'Unauthorized']);
        }

        // Get latest uploads
        $uploads = \App\Models\QueuedUploads::find([
            'conditions' => 'created_at >= :since:',
            'bind' => [
                'since' => date('Y-m-d H:i:s', strtotime('-1 hour'))
            ],
            'order' => 'created_at DESC',
            'limit' => 10
        ]);

        $results = [];
        foreach ($uploads as $upload) {
            $results[] = [
                'id' => $upload->id,
                'filename' => $upload->original_name,
                'status' => $upload->status,
                'processed' => $upload->processed_count,
                'errors' => $upload->error_count,
                'message' => $upload->error_message,
                'created_at' => $upload->created_at,
                'completed_at' => $upload->completed_at
            ];
        }

        return $this->response->setJsonContent($results);
    }

    /**
     * Process a single image file
     * @param string $tempPath Path to temporary file
     * @param string $originalName Original filename
     * @param int $successCount Reference to success counter
     * @param int $errorCount Reference to error counter
     * @param array $errors Reference to errors array
     */
    private function processImage($tempPath, $originalName, &$successCount, &$errorCount, &$errors)
    {
        try {
            // Check if it's an image
            $imageInfo = getimagesize($tempPath);
            if (!$imageInfo) {
                throw new \Exception('Invalid image file');
            }

            $mimeType = $imageInfo['mime'];
            if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif'])) {
                throw new \Exception("Invalid file type: {$mimeType}");
            }

            // Get image content and hash
            $imageContent = file_get_contents($tempPath);
            $imageHash = md5($imageContent);

            // Check for duplicate
            if (Memes::findFirst([
                'conditions' => 'file_hash = :hash:',
                'bind' => ['hash' => $imageHash]
            ])) {
                throw new \Exception('Duplicate image');
            }

            // Generate unique filename
            $extension = image_type_to_extension($imageInfo[2], false);
            $filename = sprintf(
                '%s/%s.%s',
                date('Y/m'),
                md5(uniqid() . $originalName),
                $extension
            );

            // Upload to S3
            $s3Client = $this->di->get('s3Client');
            $s3Client->putObject([
                'Bucket' => $this->config->aws->bucket,
                'Key' => $filename,
                'Body' => $imageContent,
                'ContentType' => $mimeType,
                'ACL' => 'public-read'
            ]);

            // Create meme record
            $meme = new Memes();
            $meme->file_path = $filename;
            $meme->source_url = '';
            $meme->source_type = 'admin_upload';
            $meme->width = $imageInfo[0];
            $meme->height = $imageInfo[1];
            $meme->file_size = strlen($imageContent);
            $meme->mime_type = $mimeType;
            $meme->file_hash = $imageHash;
            $meme->status = Memes::STATUS_PENDING;

            if (!$meme->save()) {
                throw new \Exception('Failed to save meme record: ' . implode(', ', $meme->getMessages()));
            }

            $successCount++;
        } catch (\Exception $e) {
            $errorCount++;
            $errors[] = $originalName . ': ' . $e->getMessage();
        }
    }

    /**
     * Recursively remove a directory and its contents
     * @param string $dir Directory path
     */
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

    public function loginAction()
    {
        // Set empty layout for login page
        $this->view->setLayout('');
        
        // Check if already logged in
        if ($this->session->has('admin_token') && 
            $this->session->get('admin_token') === $_ENV['ADMIN_TOKEN']) {
            return $this->response->redirect('/admin');
        }
        
        if ($this->request->isPost()) {
            $inputToken = $this->request->getPost('token');
            $envToken = $_ENV['ADMIN_TOKEN'];
            
            if ($inputToken === $envToken) {
                // Store token in session
                $this->session->set('admin_token', $inputToken);
                return $this->response->redirect('/admin');
            }
            
            $this->flash->error('Invalid admin token');
            $this->logger->warning('Invalid admin login attempt', [
                'ip' => $this->request->getClientAddress()
            ]);
        }
    }

    public function logoutAction()
    {
        $this->session->remove('admin_token');
        return $this->response->redirect('/admin/login');
    }
}
