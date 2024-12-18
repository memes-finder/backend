<?php

namespace App\Tasks;

use App\Services\Crawlers\GoogleImagesCrawler;
use Phalcon\Cli\Task;

class CrawlerTask extends Task
{
    private function log(string $message): void 
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}" . PHP_EOL;
        
        // Get log path from config
        $config = $this->getDI()->get('config');
        $logPath = $config->application->logPath;
        
        // Ensure log directory exists
        $logDir = dirname($logPath);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        
        // Append to log file
        file_put_contents($logPath, $logMessage, FILE_APPEND);
        
        // Also output to console
        echo $logMessage;
    }

    /**
     * Main task action to run the crawler
     */
    public function mainAction()
    {
        try {
            $this->log('Starting crawler task');
            
            // Initialize and run Google Images crawler
            $crawler = new GoogleImagesCrawler();
            $crawler->crawl();
            
            $this->log('Crawler task completed successfully');
            return 0;
        } catch (\Exception $e) {
            $this->log('Error: Crawler task failed: ' . $e->getMessage());
            return 1;
        }
    }
}
