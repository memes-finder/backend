<?php

namespace App\Models;

class QueuedUploads extends \Phalcon\Mvc\Model
{
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    public $id;
    public $original_name;
    public $extract_path;
    public $status;
    public $processed_count;
    public $error_count;
    public $error_message;
    public $created_at;
    public $completed_at;

    public function initialize()
    {
        $this->setSource('queued_uploads');
    }

    public function beforeValidation()
    {
        if (empty($this->created_at)) {
            $this->created_at = date('Y-m-d H:i:s');
        }
        if (empty($this->status)) {
            $this->status = self::STATUS_PENDING;
        }
    }
}
