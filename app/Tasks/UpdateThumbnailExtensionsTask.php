<?php

namespace App\Tasks;

use App\Models\Memes;
use Phalcon\Cli\Task;

class UpdateThumbnailExtensionsTask extends Task
{
    public function mainAction()
    {
        echo "Starting thumbnail extension update...\n";

        // Get all memes with thumbnails that end in .jpg
        $memes = Memes::find([
            "conditions" => "thumbnail LIKE '%/thumbnail.jpg'",
        ]);

        $updatedCount = 0;

        foreach ($memes as $meme) {
            if ($meme->thumbnail) {
                // Replace .jpg with .jpeg at the end of the thumbnail path
                $meme->thumbnail = preg_replace('/\/thumbnail\.jpg$/', '/thumbnail.jpeg', $meme->thumbnail);
                
                if ($meme->save()) {
                    $updatedCount++;
                    echo "Updated meme ID {$meme->id}: {$meme->thumbnail}\n";
                } else {
                    echo "Failed to update meme ID {$meme->id}\n";
                    if ($meme->getMessages()) {
                        foreach ($meme->getMessages() as $message) {
                            echo " - " . $message . "\n";
                        }
                    }
                }
            }
        }

        echo "\nUpdate complete. Updated $updatedCount memes.\n";
    }
}
