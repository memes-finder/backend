<?php

use Phalcon\Db\Column;
use Phalcon\Db\Index;
use Phalcon\Migrations\Mvc\Model\Migration;

class CreateCrawlerStatesTable_104 extends Migration
{
    public function up()
    {
        $this->morphTable('crawler_states', [
            'columns' => [
                new Column('id', [
                    'type' => Column::TYPE_INTEGER,
                    'notNull' => true,
                    'autoIncrement' => true,
                    'primary' => true,
                    'size' => 11
                ]),
                new Column('language', [
                    'type' => Column::TYPE_VARCHAR,
                    'notNull' => true,
                    'size' => 10
                ]),
                new Column('query', [
                    'type' => Column::TYPE_VARCHAR,
                    'notNull' => true,
                    'size' => 255
                ]),
                new Column('last_page', [
                    'type' => Column::TYPE_INTEGER,
                    'notNull' => true,
                    'default' => 0,
                    'size' => 11
                ]),
                new Column('completed', [
                    'type' => Column::TYPE_INTEGER,
                    'notNull' => true,
                    'default' => 0,
                    'size' => 1
                ]),
                new Column('created_at', [
                    'type' => Column::TYPE_TIMESTAMP,
                    'notNull' => true,
                    'default' => 'CURRENT_TIMESTAMP'
                ]),
                new Column('updated_at', [
                    'type' => Column::TYPE_TIMESTAMP,
                    'notNull' => true,
                    'default' => 'CURRENT_TIMESTAMP'
                ])
            ],
            'indexes' => [
                new Index('language_query_idx', ['language', 'query']),
                new Index('created_at_idx', ['created_at'])
            ]
        ]);
    }

    public function down()
    {
        $this->getConnection()->dropTable('crawler_states');
    }
}
