<?php

use Phalcon\Db\Column;
use Phalcon\Db\Index;
use Phalcon\Migrations\Mvc\Model\Migration;

class AddBlurHashMigration_1010 extends Migration
{
    public function up()
    {
        $this->morphTable('memes', [
            'columns' => [
                new Column(
                    'blur_hash',
                    [
                        'type' => Column::TYPE_VARCHAR,
                        'size' => 100,
                        'notNull' => false,
                        'after' => 'file_hash'
                    ]
                ),
                new Column(
                    'thumbnail_blur_hash',
                    [
                        'type' => Column::TYPE_VARCHAR,
                        'size' => 100,
                        'notNull' => false,
                        'after' => 'thumbnail'
                    ]
                )
            ]
        ]);
    }

    public function down()
    {
        $this->morphTable('memes', [
            'columns' => [
                'blur_hash',
                'thumbnail_blur_hash'
            ]
        ]);
    }
}
