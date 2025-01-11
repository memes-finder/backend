<?php

namespace App\Tasks;

use App\Models\Memes;
use App\Models\MemeTags;
use App\Services\TaggingServices\TaggingServiceFactory;
use App\Services\TaggingServices\TaggingServiceInterface;
use Phalcon\Cli\Task;

class RegenerateTagsTask extends Task
{
    public function mainAction($taggingService)
    {
        $this->println("Starting {$taggingService} AI tag regeneration...");

        /**
         * Get memes that don't have AI-generated tags
         * @var $memes Memes[]
         */
        $memes = Memes::query()->conditions(
            'id NOT IN (
                SELECT meme_id
                FROM \App\Models\MemeTags
                GROUP BY meme_id
                HAVING COUNT(DISTINCT \App\Models\MemeTags.id) > 5 AND COUNT(\App\Models\MemeTags.language = \'arabic\') > 5
            )
            AND status = :status:')
            ->bind([
                'status' => Memes::STATUS_APPROVED
            ])
            ->execute();


        $total = count($memes);
        $this->println("Found {$total} memes without AI tags to process");

        $aiService = TaggingServiceFactory::create($taggingService);

        $processed = 0;
        $errors = 0;

        foreach ($memes as $meme) {
            try {
                $processed++;
                $this->println("Processing meme $meme->id ($processed/$total)");

                // Generate new tags
                if ($meme->meme_type == 'video') {
                    $image = $meme->getThumbnailUrl();
                } else {
                    $image = $meme->getPublicUrl();
                }

                $tags = $aiService->generateTags($image);

                $now = date('Y-m-d H:i:s');

                // Save generated tags
                foreach ($tags as $language => $langTags) {
                    foreach ($langTags as $tag) {
                        $memeTag = new MemeTags();
                        $memeTag->meme_id = $meme->id;
                        $memeTag->tag = $tag;
                        $memeTag->language = $language === 'ar' ? 'arabic' : 'english';
                        $memeTag->confidence = 0.9; // AI tags get high confidence
                        $memeTag->origin = $aiService->getOrigin();
                        $memeTag->created_at = $now;

                        if ($memeTag->save()) {
                            // Index the new tag
                            try {
                                $this->getDI()->get('searchService')->indexTag(
                                    $memeTag->meme_id,
                                    $memeTag->tag,
                                    $memeTag->language,
                                    $memeTag->confidence,
                                    $meme->meme_type
                                );
                            } catch (\Exception $e) {
                                $this->println("Warning: Error indexing tag for meme {$meme->id}: " . $e->getMessage());
                            }
                        }
                    }
                }

                $this->println("Successfully processed meme {$meme->id}");

                // Add a small delay to avoid rate limiting
                usleep(500000); // 0.5 second delay
            } catch (\Exception $e) {
                $errors++;
                $this->println("Error processing meme {$meme->id}: " . $e->getMessage());
            }
        }

        $this->println("\nTag regeneration completed:");
        $this->println("Total memes processed: {$processed}");
        $this->println("Errors encountered: {$errors}");
    }

    private function println(string $message)
    {
        echo $message . PHP_EOL;
    }
}
