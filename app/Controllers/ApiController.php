<?php

namespace App\Controllers;

use App\Services\SearchService;
use App\Models\Memes;
use App\Models\SearchLogs;

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
            $page = $this->request->getQuery('page', 'int', 1);
            $limit = $this->request->getQuery('limit', 'int', 20);
            $offset = ($page - 1) * $limit;

            // Parse tags if provided
            $tagArray = [];
            if (!empty($tags)) {
                $tagArray = array_map('trim', explode(',', $tags));
                $tagArray = array_filter($tagArray); // Remove empty tags
            }

            // Require at least a query or tags
            if (empty($query) && empty($tagArray)) {
                return $this->response->setJsonContent([
                    'status' => 'error',
                    'message' => 'Either query (q) or tags parameter is required'
                ])->setStatusCode(400);
            }

            // Get language from header for response formatting
            $responseLanguage = $this->getLanguageFromHeader();

            // Log search query
            $searchLog = new SearchLogs();
            $searchLog->query = $query;
            $searchLog->tags = implode(',', $tagArray);
            $searchLog->language = $responseLanguage;
            $searchLog->save();

            /** @var SearchService $searchService */
            $searchService = $this->di->get('searchService');
            
            // Get meme IDs from search service
            $memeIds = array_values($searchService->search($query, $tagArray, $responseLanguage, $limit, $offset));
            $totalMemeIds = array_values($searchService->search($query, $tagArray, $responseLanguage, 1000));

            // Get meme details
            $formattedResults = [];
            if (!empty($memeIds)) {
                /** @var Memes[] $memes */
                $memes = Memes::find([
                    'id IN ({ids:array})',
                    'bind' => [
                        'ids' => $memeIds
                    ]
                ]);

                foreach ($memes as $meme) {
                    // Get tags in both languages but prioritize response language
                    $primaryTags = array_column($meme->getTagsByLanguage($responseLanguage === 'ar' ? 'arabic' : 'english')->toArray(), 'tag');
                    $secondaryTags = array_column($meme->getTagsByLanguage($responseLanguage === 'ar' ? 'english' : 'arabic')->toArray(), 'tag');

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

                // Get total count of approved memes
                $totalApprovedCount = Memes::count([
                    'conditions' => 'id IN ({ids:array})',
                    'bind' => [
                        'ids' => $totalMemeIds
                    ]
                ]);

                // Update search log with results count
                $searchLog->results_count = $totalApprovedCount;
                $searchLog->save();
            }

            return $this->response->setJsonContent([
                'status' => 'success',
                'data' => [
                    'total' => $totalApprovedCount ?? 0,
                    'page' => $page,
                    'limit' => $limit,
                    'language' => $responseLanguage,
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
                    'language' => $responseLanguage ?? null
                ]
            );

            return $this->response->setJsonContent([
                'status' => 'error',
                'message' => 'An error occurred while processing your search'
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
