<?php

use Phalcon\Db\Column;
use Phalcon\Db\Index;
use Phalcon\Migrations\Mvc\Model\Migration;

class AddStatusToMemeTagsMigration_1011 extends Migration
{
    public function up()
    {
        $this->morphTable('meme_tags', [
            'columns' => [
                new Column(
                    'status',
                    [
                        'type' => Column::TYPE_VARCHAR,
                        'size' => 20,
                        'notNull' => true,
                        'default' => 'pending',
                        'after' => 'origin'
                    ]
                )
            ],
            'indexes' => [
                new Index('idx_status', ['status'])
            ]
        ]);

        // Set all existing tags to approved
        $this->getConnection()->execute(
            "UPDATE meme_tags SET status = 'approved' WHERE status = 'pending'"
        );
    }

    public function down()
    {
        $this->morphTable('meme_tags', [
            'columns' => [
                'status'
            ]
        ]);
    }
}
