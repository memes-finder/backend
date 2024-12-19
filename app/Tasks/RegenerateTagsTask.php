<?php

namespace App\Tasks;

use App\Models\Memes;
use App\Models\MemeTags;
use Phalcon\Cli\Task;

class RegenerateTagsTask extends Task
{
    public function mainAction()
    {
        $this->println("Starting AI tag regeneration...");

        // Get all memes
        $memes = Memes::find([
            'conditions' => 'status = :status:',
            'bind' => ['status' => Memes::STATUS_APPROVED]
        ]);

        $total = count($memes);
        $this->println("Found {$total} memes to process");

        /** @var \App\Services\AITaggingService $aiService */
        $aiService = $this->getDI()->get('aiTaggingService');

        $processed = 0;
        $errors = 0;

        foreach ($memes as $meme) {
            try {
                $processed++;
                $this->println("Processing meme {$meme->id} ({$processed}/{$total})");

                // Delete existing AI-generated tags
                MemeTags::find([
                    'conditions' => 'meme_id = :meme_id:',
                    'bind' => [
                        'meme_id' => $meme->id
                    ]
                ])->delete();

                // Generate new tags
                $tags = $aiService->generateTags($meme->getPublicUrl());
                $now = date('Y-m-d H:i:s');

                // Save generated tags
                foreach ($tags as $language => $langTags) {
                    foreach ($langTags as $tag) {
                        $memeTag = new MemeTags();
                        $memeTag->meme_id = $meme->id;
                        $memeTag->tag = $tag;
                        $memeTag->language = $language === 'ar' ? 'arabic' : 'english';
                        $memeTag->confidence = 0.9; // AI tags get high confidence
                        $memeTag->created_at = $now;

                        if ($memeTag->save()) {
                            // Index the new tag
                            try {
                                $this->getDI()->get('searchService')->indexTag(
                                    $memeTag->meme_id,
                                    $memeTag->tag,
                                    $memeTag->language,
                                    $memeTag->confidence,
                                    $memeTag->created_at
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
