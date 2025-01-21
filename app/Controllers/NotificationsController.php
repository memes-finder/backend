<?php

namespace App\Controllers;

use App\Models\Notifications;
use Phalcon\Http\ResponseInterface;

class NotificationsController extends BaseApiController
{
    /**
     * Get active notifications
     * 
     * @param int $page Page number (optional)
     * @param int $limit Items per page (optional)
     * @return ResponseInterface
     */
    public function getAction(): ResponseInterface
    {
        try {
            $page = $this->request->getQuery('page', 'int', 0);
            $limit = $this->request->getQuery('limit', 'int', 20);
            $offset = $page * $limit;

            // Get total count
            $total = Notifications::count([
                'conditions' => 'status = :status:',
                'bind' => ['status' => Notifications::STATUS_ACTIVE]
            ]);

            // Get notifications
            $notifications = Notifications::find([
                'conditions' => 'status = :status:',
                'bind' => ['status' => Notifications::STATUS_ACTIVE],
                'order' => 'sort_order ASC, created_at DESC',
                'limit' => $limit,
                'offset' => $offset
            ]);

            $items = array_map(function($notification) {
                return [
                    'id' => $notification['id'],
                    'title' => $notification['title'],
                    'text' => $notification['text'],
                    'color' => $notification['color'],
                    'sort_order' => $notification['sort_order'],
                    'created_at' => date('M d, Y', strtotime($notification['created_at']))
                ];
            }, $notifications->toArray());

            return $this->response->setJsonContent([
                'status' => 'success',
                'data' => [
                    'items' => $items,
                    'pagination' => [
                        'total' => $total,
                        'page' => $page,
                        'limit' => $limit,
                        'total_pages' => ceil($total / $limit)
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to get notifications: ' . $e->getMessage(), [
                'exception' => $e
            ]);

            return $this->response->setJsonContent([
                'status' => 'error',
                'message' => 'Failed to get notifications'
            ])->setStatusCode(500);
        }
    }
}
