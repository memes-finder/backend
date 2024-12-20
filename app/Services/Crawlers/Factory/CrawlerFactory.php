<?php

namespace App\Services\Crawlers\Factory;

use App\Services\Crawlers\Interfaces\CrawlerInterface;
use App\Services\Crawlers\FacebookCrawler;
use App\Services\Crawlers\GoogleImagesCrawler;
use InvalidArgumentException;

class CrawlerFactory
{
    public const CRAWLER_FACEBOOK = 'facebook';
    public const CRAWLER_GOOGLE = 'google';
    
    /**
     * Create a new crawler instance
     * @param string $type
     * @param array $config Optional configuration for the crawler
     * @return CrawlerInterface
     * @throws InvalidArgumentException
     */
    public static function create(string $type): CrawlerInterface
    {
        switch ($type) {
            case self::CRAWLER_FACEBOOK:
                return new FacebookCrawler();
            case self::CRAWLER_GOOGLE:
                return new GoogleImagesCrawler();
            default:
                throw new InvalidArgumentException("Invalid crawler type: {$type}");
        }
    }
    
    /**
     * Get list of available crawler types
     * @return array
     */
    public static function getAvailableTypes(): array
    {
        return [
            self::CRAWLER_FACEBOOK => 'Facebook Crawler',
            self::CRAWLER_GOOGLE => 'Google Images Crawler',
        ];
    }
}
