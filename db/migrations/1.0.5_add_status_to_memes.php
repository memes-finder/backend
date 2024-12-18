<?php

use Phalcon\Db\Column;
use Phalcon\Db\Index;
use Phalcon\Migrations\Mvc\Model\Migration;

class AddStatusToMemesMigration_105 extends Migration
{
    public function up()
    {
        $this->morphTable('memes', [
            'columns' => [
                new Column(
                    'status',
                    [
                        'type' => Column::TYPE_VARCHAR,
                        'size' => 20,
                        'notNull' => true,
                        'default' => 'pending',
                        'after' => 'file_hash'
                    ]
                )
            ],
            'indexes' => [
                new Index(
                    'idx_status',
                    ['status']
                )
            ]
        ]);

        // Update existing records to approved status
        $this->getConnection()->execute("UPDATE memes SET status = 'approved' WHERE status = 'pending'");
    }

    public function down()
    {
        $this->morphTable('memes', [
            'columns' => [
                'status'
            ]
        ]);
    }
}
