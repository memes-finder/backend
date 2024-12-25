<?php

namespace App\Services\Crawlers\Factory;

use App\Services\Crawlers\Interfaces\CrawlerInterface;
use App\Services\Crawlers\GoogleImagesCrawler;
use App\Services\Crawlers\TenorCrawler;
use InvalidArgumentException;

class CrawlerFactory
{
    public const CRAWLER_GOOGLE = 'google';

    public const CRAWLER_TENOR = 'tenor';
    
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
            case self::CRAWLER_GOOGLE:
                return new GoogleImagesCrawler();
            case self::CRAWLER_TENOR:
                return new TenorCrawler();
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
            self::CRAWLER_TENOR => 'Tenor Crawler',
            self::CRAWLER_GOOGLE => 'Google Images Crawler',
        ];
    }
}
