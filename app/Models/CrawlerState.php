<?php

namespace App\Models;

use Phalcon\Mvc\Model;

class CrawlerState extends Model
{
    public $id;
    public $language;
    public $query;
    public $last_page;
    public $completed;
    public $created_at;
    public $updated_at;

    public function initialize()
    {
        $this->setSource('crawler_states');
    }

    /**
     * Before create
     */
    public function beforeCreate()
    {
        $this->created_at = date('Y-m-d H:i:s');
        $this->updated_at = date('Y-m-d H:i:s');
    }

    /**
     * Before update
     */
    public function beforeUpdate()
    {
        $this->updated_at = date('Y-m-d H:i:s');
    }
}
