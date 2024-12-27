<?php

use Phalcon\Db\Column;
use Phalcon\Db\Index;
use Phalcon\Migrations\Mvc\Model\Migration;

class CreateQueuedUploadsMigration_108 extends Migration
{
    public function up()
    {
        $this->morphTable('queued_uploads', [
            'columns' => [
                new Column('id', [
                    'type' => Column::TYPE_INTEGER,
                    'notNull' => true,
                    'autoIncrement' => true,
                    'primary' => true,
                    'size' => 11
                ]),
                new Column('original_name', [
                    'type' => Column::TYPE_VARCHAR,
                    'notNull' => true,
                    'size' => 255
                ]),
                new Column('extract_path', [
                    'type' => Column::TYPE_VARCHAR,
                    'notNull' => true,
                    'size' => 255
                ]),
                new Column('status', [
                    'type' => Column::TYPE_VARCHAR,
                    'notNull' => true,
                    'size' => 20
                ]),
                new Column('processed_count', [
                    'type' => Column::TYPE_INTEGER,
                    'notNull' => false,
                    'size' => 11,
                    'default' => 0
                ]),
                new Column('error_count', [
                    'type' => Column::TYPE_INTEGER,
                    'notNull' => false,
                    'size' => 11,
                    'default' => 0
                ]),
                new Column('error_message', [
                    'type' => Column::TYPE_TEXT,
                    'notNull' => false
                ]),
                new Column('created_at', [
                    'type' => Column::TYPE_DATETIME,
                    'notNull' => true
                ]),
                new Column('completed_at', [
                    'type' => Column::TYPE_DATETIME,
                    'notNull' => false
                ])
            ],
            'indexes' => [
                new Index('status_idx', ['status']),
                new Index('created_at_idx', ['created_at'])
            ]
        ]);
    }

    public function down()
    {
        $this->getConnection()->dropTable('queued_uploads');
    }
}
