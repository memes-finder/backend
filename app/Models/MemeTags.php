<?php

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Mvc\Model\Relation;

/**
 * @property Memes $meme
 */
class MemeTags extends Model
{
    public const ORIGIN_MANUAL = 'manual';
    public const ORIGIN_OPENAI = 'openai';
    public const ORIGIN_GOOGLE_VISION = 'google_vision';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

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
    public $status;

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
                'reusable' => true,
                'foreignKey' => [
                    'allowNulls' => false,
                    'action' => Relation::ACTION_CASCADE
                ]
            ]
        );
    }

    /**
     * Get associated meme
     * @return Memes|null
     */
    public function getMeme()
    {
        return $this->getRelated('meme', [
            'conditions' => 'status = :status:',
            'bind' => ['status' => 'approved']
        ]);
    }

    /**
     * Before create
     */
    public function beforeCreate()
    {
        // Set default status based on origin
        if (empty($this->status)) {
            $this->status = $this->origin === self::ORIGIN_MANUAL ? 
                self::STATUS_PENDING : 
                self::STATUS_APPROVED;
        }

        if (empty($this->created_at)) {
            $this->created_at = date('Y-m-d H:i:s');
        }
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
            'created_at' => $this->created_at,
            'status' => $this->status
        ];
    }
}
