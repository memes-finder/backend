<?php

namespace App\Controllers;

use App\Services\SearchService;
use App\Models\Memes;
use App\Models\SearchLogs;
use App\Models\MemeViews;
use App\Models\MemeLaughs;
use App\Models\MemeTags;
use Aws\S3\S3Client;
use Phalcon\Http\ResponseInterface;

class ApiController extends BaseApiController
{
    /**
     * Get language from Accept-Language header
     */
    private function getLanguageFromHeader(): string
    {
        $acceptLanguage = $this->request->getHeader('Accept-Language');
        if (!$acceptLanguage) {
            return 'en';
        }

        // Parse Accept-Language header
        $languages = explode(',', $acceptLanguage);
        $primaryLang = explode(';', $languages[0])[0];

        // Check if it's Arabic
        if (strpos(strtolower($primaryLang), 'ar') === 0) {
            return 'ar';
        }

        return 'en';
    }

    /**
     * Search memes
     */
    public function searchAction()
    {
        try {
            $query = $this->request->getQuery('q', 'string', '');
            $tags = $this->request->getQuery('tags', 'string', '');
            $type = $this->request->getQuery('type', 'string', '');
            $memeId = $this->request->getQuery('memeId', 'int', 0);
            $page = $this->request->getQuery('page', 'int', 0);
            $limit = $this->request->getQuery('limit', 'int', 20);

            $filters = [];
            if ($type) {
                $filters['meme_type'] = $type;
            }
            if ($tags) {
                $filters['tags'] = explode(',', $tags);
            }
            if ($memeId) {
                $filters['meme_id'] = $memeId;
            }

            $language = $this->getLanguageFromHeader();
            $filters['language'] = $language;

            // Use hybrid search for better results
            $searchService = new SearchService();
            $searchResults = $searchService->hybridSearch($query, $filters, $page, $limit, 0.4);
            
            $results = $searchResults['items'];
            $total = $searchResults['total'];

            // Log search query
            if ($query) {
                $searchLog = new SearchLogs();
                $searchLog->query = $query;
                $searchLog->language = $language;
                $searchLog->results_count = $total;
                $searchLog->save();
            }
            
            // Format response
            $formattedResults = [];
            foreach ($results as $result) {
                /** @var Memes $meme */
                $meme = Memes::findFirst($result['id']);
                if (!$meme) {
                    continue;
                }

                $tags = $meme->getTags();
                $primaryTags = $secondaryTags = [];

                foreach ($tags as $tag) {
                    if ($tag->language == 'english') {
                        $primaryTags[] = $tag->tag;
                    } else {
                        $secondaryTags[] = $tag->tag;
                    }
                }

                $formattedResults[] = [
                    'id' => $meme->id,
                    'url' => $meme->getPublicUrl(),
                    'blur_hash' => $meme->blur_hash,
                    'thumbnail' => $meme->getThumbnailUrl(),
                    'width' => $meme->width,
                    'height' => $meme->height,
                    'type' => $meme->meme_type,
                    'views_count' => $meme->getViewCount(),
                    'laughs_count' => $meme->getLaughsCount(),
                    'tags' => [
                        'primary' => $primaryTags,
                        'secondary' => $secondaryTags
                    ]
                ];
            }

            return $this->response->setJsonContent([
                'status' => 'success',
                'data' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'total_pages' => ceil($total / $limit),
                    'has_more' => ($page + 1) * $limit < $total,
                    'language' => $language,
                    'type' => $type,
                    'items' => $formattedResults
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Search failed: ' . $e->getMessage());
            return $this->response->setJsonContent([
                'status' => 'error',
                'message' => 'Search failed'
            ])->setStatusCode(500);
        }
    }

    /**
     * Track a view for a specific meme
     * 
     * @param int $id Meme ID
     * @return ResponseInterface
     */
    public function trackViewAction(int $id)
    {
        try {
            // Check if meme exists
            $meme = Memes::findFirst($id);
            if (!$meme) {
                return $this->sendError('Meme not found', 404);
            }

            // Get client IP address
            $ipAddress = $this->request->getClientAddress();

            // Create new view record
            $view = new MemeViews();
            $view->meme_id = $id;
            $view->ip_address = $ipAddress;

            if (!$view->save()) {
                $messages = [];
                foreach ($view->getMessages() as $message) {
                    $messages[] = $message->getMessage();
                }

                $this->response->setStatusCode(400, 'Bad Request');
                return $this->response->setJsonContent([
                    'status' => 'error',
                    'message' => 'Failed to save view: ' . implode(', ', $messages)
                ]);
            }

            return $this->response->setJsonContent([
                'status' => 'success',
                'message' => 'View recorded successfully'
            ]);
        } catch (\PDOException $exception) {
            $this->response->setStatusCode(400, 'Bad Request');
            return $this->response->setJsonContent([
                'status' => 'error',
                'message' => 'The view from this IP is already registered on this meme'
            ]);
        } catch (\Exception $e) {
            // Log error details
            $this->di->get('logger')->error(
                sprintf(
                    "[API Error] %s\nFile: %s\nLine: %d\nTrace:\n%s",
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                    $e->getTraceAsString()
                )
            );
            $this->response->setStatusCode(500, 'Internal server error');
            return $this->response->setJsonContent([
                'status' => 'error',
                'message' => 'An error occurred while tracking the view: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Toggle laugh status for a meme
     * 
     * @param int $id Meme ID
     * @return ResponseInterface
     */
    public function laughAction(int $id)
    {
        try {
            // Check if meme exists
            $meme = Memes::findFirst($id);
            if (!$meme) {
                $this->response->setStatusCode(404, 'Not Found');
                return $this->response->setJsonContent([
                    'status' => 'error',
                    'message' => 'Meme not found'
                ]);
            }

            // Get client IP address
            $ipAddress = $this->request->getClientAddress();

            // Like
            $laugh = new MemeLaughs();
            $laugh->meme_id = $id;
            $laugh->ip_address = $ipAddress;
            if (!$laugh->save()) {
                throw new \Exception('Failed to save laugh');
            }

            return $this->response->setJsonContent([
                'status' => 'success',
                'message' => null,
            ]);

        } catch (\Exception $e) {
            // Log error details
            $this->di->get('logger')->error(
                sprintf(
                    "[API Error] %s\nFile: %s\nLine: %d\nTrace:\n%s",
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                    $e->getTraceAsString()
                )
            );
            $this->response->setStatusCode(500, 'Internal Server Error');
            return $this->response->setJsonContent([
                'status' => 'error',
                'message' => 'An error occurred'
            ]);
        }
    }

    /**
     * Increase views count for a meme
     *
     * @param int $id Meme ID
     * @return ResponseInterface
     */
    public function viewAction(int $id)
    {
        try {
            // Check if meme exists
            $meme = Memes::findFirst($id);
            if (!$meme) {
                $this->response->setStatusCode(404, 'Not Found');
                return $this->response->setJsonContent([
                    'status' => 'error',
                    'message' => 'Meme not found'
                ]);
            }

            // Get client IP address
            $ipAddress = $this->request->getClientAddress();

            // Like
            $laugh = new MemeViews();
            $laugh->meme_id = $id;
            $laugh->ip_address = $ipAddress;
            if (!$laugh->save()) {
                throw new \Exception('Failed to save laugh');
            }

            return $this->response->setJsonContent([
                'status' => 'success',
                'message' => null,
            ]);

        } catch (\Exception $e) {
            // Log error details
            $this->di->get('logger')->error(
                sprintf(
                    "[API Error] %s\nFile: %s\nLine: %d\nTrace:\n%s",
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                    $e->getTraceAsString()
                )
            );
            $this->response->setStatusCode(500, 'Internal Server Error');
            return $this->response->setJsonContent([
                'status' => 'error',
                'message' => 'An error occurred'
            ]);
        }
    }

    /**
     * Get like status and count for a meme
     * 
     * @param int $id Meme ID
     * @return ResponseInterface
     */
    public function getLikeStatusAction(int $id)
    {
        try {
            // Check if meme exists
            $meme = Memes::findFirst($id);
            if (!$meme) {
                $this->response->setStatusCode(404, 'Not Found');
                return $this->response->setJsonContent([
                    'status' => 'error',
                    'message' => 'Meme not found'
                ]);
            }

            $ipAddress = $this->request->getClientAddress();

            return $this->response->setJsonContent([
                'status' => 'success',
                'data' => [
                    'liked' => $meme->isLikedByIp($ipAddress),
                    'like_count' => $meme->getLaughsCount()
                ]
            ]);

        } catch (\Exception $e) {
            $this->response->setStatusCode(500, 'Internal Server Error');
            return $this->response->setJsonContent([
                'status' => 'error',
                'message' => 'An error occurred: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Download a meme (image or video)
     * @param int $id Meme ID
     * @return mixed
     */
    public function downloadAction(int $id)
    {
        try {
            /** @var Memes $meme */
            $meme = Memes::findFirst([
                'conditions' => 'id = :id: AND status = :status:',
                'bind' => [
                    'id' => $id,
                    'status' => Memes::STATUS_APPROVED
                ]
            ]);

            if (!$meme) {
                return $this->response->setJsonContent([
                    'status' => 'error',
                    'message' => 'Meme not found'
                ])->setStatusCode(404);
            }

            /** @var S3Client $s3Client */
            $s3Client = $this->di->get('s3Client');
            $bucket = $this->di->get('config')->aws->bucket;

            // For images, return a pre-signed URL
            if ($meme->meme_type === 'image') {
                $command = $s3Client->getCommand('GetObject', [
                    'Bucket' => $bucket,
                    'Key' => $meme->file_path,
                    'ResponseContentDisposition' => 'attachment; filename="' . basename($meme->file_path) . '"'
                ]);

                $preSignedUrl = $s3Client->createPresignedRequest($command, '+15 minutes')->getUri();

                return $this->response->setJsonContent([
                    'status' => 'success',
                    'data' => [
                        'url' => (string) $preSignedUrl,
                        'expires_in' => 900 // 15 minutes in seconds
                    ]
                ]);
            }

            // For videos (m3u8), return MP4 URL
            if ($meme->meme_type === 'video') {
                if (!$meme->mp4_link) {
                    return $this->response->setJsonContent([
                        'status' => 'error',
                        'message' => 'MP4 version not available'
                    ])->setStatusCode(400);
                }

                $command = $s3Client->getCommand('GetObject', [
                    'Bucket' => $bucket,
                    'Key' => $meme->mp4_link,
                    'ResponseContentDisposition' => 'attachment; filename="meme_' . $meme->id . '.mp4"'
                ]);

                $preSignedUrl = $s3Client->createPresignedRequest($command, '+15 minutes')->getUri();

                return $this->response->setJsonContent([
                    'status' => 'success',
                    'data' => [
                        'url' => (string)$preSignedUrl,
                        'expires_in' => 900 // 15 minutes in seconds
                    ]
                ]);
            }

            return $this->response->setJsonContent([
                'status' => 'error',
                'message' => 'Unsupported meme type'
            ])->setStatusCode(400);

        } catch (\Exception $e) {
            $this->logger->error('Download failed: ' . $e->getMessage(), [
                'exception' => $e,
                'meme_id' => $id
            ]);

            return $this->response->setJsonContent([
                'status' => 'error',
                'message' => 'Failed to generate download URL'
            ])->setStatusCode(500);
        }
    }

    public function notFoundAction()
    {
        $this->response->setStatusCode(404, 'Not Found');
        return $this->response->setJsonContent([
            'status' => 'error',
            'message' => 'Not Found'
        ]);
    }
}
