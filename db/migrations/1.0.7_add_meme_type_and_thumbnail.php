<?php

use Phalcon\Db\Column;
use Phalcon\Db\Index;
use Phalcon\Migrations\Mvc\Model\Migration;

class AddMemeTypeAndThumbnailMigration_107 extends Migration
{
    public function up()
    {
        $this->morphTable('memes', [
            'columns' => [
                new Column(
                    'meme_type',
                    [
                        'type' => Column::TYPE_VARCHAR,
                        'size' => 10,
                        'notNull' => true,
                        'default' => 'image',
                        'after' => 'mime_type'
                    ]
                ),
                new Column(
                    'thumbnail',
                    [
                        'type' => Column::TYPE_VARCHAR,
                        'size' => 255,
                        'notNull' => false,
                        'after' => 'meme_type'
                    ]
                )
            ]
        ]);
    }

    public function down()
    {
        $this->morphTable('memes', [
            'columns' => [
                'meme_type',
                'thumbnail'
            ],
            'indexes' => []
        ]);
    }
}
