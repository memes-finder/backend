<?php

use Phalcon\Db\Column;
use Phalcon\Db\Index;
use Phalcon\Migrations\Mvc\Model\Migration;

class MemeTagsMigration_104 extends Migration
{
    /**
     * Define the table structure
     *
     * @return void
     */
    public function morph()
    {
        $this->morphTable('meme_tags', [
            'columns' => [
                new Column(
                    'origin',
                    [
                        'type' => Column::TYPE_VARCHAR,
                        'size' => 20,
                        'notNull' => true,
                        'default' => 'manual',
                        'after' => 'confidence'
                    ]
                ),
            ],
            'indexes' => [
                new Index('idx_meme_tags_origin', ['origin'])
            ]
        ]);
    }

    /**
     * Reverse the migrations
     *
     * @return void
     */
    public function down()
    {
        $this->getConnection()->dropColumn('meme_tags', null, 'origin');
    }
}
