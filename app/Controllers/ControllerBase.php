<?php

namespace App\Controllers;

use Phalcon\Mvc\Controller;

class ControllerBase extends Controller
{
    public function initialize()
    {
        // Pass flash service to view
        $this->view->flash = $this->flash;
    }
}
