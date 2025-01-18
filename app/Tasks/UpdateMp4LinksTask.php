<?php

namespace App\Tasks;

use Phalcon\Cli\Task;
use App\Models\Memes;

class UpdateMp4LinksTask extends Task
{
    public function mainAction()
    {
        $this->stdout("Starting to update MP4 links...\n");

        // Get all video memes
        $memes = Memes::find([
            'conditions' => 'meme_type = :type:',
            'bind' => [
                'type' => 'video'
            ]
        ]);

        $count = 0;
        foreach ($memes as $meme) {
            if (!$meme->file_path) {
                continue;
            }

            // Replace .m3u8 with .mp4 in the file path
            $mp4Path = preg_replace('/\.m3u8$/', '.mp4', $meme->file_path);
            
            // Update the meme
            $meme->mp4_link = $mp4Path;
            if ($meme->save()) {
                $count++;
                $this->stdout("Updated meme #{$meme->id}: {$mp4Path}\n");
            } else {
                $errors = $meme->getMessages();
                $this->stdout("Failed to update meme #{$meme->id}: " . implode(', ', $errors) . "\n");
            }
        }

        $this->stdout("\nCompleted! Updated {$count} memes.\n");
    }

    private function stdout(string $message)
    {
        echo $message;
    }
}
