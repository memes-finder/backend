<?php

use Phalcon\Db\Column;
use Phalcon\Db\Exception;
use Phalcon\Db\Index;
use Phalcon\Db\Reference;
use Phalcon\Migrations\Migration;

class AddSourceUrlHashToMemesMigration_106 extends Migration
{
    public function up(): void
    {
        $this->morphTable('memes', [
            'columns' => [
                new Column(
                    'source_url_hash',
                    [
                        'type' => Column::TYPE_CHAR,
                        'size' => 32,
                        'notNull' => true,
                        'after' => 'source_url'
                    ]
                )
            ],
            'indexes' => [
                new Index('idx_source_url_hash_unique', ['source_url_hash'], 'UNIQUE')
            ]
        ]);

        // Update existing records
        $this->getConnection()->execute(
            'UPDATE memes SET source_url_hash = MD5(source_url) WHERE source_url IS NOT NULL'
        );
    }

    public function down(): void
    {
        $this->morphTable('memes', [
            'columns' => [
                'source_url_hash'
            ],
            'indexes' => [
                'idx_source_url_hash_unique'
            ],
            'columns_drop' => true,
            'indexes_drop' => true
        ]);
    }
}
