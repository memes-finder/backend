<?php

namespace App\Tasks;

use App\Models\Memes;
use App\Models\MemeTags as Tags;
use App\Services\SearchService;
use Phalcon\Cli\Task;

class ReindexTagsTask extends Task
{
    public function mainAction()
    {
        $searchService = $this->getDI()->get('searchService');

        // Drop existing index but keep documents
        try {
            $this->dropIndex($searchService);
            $this->println("Dropped existing index (keeping documents)");
        } catch (\Exception $e) {
            $this->println("No existing index found or error dropping index: " . $e->getMessage());
        }

        // Get all tags
        $tags = Tags::find();

        $total = count($tags);
        $indexed = 0;
        $errors = 0;

        $this->println("Found {$total} tags to reindex");

        foreach ($tags as $tag) {
            try {
                $searchService->indexTag(
                    $tag->meme_id,
                    $tag->tag,
                    $tag->language,
                    $tag->confidence ?? 1.0,
                    $tag->created_at
                );
                $indexed++;

                if ($indexed % 100 === 0) {
                    $this->println("Indexed {$indexed}/{$total} tags");
                }
            } catch (\Exception $e) {
                $errors++;
                $this->println("Error indexing tag {$tag->id}: " . $e->getPrevious()->getMessage());
            }
        }

        $this->println("Reindexing complete!");
        $this->println("Successfully indexed: {$indexed}");
        $this->println("Errors: {$errors}");
    }

    private function dropIndex(SearchService $searchService)
    {
        $redis = $this->getDI()->get('redis');
        $indexName = $searchService->indexName;
        $redis->rawCommand('FT.DROPINDEX', $indexName);
    }

    private function println(string $message)
    {
        echo $message . PHP_EOL;
    }
}
