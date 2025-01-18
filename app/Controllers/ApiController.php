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
            $offset = $page * $limit;
            $isAll = $this->request->getQuery('all', 'int', 0);
            $order = $isAll ? 'id DESC' : 'rand()';

            // Parse tags if provided
            $tagArray = [];
            if (!empty($tags)) {
                $tagArray = array_map('trim', explode(',', $tags));
                $tagArray = array_filter($tagArray); // Remove empty tags
            }

            if (!empty($query)) {
                $tagArray = array_merge($tagArray, explode(' ', $query));
            }

            // Get language from header for response formatting
            $responseLanguage = $this->getLanguageFromHeader();

            // Log search query if we have one
            if (!empty($query) || !empty($tagArray)) {
                $searchLog = new SearchLogs();
                $searchLog->query = $query;
                $searchLog->tags = implode(',', $tagArray);
                $searchLog->language = $responseLanguage;
                $searchLog->save();
            }

            $totalApprovedCount = 0;

            // If no query and no tags, get directly from database
            if (empty($query) && empty($tagArray)) {
                // Build conditions
                $conditions = ['status = :status:'];
                $params = ['status' => Memes::STATUS_APPROVED];
                
                if (!empty($type)) {
                    $conditions[] = 'meme_type = :type:';
                    $params['type'] = $type;
                }

                if (!empty($memeId)) {
                    $conditions[] = 'meme_id = :memeId:';
                    $params['memeId'] = $memeId;
                }

                // Get total count first
                $totalCount = Memes::count([
                    'conditions' => implode(' AND ', $conditions),
                    'bind' => $params
                ]);

                // Then get the page of results
                $memes = Memes::find([
                    'conditions' => implode(' AND ', $conditions),
                    'bind' => $params,
                    'order' => $order,
                    'limit' => $limit,
                    'offset' => $offset
                ]);

                $totalMemeIds = range(1, $totalCount);
            } else {
                /** @var SearchService $searchService */
                $searchService = $this->di->get('searchService');

                if (!empty($memeId)) {
                    $memeIds = [$memeId];
                    $totalMemeIds = [$memeId];
                } else {
                    // Get meme IDs from search service
                    $memeIds = array_values($searchService->search($query, $tagArray, $responseLanguage, $limit, $offset, $type));
                    $totalMemeIds = array_values($searchService->search($query, $tagArray, $responseLanguage, 1000, 0, $type));
                }

                $memes = [];
                if (!empty($memeIds)) {
                    $queryParams = [
                        'conditions' => '\App\Models\MemeTags.status = :tag_status: AND \App\Models\Memes.id IN ({ids:array}) AND \App\Models\Memes.status = :status:' . (!empty($type) ? ' AND \App\Models\Memes.meme_type = :type:' : ''),
                        'bind' => array_merge(
                            [
                                'ids' => $memeIds,
                                'status' => Memes::STATUS_APPROVED,
                                'tag_status' => MemeTags::STATUS_APPROVED,
                            ],
                            !empty($type) ? ['type' => $type] : []
                        )
                    ];

                    /** @var Memes[] $memes */
                    $memes = Memes::query()
                    ->distinct(['id'])
                        ->columns([
                            '\App\Models\Memes.*',
                            'MAX(\App\Models\MemeTags.confidence) as tag_confidence'
                        ])
                        ->leftJoin(MemeTags::class, '\App\Models\Memes.id = \App\Models\MemeTags.meme_id')
                        ->where($queryParams['conditions'], $queryParams['bind'])
                        ->orderBy('tag_confidence DESC')
                        ->groupBy('\App\Models\Memes.id')
                        ->execute();

                    $memes = array_map(function ($meme) {
                        return $meme->{"app\Models\Memes"};
                    }, $memes->toArray());

                    // Get total count of approved memes
                    $totalApprovedCount = Memes::count([
                        'conditions' => 'id IN ({ids:array}) AND status = :status:',
                        'bind' => [
                            'ids' => $totalMemeIds,
                            'status' => Memes::STATUS_APPROVED
                        ]
                    ]);
                }
            }

            // Get meme details
            $formattedResults = [];

            foreach ($memes as $meme) {
                // Get tags in both languages but prioritize response language
                $primaryTags = array_column($meme->getTagsByLanguage($responseLanguage === 'ar' ? 'arabic' : 'english')->toArray(), 'tag');
                $secondaryTags = array_column($meme->getTagsByLanguage($responseLanguage === 'ar' ? 'english' : 'arabic')->toArray(), 'tag');

                $memesAsArray = [
                    'id' => $meme->id,
                    'url' => $meme->getPublicUrl(),
                    'width' => $meme->width,
                    'height' => $meme->height,
                    'type' => $meme->meme_type,
                    'laughs_count' => $meme->getLaughsCount(),
                    'views_count' => $meme->getViewCount(),
                    'blur_hash' => $meme->blur_hash,
                    'tags' => [
                        'primary' => $primaryTags,
                        'secondary' => $secondaryTags
                    ]
                ];

                if ($type == 'video') {
                    $memesAsArray['thumbnail'] = $meme->getThumbnailUrl();
                }

                $formattedResults[] = $memesAsArray;
            }

            // Update search log with results count
            if (!empty($query) || !empty($tagArray)) {
                $searchLog->results_count = $totalApprovedCount;
                $searchLog->save();
            }

            return $this->response->setJsonContent([
                'status' => 'success',
                'data' => [
                    'total' => $totalMemeIds ? count($totalMemeIds) : 0,
                    'page' => $page, // Return zero-based page number
                    'limit' => $limit,
                    'language' => $responseLanguage,
                    'type' => $type,
                    'items' => $formattedResults
                ]
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
                ),
                [
                    'query' => $query ?? null,
                    'tags' => $tags ?? null,
                    'page' => $page ?? null,
                    'limit' => $limit ?? null,
                    'language' => $responseLanguage ?? null,
                    'type' => $type ?? null
                ]
            );

            return $this->response->setJsonContent([
                'status' => 'error',
                'message' => 'An error occurred while processing your search'
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
