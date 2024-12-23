<?php

namespace App\Controllers;

use App\Services\TagSuggestionService;

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
}
