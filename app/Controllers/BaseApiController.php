<?php

namespace App\Controllers;

use App\Services\RateLimitService;
use Phalcon\Mvc\Controller;

class BaseApiController extends Controller
{
    protected RateLimitService $rateLimiter;

    public function onConstruct()
    {
        $this->rateLimiter = new RateLimitService();

        // Check rate limit before processing request
        $clientIp = $this->request->getClientAddress();
        
        if ($this->rateLimiter->shouldLimit($clientIp)) {
            $this->response
                ->setStatusCode(429, 'Too Many Requests')
                ->setJsonContent([
                    'status' => 'error',
                    'message' => 'Rate limit exceeded. Please try again later.',
                    'retryAfter' => 1 // Retry after 1 second
                ])
                ->setHeader('Retry-After', '1')
                ->send();
            exit();
        }

        // Add rate limit headers
        $remaining = $this->rateLimiter->getRemainingRequests($clientIp);
        $this->response
            ->setHeader('X-RateLimit-Limit', '60')
            ->setHeader('X-RateLimit-Remaining', (string)$remaining)
            ->setHeader('X-RateLimit-Reset', (string)(time() + 1));
    }
}
