<?php

namespace App\Controllers;

use App\Models\Memes;
use App\Models\MemeTags;
use App\Services\TagSuggestionService;
use Phalcon\Http\ResponseInterface;

class TagsController extends BaseApiController
{
    private TagSuggestionService $tagSuggestionService;

    public function initialize()
    {
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
     * Add tags to a meme (will be pending until approved)
     * @route /api/tags/add/{id}
     * @method POST
     */
    public function addAction(int $id): ResponseInterface
    {
        try {
            // Check if meme exists
            $meme = Memes::findFirst($id);
            if (!$meme) {
                return $this->response
                    ->setStatusCode(404)
                    ->setJsonContent([
                        'status' => 'error',
                        'message' => 'Meme not found'
                    ]);
            }

            // Get tags from request
            $tags = $this->request->getJsonRawBody(true);
            if (empty($tags) || !isset($tags['tags']) || !is_array($tags['tags'])) {
                return $this->response
                    ->setStatusCode(400)
                    ->setJsonContent([
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

                // Create new tag (will be set as pending by model)
                $memeTag = new MemeTags();
                $memeTag->meme_id = $id;
                $memeTag->tag = $tagText;
                $memeTag->language = $languageCode;
                $memeTag->confidence = 1.0;
                $memeTag->origin = MemeTags::ORIGIN_MANUAL;

                if ($memeTag->save()) {
                    $addedTags[] = $tagText;
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
                    'errors' => $errors,
                    'message' => 'Tags added successfully and will be reviewed by moderators'
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
                ),
                [
                    'meme_id' => $id,
                    'tags' => $tags['tags'] ?? null,
                    'language' => $language ?? null
                ]
            );

            return $this->response
                ->setStatusCode(500)
                ->setJsonContent([
                    'status' => 'error',
                    'message' => 'An error occurred while adding tags'
                ]);
        }
    }
}
