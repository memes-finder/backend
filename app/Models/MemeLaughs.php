<?php

namespace App\Models;

use Phalcon\Mvc\Model;

class MemeLaughs extends Model
{
    /**
     * @var int
     */
    public $id;

    /**
     * @var int
     */
    public $meme_id;

    /**
     * @var string
     */
    public $ip_address;

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
    }
}
