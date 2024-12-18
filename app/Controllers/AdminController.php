<?php

namespace App\Controllers;

use App\Models\Memes;
use App\Models\MemeTags;

class AdminController extends ControllerBase
{
    public function initialize()
    {
        parent::initialize();
        // Add admin layout
        $this->view->setLayout('admin');
        $this->view->title = 'Admin Panel';
        $this->view->flash = $this->flash;
    }

    public function indexAction()
    {
        $currentPage = $this->request->getQuery('page', 'int', 1);
        $status = $this->request->getQuery('status', 'string', '');
        $searchQuery = $this->request->getQuery('q', 'string', '');
        $limit = 20;

        // Build query conditions
        $conditions = [];
        $binds = [];
        $parameters = [];
        
        if ($status) {
            $conditions[] = 'status = :status:';
            $binds['status'] = $status;
        }

        // If search query exists, get meme IDs from search service
        if ($searchQuery) {
            try {
                /** @var \App\Services\SearchService $searchService */
                $searchService = $this->di->get('searchService');
                $memeIds = $searchService->search($searchQuery, null, 1000, 0); // Get more results for pagination
                
                if (!empty($memeIds)) {
                    $conditions[] = 'id IN ({meme_ids:array})';
                    $binds['meme_ids'] = $memeIds;
                } else {
                    // No results found
                    $conditions[] = 'id = 0'; // Force no results
                }
            } catch (\Exception $e) {
                $this->flash->error("Search error: " . $e->getMessage());
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
                $this->flash->success('Meme updated successfully');
            } else {
                $messages = [];
                foreach ($meme->getMessages() as $message) {
                    $messages[] = $message->getMessage();
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
            /** @var \App\Services\AITaggingService $aiService */
            $aiService = $this->di->get('aiTaggingService');
            
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
}
