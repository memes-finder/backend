<?php

namespace App\Services;

use Phalcon\Di\Injectable;
use Redis;

class RateLimiter extends Injectable
{
    private const RATE_LIMIT = 60; // requests per second
    private const WINDOW_SIZE = 1; // window size in seconds
    private Redis $redis;

    public function __construct()
    {
        $this->redis = $this->di->get('rateLimiter');
    }

    /**
     * Check if request should be rate limited
     * @param string $identifier Client identifier (e.g. IP address)
     * @return bool True if request should be allowed, false if it should be rate limited
     * @throws \RedisException
     */
    public function shouldLimit(string $identifier): bool
    {
        $key = "rate_limit:{$identifier}";
        $now = time();
        $windowStart = $now - self::WINDOW_SIZE;

        // Start a Redis transaction
        $this->redis->multi();

        // Remove old requests
        $this->redis->zRemRangeByScore($key, 0, $windowStart);

        // Count requests in current window
        $this->redis->zCount($key, $windowStart, $now);

        // Add current request
        $this->redis->zAdd($key, $now, uniqid());

        // Set expiry on the key
        $this->redis->expire($key, 60);

        // Execute transaction
        $results = $this->redis->exec();

        // Get the count from results (index 1 because zCount was the second command)
        $requestCount = $results[1];

        return $requestCount >= self::RATE_LIMIT;
    }

    /**
     * Get remaining requests for the current window
     * @param string $identifier Client identifier
     * @return int Number of remaining requests
     */
    public function getRemainingRequests(string $identifier): int
    {
        $key = "rate_limit:{$identifier}";
        $now = time();
        $windowStart = $now - self::WINDOW_SIZE;

        // Remove old requests first
        $this->redis->zRemRangeByScore($key, 0, $windowStart);

        // Count current requests
        $currentRequests = $this->redis->zCount($key, $windowStart, $now);

        return max(0, self::RATE_LIMIT - $currentRequests);
    }
}
