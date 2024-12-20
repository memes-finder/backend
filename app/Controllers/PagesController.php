<?php

namespace App\Controllers;

use Phalcon\Mvc\Controller;

class PagesController extends Controller
{
    /**
     * Privacy Policy page
     */
    public function privacyAction()
    {
        $this->view->setMainView('layouts/main');
        $this->view->setVar('title', 'Privacy Policy - Memes Collection');
        $this->view->pick('pages/privacy');
    }

    /**
     * Terms of Service page
     */
    public function termsAction()
    {
        $this->view->setMainView('layouts/main');
        $this->view->setVar('title', 'Terms of Service - Memes Collection');
        $this->view->pick('pages/terms');
    }

    /**
     * Data Deletion Instructions page
     */
    public function dataDeletionAction()
    {
        $this->view->setMainView('layouts/main');
        $this->view->setVar('title', 'Data Deletion Instructions - Memes Planet');
        $this->view->pick('pages/data-deletion');
    }
}
