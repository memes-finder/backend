<?php

namespace App\Tasks;

use Phalcon\Cli\Task;
use App\Models\MemeTags;

class DetectTagLanguagesTask extends Task
{
    public function mainAction()
    {
        $this->println("Starting tag language detection...");
        
        try {
            // Get tags without language set
            $tags = MemeTags::find([
                'order' => 'id DESC'
            ]);

            if (count($tags) === 0) {
                $this->println("No tags found without language.");
                return;
            }

            $this->println(sprintf("Found %d tags to process", count($tags)));
            $processed = 0;
            $errors = 0;

            foreach ($tags as $tag) {
                try {
                    $language = $this->detectLanguage($tag->tag);
                    
                    if ($language) {
                        $tag->language = $language;
                        if ($tag->save()) {
                            $processed++;
                            if ($processed % 100 === 0) {
                                $this->println(sprintf("Processed %d tags...", $processed));
                            }
                        } else {
                            $errors++;
                            $this->println(sprintf("Failed to save tag %d: %s", 
                                $tag->id, 
                                implode(', ', $tag->getMessages())
                            ));
                        }
                    } else {
                        $errors++;
                        $this->println(sprintf("Failed to save tag %d: %s",
                            $tag->id,
                            implode(', ', $tag->getMessages())
                        ));
                    }
                } catch (\Exception $e) {
                    $errors++;
                    $this->println(sprintf("Error processing tag %d: %s", 
                        $tag->id, 
                        $e->getMessage()
                    ));
                }
            }

            $this->println(sprintf(
                "Completed! Processed: %d, Errors: %d", 
                $processed, 
                $errors
            ));

        } catch (\Exception $e) {
            $this->println("Error: " . $e->getMessage());
            $this->println("Stack trace:");
            $this->println($e->getTraceAsString());
        }
    }

    private function detectLanguage($text)
    {
        // Clean the text
        $text = trim($text);

        if (empty($text)) {
            return null;
        }

        if (preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}]/u', $text)) {
            return 'arabic';
        } else {
            return 'english';
        }
    }

    private function println($message)
    {
        echo $message . PHP_EOL;
    }
}
