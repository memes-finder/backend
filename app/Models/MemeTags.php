<?php

namespace App\Models;

use Phalcon\Mvc\Model;

/**
 * @property Memes $meme
 */
class MemeTags extends Model
{
    public const ORIGIN_MANUAL = 'manual';
    public const ORIGIN_OPENAI = 'openai';
    public const ORIGIN_GOOGLE_VISION = 'google_vision';

    /**
     * @var integer
     */
    public $id;

    /**
     * @var integer
     */
    public $meme_id;

    /**
     * @var string
     */
    public $tag;

    /**
     * @var string
     */
    public $language;

    /**
     * @var float
     */
    public $confidence;

    /**
     * @var string
     */
    public $origin;

    /**
     * @var string
     */
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

        // Set default values
        $this->origin = self::ORIGIN_MANUAL;
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
