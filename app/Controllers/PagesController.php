<?php

namespace App\Controllers;

use Phalcon\Mvc\Controller;

class PagesController extends Controller
{
    public function initialize()
    {
        // Set Facebook App ID from environment variable for all views
        $this->view->setVar('facebookAppId', $_ENV['FACEBOOK_APP_ID']);
    }

    /**
     * Index page
     */
    public function indexAction()
    {
        $this->view->setMainView('layouts/main');
        $this->view->setVar('title', 'Memes Planet - API Service');
        $this->view->pick('pages/index');
    }

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
