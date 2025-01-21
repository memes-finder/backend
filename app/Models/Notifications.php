<?php

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Mvc\Model\Behavior\SoftDelete;

class Notifications extends Model
{
    const STATUS_ACTIVE = 'active';
    const STATUS_DELETED = 'deleted';

    /**
     * @var int
     */
    public $id;

    /**
     * @var string
     */
    public $title;

    /**
     * @var string
     */
    public $text;

    /**
     * @var string
     */
    public $color;

    /**
     * @var string
     */
    public $status;

    /**
     * @var int
     */
    public $sort_order;

    /**
     * @var string
     */
    public $created_at;

    /**
     * @var string|null
     */
    public $updated_at;

    /**
     * @var string|null
     */
    public $deleted_at;

    public function initialize()
    {
        $this->setSource('notifications');
        
        // Enable soft deletes
        $this->addBehavior(
            new SoftDelete([
                'field' => 'status',
                'value' => self::STATUS_DELETED
            ])
        );
    }

    /**
     * Before create
     */
    public function beforeCreate()
    {
        $this->created_at = date('Y-m-d H:i:s');
        $this->status = self::STATUS_ACTIVE;
        
        // Set default sort order to the highest value + 1
        $maxOrder = self::maximum([
            'column' => 'sort_order',
            'conditions' => 'status = :status:',
            'bind' => ['status' => self::STATUS_ACTIVE]
        ]);
        $this->sort_order = ($maxOrder ?: 0) + 1;
    }

    /**
     * Before update
     */
    public function beforeUpdate()
    {
        $this->updated_at = date('Y-m-d H:i:s');
    }

    /**
     * Before delete
     */
    public function beforeDelete()
    {
        $this->deleted_at = date('Y-m-d H:i:s');
    }
}
