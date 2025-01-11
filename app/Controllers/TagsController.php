<?php

namespace App\Controllers;

use App\Models\Memes;
use App\Models\MemeTags;
use App\Services\SearchService;
use App\Services\TagSuggestionService;
use Phalcon\Http\ResponseInterface;

class TagsController extends ControllerBase
{
    private TagSuggestionService $tagSuggestionService;

    public function initialize()
    {
        parent::initialize();

        $this->tagSuggestionService = new TagSuggestionService();
    }

    /**
     * Get suggested tags
     * @route /api/tags/suggestions
     * @method GET
     */
    public function suggestionsAction()
    {
        try {
            // Get language from query parameter, default to English
            $language = $this->request->getQuery('lang', 'string', 'en');
            
            // Validate language
            if (!in_array($language, ['en', 'ar'])) {
                return $this->response
                    ->setStatusCode(400)
                    ->setJsonContent([
                        'status' => 'error',
                        'message' => 'Invalid language code. Supported codes: en, ar'
                    ]);
            }

            // Get suggestions
            $suggestions = $this->tagSuggestionService->getSuggestions($language);

            return $this->response->setJsonContent([
                'status' => 'success',
                'data' => [
                    'language' => $language,
                    'suggestions' => $suggestions
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
                    'language' => $responseLanguage ?? null
                ]
            );
            return $this->response
                ->setStatusCode(500)
                ->setJsonContent([
                    'status' => 'error',
                    'message' => 'Something went wrong. Please try again later.'
                ]);
        }
    }

    /**
     * Get trending tags
     * @route /api/tags/trending
     * @method GET
     */
    public function trendingAction()
    {
        try {
            $language = $this->request->getQuery('lang', 'string', 'en');
            $limit = $this->request->getQuery('limit', 'int', 10);
            
            // Validate language
            if (!in_array($language, ['en', 'ar'])) {
                return $this->response
                    ->setStatusCode(400)
                    ->setJsonContent([
                        'status' => 'error',
                        'message' => 'Invalid language code. Supported codes: en, ar'
                    ]);
            }

            // Validate limit
            if ($limit < 1 || $limit > 50) {
                return $this->response
                    ->setStatusCode(400)
                    ->setJsonContent([
                        'status' => 'error',
                        'message' => 'Limit must be between 1 and 50'
                    ]);
            }

            // Get trending tags
            $trending = $this->tagSuggestionService->getTrendingTags($language, $limit);

            return $this->response->setJsonContent([
                'status' => 'success',
                'data' => [
                    'language' => $language,
                    'trending_tags' => $trending
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
                    'language' => $responseLanguage ?? null
                ]
            );
            return $this->response
                ->setStatusCode(500)
                ->setJsonContent([
                    'status' => 'error',
                    'message' => 'Something went wrong. Please try again later.'
                ]);
        }
    }

    /**
     * Add tags to a meme
     *
     * @param int $id Meme ID
     * @return ResponseInterface
     */
    public function addTagsAction(int $id)
    {
        try {
            /**
             * Check if meme exists
             * @var Memes $meme
             */
            $meme = Memes::findFirst($id);
            if (!$meme) {
                $this->response->setStatusCode(404, 'Not Found');
                return $this->response->setJsonContent([
                    'status' => 'error',
                    'message' => 'Meme not found'
                ]);
            }

            // Get tags from request
            $tags = $this->request->getJsonRawBody(true);
            if (empty($tags) || !isset($tags['tags']) || !is_array($tags['tags'])) {
                $this->response->setStatusCode(400, 'Bad Request');
                return $this->response->setJsonContent([
                    'status' => 'error',
                    'message' => 'Tags array is required in request body'
                ]);
            }

            // Process each tag
            $addedTags = [];
            $errors = [];

            foreach ($tags['tags'] as $tagText) {
                // Validate tag
                $tagText = trim($tagText);
                if (empty($tagText)) {
                    continue;
                }

                if (preg_match('/\p{Arabic}/u', $tagText)) {
                    $languageCode = 'arabic';
                } else {
                    $languageCode = 'english';
                }

                // Create new tag
                $memeTag = new MemeTags();
                $memeTag->meme_id = $id;
                $memeTag->tag = $tagText;
                $memeTag->language = $languageCode;
                $memeTag->confidence = 1.0; // User-added tags have full confidence
                $memeTag->origin = MemeTags::ORIGIN_MANUAL;

                if ($memeTag->save()) {
                    $addedTags[] = $tagText;

                    // Index the new tag in search
                    /** @var SearchService $searchService */
                    $searchService = $this->di->get('searchService');
                    try {
                        $searchService->indexTag(
                            $id,
                            $tagText,
                            $languageCode,
                            1.0,
                            $meme->meme_type
                        );
                    } catch (\Exception $e) {
                        $this->di->get('logger')->error(
                            "Failed to index tag: " . $e->getMessage(),
                            ['tag' => $tagText, 'meme_id' => $id]
                        );
                    }
                } else {
                    $messages = [];
                    foreach ($memeTag->getMessages() as $message) {
                        $messages[] = $message->getMessage();
                    }
                    $errors[] = "Failed to save tag '$tagText': " . implode(', ', $messages);
                }
            }

            // Prepare response
            $response = [
                'status' => 'success',
                'data' => [
                    'added_tags' => $addedTags,
                    'errors' => $errors
                ]
            ];

            // If no tags were added but there were errors, set error status
            if (empty($addedTags) && !empty($errors)) {
                $response['status'] = 'error';
                $this->response->setStatusCode(400);
            }

            return $this->response->setJsonContent($response);

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
                'message' => 'An error occurred while adding tags'
            ]);
        }
    }
}
