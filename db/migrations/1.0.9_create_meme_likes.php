<?php

use Phalcon\Db\Column;
use Phalcon\Db\Index;
use Phalcon\Migrations\Mvc\Model\Migration;

class CreateMemeLikesMigration_109 extends Migration
{
    public function up()
    {
        $this->morphTable('meme_likes', [
            'columns' => [
                new Column(
                    'id',
                    [
                        'type' => Column::TYPE_BIGINTEGER,
                        'notNull' => true,
                        'autoIncrement' => true,
                        'primary' => true,
                        'size' => 20
                    ]
                ),
                new Column(
                    'meme_id',
                    [
                        'type' => Column::TYPE_INTEGER,
                        'notNull' => true,
                        'size' => 11
                    ]
                ),
                new Column(
                    'ip_address',
                    [
                        'type' => Column::TYPE_VARCHAR,
                        'notNull' => true,
                        'size' => 45
                    ]
                ),
                new Column(
                    'created_at',
                    [
                        'type' => Column::TYPE_TIMESTAMP,
                        'notNull' => true,
                        'default' => 'CURRENT_TIMESTAMP',
                    ]
                )
            ],
            'indexes' => [
                new Index(
                    'meme_likes_meme_id_idx',
                    ['meme_id']
                ),
                new Index(
                    'meme_likes_ip_meme_unique_idx',
                    ['ip_address', 'meme_id'],
                    'UNIQUE'
                )
            ],
            'references' => [
                new \Phalcon\Db\Reference(
                    'meme_likes_meme_fk',
                    [
                        'referencedSchema' => null,
                        'referencedTable' => 'memes',
                        'columns' => ['meme_id'],
                        'referencedColumns' => ['id'],
                        'onUpdate' => 'NO ACTION',
                        'onDelete' => 'CASCADE'
                    ]
                )
            ]
        ]);
    }

    public function down()
    {
        $this->getConnection()->dropTable('meme_likes');
    }
}
