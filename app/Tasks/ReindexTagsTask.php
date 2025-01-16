<?php

namespace App\Tasks;

use App\Models\Memes;
use App\Models\MemeTags;
use App\Models\MemeTags as Tags;
use App\Services\SearchService;
use Phalcon\Cli\Task;

class ReindexTagsTask extends Task
{
    public function mainAction()
    {
        /** @var SearchService $searchService */
        $searchService = $this->getDI()->get('searchService');

        // Drop existing index but keep documents
        try {
            $this->dropIndex($searchService);
            $this->println("Dropped existing index (keeping documents)");
        } catch (\Exception $e) {
            $this->println("No existing index found or error dropping index: " . $e->getMessage());
        }

        // Get all memes
        /** @var Memes[] $memes */
        $memes = Memes::find();

        $total = count($memes);
        $indexed = 0;
        $errors = 0;

        $this->println("Found {$total} memes to reindex");

        foreach ($memes as $meme) {
            try {
                // Get all tags for this meme
                $tags = $meme->getTags();
                if ($tags->count() === 0) {
                    continue;
                }

                // Group tags by language
                $tagsByLang = [];
                foreach ($tags as $tag) {
                    $tagsByLang[$tag->language][] = $tag->tag;
                }

                // Index each language group
                foreach ($tagsByLang as $lang => $langTags) {
                    $searchService->indexTags(
                        $meme->id,
                        $langTags,
                        $lang,
                        $meme->meme_type,
                        true // Replace existing documents
                    );
                }

                $indexed++;

                if ($indexed % 100 === 0) {
                    $this->println("Indexed {$indexed}/{$total} memes");
                }
            } catch (\Exception $e) {
                $errors++;
                $this->println("Error indexing meme {$meme->id}: " . $e->getPrevious()->getMessage());
            }
        }

        $this->println("Reindexing complete!");
        $this->println("Successfully indexed: {$indexed}");
        $this->println("Errors: {$errors}");
    }

    private function dropIndex(SearchService $searchService)
    {
        $redis = $this->getDI()->getCache()['instance'];
        $indexName = $searchService->indexName;
        $redis->rawCommand('FT.DROPINDEX', $indexName);
    }

    private function println(string $message)
    {
        echo $message . PHP_EOL;
    }
}
