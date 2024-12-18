<?php

namespace App\Models;

use Phalcon\Mvc\Model;

class MemeTags extends Model
{
    public $id;
    public $meme_id;
    public $tag;
    public $language;
    public $confidence;
    public $created_at;

    public function initialize()
    {
        $this->belongsTo(
            'meme_id',
            Memes::class,
            'id',
            [
                'alias' => 'meme',
                'reusable' => true
            ]
        );
    }

    /**
     * Convert tag to array
     * @return array
     */
    public function toArray($columns = null): array
    {
        return [
            'id' => $this->id,
            'meme_id' => $this->meme_id,
            'tag' => $this->tag,
            'language' => $this->language,
            'confidence' => $this->confidence,
            'created_at' => $this->created_at
        ];
    }
}
