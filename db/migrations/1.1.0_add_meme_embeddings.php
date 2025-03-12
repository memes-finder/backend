<?php

use Phalcon\Db\Column;
use Phalcon\Db\Index;
use Phalcon\Migrations\Mvc\Model\Migration;

class AddMemeEmbeddingsMigration_110 extends Migration
{
    public function up()
    {
        $this->morphTable('memes', [
            'columns' => [
                new Column(
                    'embedding_status',
                    [
                        'type' => Column::TYPE_VARCHAR,
                        'size' => 20,
                        'notNull' => true,
                        'default' => 'pending',
                        'after' => 'thumbnail'
                    ]
                ),
                new Column(
                    'last_indexed_at',
                    [
                        'type' => Column::TYPE_DATETIME,
                        'notNull' => false,
                        'after' => 'embedding_status'
                    ]
                ),
                new Column(
                    'search_score',
                    [
                        'type' => Column::TYPE_DECIMAL,
                        'size' => 10,
                        'scale' => 4,
                        'notNull' => false,
                        'after' => 'last_indexed_at'
                    ]
                )
            ],
            'indexes' => [
                new Index(
                    'idx_memes_embedding_status',
                    ['embedding_status']
                )
            ]
        ]);
    }

    public function down()
    {
        $this->morphTable('memes', [
            'columns' => [
                'embedding_status',
                'last_indexed_at',
                'search_score'
            ]
        ]);
    }
}
