<?php

use Phalcon\Mvc\Router;

$router = new Router();

// Set default namespace
$router->setDefaultNamespace('App\\Controllers');

// Define your routes here
$router->add('/api/search', [
    'controller' => 'api',
    'action'     => 'search'
])->via(['GET']);

$router->add('/api/upload', [
    'controller' => 'api',
    'action'     => 'upload'
])->via(['POST']);

$router->add('/api/crawl', [
    'controller' => 'api',
    'action'     => 'crawl'
])->via(['POST']);

// Admin routes
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

$router->add('/admin/tags/generate/{id:[0-9]+}', [
    'controller' => 'admin',
    'action' => 'generateTags'
]);

$router->add('/admin/delete/{id:[0-9]+}', [
    'controller' => 'admin',
    'action' => 'delete'
]);

// Set 404 paths
$router->notFound([
    'controller' => 'api',
    'action' => 'notFound'
]);

// Register the router in the DI container
$di->set('router', $router);

//$router->handle($_SERVER['REQUEST_URI']);
