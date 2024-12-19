<?php

namespace App\Middleware;

use Phalcon\Di\Injectable;
use Phalcon\Http\Response;

class AdminAuthMiddleware extends Injectable
{
    public function beforeExecuteRoute()
    {
        // Skip auth for login page
        if ($this->dispatcher->getControllerName() === 'admin' && 
            $this->dispatcher->getActionName() === 'login') {
            return true;
        }

        // Check if request has valid token in session
        $isLoggedIn = $this->session->has('admin_token') && 
            $this->session->get('admin_token') === getenv('ADMIN_TOKEN');

        if (!$isLoggedIn) {
            $envToken = getenv('ADMIN_TOKEN');
            if (!$envToken) {
                $this->logger->error('ADMIN_TOKEN not set in environment');
            } else {
                $this->logger->warning('Invalid admin session attempt', [
                    'ip' => $this->request->getClientAddress()
                ]);
            }
            
            if ($this->request->isAjax()) {
                $response = new Response();
                $response->setStatusCode(401, 'Unauthorized');
                $response->setJsonContent(['error' => 'Unauthorized']);
                return $response->send();
            }
            
            return $this->response->redirect('/admin/login');
        }

        return true;
    }
}
