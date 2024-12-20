<?php

namespace App\Controllers;

use App\Services\SearchService;
use Phalcon\Mvc\Controller;
use App\Models\Memes;
use App\Models\SearchLogs;

class ApiController extends Controller
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
            $query = $this->request->getQuery('q', 'string');
            $page = $this->request->getQuery('page', 'int', 1);
            $limit = $this->request->getQuery('limit', 'int', 20);

            if (empty($query)) {
                return $this->response->setJsonContent([
                    'status' => 'error',
                    'message' => 'Query parameter is required'
                ]);
            }

            // Get language from header for response formatting
            $responseLanguage = $this->getLanguageFromHeader();

            // Log search query
            $searchLog = new SearchLogs();
            $searchLog->query = $query;
            $searchLog->language = $responseLanguage;
            $searchLog->save();

            // Search in both languages
            $offset = ($page - 1) * $limit;

            /** @var SearchService $searchService */
            $searchService = $this->di->get('searchService');
            $memeIds = $searchService->search($query, $responseLanguage, $limit, $offset, Memes::STATUS_APPROVED);

            // Get meme details
//            $memeIds = array_column($results['items'], 'meme_id');
            if ($memeIds) {
                /** @var Memes[] $memes */
                $memes = Memes::find([
                    'conditions' => 'id IN ({ids:array})',
                    'bind' => ['ids' => $memeIds]
                ]);
            } else {
                $memes = [];
            }

            $formattedResults = [];
            foreach ($memes as $meme) {
                // Get tags in both languages but prioritize response language
                $primaryTags = array_column($meme->getTagsByLanguage('arabic')->toArray(), 'tag');
                $secondaryTags = array_column($meme->getTagsByLanguage('english')->toArray(), 'tag');

                $formattedResults[] = [
                    'id' => $meme->id,
                    'url' => $meme->getPublicUrl(),
                    'width' => $meme->width,
                    'height' => $meme->height,
                    'tags' => [
                        'primary' => $primaryTags,
                        'secondary' => $secondaryTags
                    ]
                ];
            }

            // Update search log with results count
            $searchLog->results_count = count($formattedResults);
            $searchLog->save();

            return $this->response->setJsonContent([
                'status' => 'success',
                'data' => [
                    'total' => count($formattedResults),
                    'page' => $page,
                    'limit' => $limit,
                    'language' => $responseLanguage,
                    'items' => $formattedResults
                ]
            ]);
        } catch (\Exception $e) {
            return $this->response->setJsonContent([
                'status' => 'error',
                'message' => 'An error occurred while processing your request'
            ]);
        }
    }

    /**
     * Get search suggestions
     */
    public function suggestionsAction()
    {
        try {
            $prefix = $this->request->getQuery('q', 'string');

            if (empty($prefix)) {
                return $this->response->setJsonContent([
                    'status' => 'error',
                    'message' => 'Query parameter is required'
                ]);
            }

            // Get language from header
            $responseLanguage = $this->getLanguageFromHeader();

            // Get suggestions in both languages but prioritize response language
            $primarySuggestions = $this->di->get('searchService')->getSuggestions($prefix, $responseLanguage);
            $secondarySuggestions = $this->di->get('searchService')->getSuggestions(
                $prefix, 
                $responseLanguage === 'en' ? 'ar' : 'en'
            );

            return $this->response->setJsonContent([
                'status' => 'success',
                'data' => [
                    'language' => $responseLanguage,
                    'suggestions' => [
                        'primary' => $primarySuggestions,
                        'secondary' => $secondarySuggestions
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return $this->response->setJsonContent([
                'status' => 'error',
                'message' => 'An error occurred while processing your request'
            ]);
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
