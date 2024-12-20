<?php

namespace App\Services\Crawlers\Interfaces;

interface CrawlerInterface
{
    /**
     * Execute the crawler
     * @return bool True if successful, False if there were any errors
     */
    public function crawl(): void;
}
