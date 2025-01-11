<?php

use Phalcon\Mvc\Router;

$router = new Router();

// Set default namespace
$router->setDefaultNamespace('App\\Controllers');

// Admin routes
$router->add('/admin/login', [
    'controller' => 'admin',
    'action' => 'login'
])->via(['GET', 'POST']);

$router->add('/admin/logout', [
    'controller' => 'admin',
    'action' => 'logout'
]);

$router->add('/admin', [
    'controller' => 'admin',
    'action' => 'index'
]);

$router->add('/admin/meme/{id:[0-9]+}', [
    'controller' => 'admin',
    'action' => 'meme'
]);

$router->add('/admin/tags/{id:[0-9]+}', [
    'controller' => 'admin',
    'action' => 'tags'
]);

$router->add('/admin/tags/pending', [
    'controller' => 'admin',
    'action' => 'pendingTags'
]);

$router->add('/admin/tags/review/{id:[0-9]+}', [
    'controller' => 'admin',
    'action' => 'reviewTag'
]);

$router->add('/admin/tags/generate/{id:[0-9]+}', [
    'controller' => 'admin',
    'action' => 'generateTags'
]);

$router->add('/admin/delete/{id:[0-9]+}', [
    'controller' => 'admin',
    'action' => 'delete'
]);

// Define your routes here
$router->add('/api/search', [
    'controller' => 'api',
    'action'     => 'search'
])->via(['GET']);

// Tags API endpoints
$router->addGet('/api/tags/suggestions', [
    'controller' => 'tags',
    'action' => 'suggestions'
]);

$router->addGet('/api/tags/trending', [
    'controller' => 'tags',
    'action' => 'trending'
]);

$router->addPost('/api/tags/{id:[0-9]+}', [
    'controller' => 'tags',
    'action' => 'add'
]);

// Meme view
$router->addPost('/api/memes/view/{id:[0-9]+}', [
    'controller' => 'api',
    'action' => 'view'
]);

// Meme laugh
$router->addPost('/api/memes/laugh/{id:[0-9]+}', [
    'controller' => 'api',
    'action' => 'laugh'
]);

// Lambda API routes
$router->addPost('/api/lambda/video', [
    'controller' => 'lambda',
    'action' => 'createFromVideo'
]);

// Main page
$router->add('/', [
    'controller' => 'pages',
    'action' => 'index'
])->setName('home');

// Legal pages
$router->add('/privacy', [
    'controller' => 'pages',
    'action' => 'privacy'
])->setName('privacy');

$router->add('/terms', [
    'controller' => 'pages',
    'action' => 'terms'
])->setName('terms');

$router->add('/data-deletion', [
    'controller' => 'pages',
    'action' => 'dataDeletion'
])->setName('data-deletion');

// Set 404 paths
$router->notFound([
    'controller' => 'api',
    'action' => 'notFound'
]);

// Register the router in the DI container
$di->set('router', $router);
