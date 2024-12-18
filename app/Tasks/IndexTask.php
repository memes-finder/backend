<?php

namespace App\Tasks;

use App\Services\SearchService;
use Phalcon\Cli\Task;
use App\Models\Memes;
use App\Models\MemeTags;

class IndexTask extends Task
{
    public function mainAction()
    {
        echo "Starting indexing process...\n";

        /** @var SearchService $searchService */
        $searchService = $this->di->get('searchService');

        $processed = 0;
        $total = MemeTags::count();

        $memeTags = MemeTags::find([
            'order' => 'meme_id ASC'
        ]);

        foreach ($memeTags as $memeTag) {
            try {
                $searchService->indexTag(
                    $memeTag->meme_id,
                    $memeTag->tag,
                    $memeTag->language,
                    $memeTag->confidence,
                    $memeTag->created_at
                );
                
                $processed++;
                if ($processed % 100 === 0) {
                    echo "Processed {$processed} of {$total} tags...\n";
                }
            } catch (\Exception $e) {
                echo "Error indexing tag {$memeTag->id}: " . $e->getMessage() . "\n";
            }
        }

        echo "Indexing complete. Processed {$processed} tags.\n";
    }
}
