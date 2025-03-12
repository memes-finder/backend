<?php

namespace App\Tasks;

use App\Models\Memes;
use App\Services\VectorSearchService;
use Phalcon\Cli\Task;

class ReindexVectorsTask extends Task
{
    private const BATCH_SIZE = 50;  
    private VectorSearchService $vectorSearchService;
    
    public function initialize()
    {
        $this->vectorSearchService = new VectorSearchService();
    }
    
    /**
     * Reindex vectors for all memes that need indexing
     */
    public function mainAction()
    {
        $remainingCount = Memes::count([
            'conditions' => 'meme_type = :memeType: AND embedding_status IN ({statuses:array})',
            'bind' => [
                'memeType' => 'video',
                'statuses' => ['pending']
            ]
        ]);
        
        echo "Found {$remainingCount} memes to process\n";
        
        while ($remainingCount > 0) {
            $startTime = microtime(true);
            
            // Get batch of memes
            $memes = Memes::find([
                'conditions' => 'meme_type = :memeType: AND embedding_status IN ({statuses:array})',
                'bind' => [
                    'memeType' => 'video',
                    'statuses' => ['pending']
                ],
                'limit' => self::BATCH_SIZE
            ]);
            
            // Process batch
            $batchData = [];
            foreach ($memes as $meme) {
                // Get tags in both languages
                $englishTags = array_column($meme->getTagsByLanguage('english')->toArray(), 'tag');
                $arabicTags = array_column($meme->getTagsByLanguage('arabic')->toArray(), 'tag');
                
                // Combine tags, removing duplicates
                $allTags = array_unique(array_merge($englishTags, $arabicTags));
                
                $batchData[] = [
                    'id' => $meme->id,
                    'media_url' => $meme->getPublicUrl(),
                    'tags' => $allTags,
                    'caption' => $meme->caption ?? '',
                    'meme_type' => $meme->meme_type
                ];
                
                // Mark as indexed
                $meme->embedding_status = 'indexed';
                $meme->last_indexed_at = date('Y-m-d H:i:s');
                $meme->save();
            }
            
            // Send batch to search service
            if (!empty($batchData)) {
                $this->vectorSearchService->batchIndexMemes($batchData);
            }
            
            $remainingCount = Memes::count([
                'conditions' => 'embedding_status IN ({statuses:array})',
                'bind' => [
                    'statuses' => ['pending']
                ]
            ]);
            
            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);
            
            echo "Processed " . count($batchData) . " memes in {$duration}s. {$remainingCount} remaining.\n";
        }
        
        echo "All memes processed.\n";
    }
}
