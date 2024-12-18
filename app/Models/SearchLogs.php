<?php

namespace App\Models;

use Phalcon\Mvc\Model;

class SearchLogs extends Model
{
    public $id;
    public $query;
    public $language;
    public $results_count;
    public $created_at;

    public function initialize()
    {
        $this->setSource('search_logs');
    }

    public function beforeCreate()
    {
        $this->created_at = date('Y-m-d H:i:s');
    }
}
