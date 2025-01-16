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

        /** @var MemeTags[] $memeTags */
        $memeTags = MemeTags::find([
            'order' => 'meme_id ASC'
        ]);

        $tags = [];
        $currentMemeId = null;
        foreach ($memeTags as $memeTag) {
            if ($memeTag->meme_id !== $currentMemeId) {
                if ($currentMemeId !== null) {
                    // Group tags by language
                    $tagsByLang = [];
                    foreach ($tags as $tag) {
                        $tagsByLang[$tag->language][] = $tag->tag;
                    }

                    // Index each language group
                    foreach ($tagsByLang as $lang => $langTags) {
                        $searchService->indexTags(
                            $currentMemeId,
                            $langTags,
                            $lang,
                            $memeTag->meme->meme_type,
                            true // Replace existing documents
                        );
                    }

                    $processed += count($tags);
                    if ($processed % 100 === 0) {
                        echo "Processed {$processed} of {$total} tags...\n";
                    }
                }

                $tags = [];
                $currentMemeId = $memeTag->meme_id;
            }

            $tags[] = $memeTag;
        }

        // Process the last set of tags
        if (!empty($tags)) {
            // Group tags by language
            $tagsByLang = [];
            foreach ($tags as $tag) {
                $tagsByLang[$tag->language][] = $tag->tag;
            }

            // Index each language group
            foreach ($tagsByLang as $lang => $langTags) {
                $searchService->indexTags(
                    $currentMemeId,
                    $langTags,
                    $lang,
                    $memeTags[count($memeTags) - 1]->meme->meme_type,
                    true // Replace existing documents
                );
            }

            $processed += count($tags);
        }

        echo "Indexing complete. Processed {$processed} tags.\n";
    }
}
