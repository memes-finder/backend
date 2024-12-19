<?php

use Phalcon\Di\FactoryDefault;
use Phalcon\Loader;
use Phalcon\Mvc\Application;
use Phalcon\Config;
use Dotenv\Dotenv;

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');

// Require Composer's autoloader
require BASE_PATH . '/vendor/autoload.php';

// Load .env file
$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->load();

// Register an autoloader
$loader = new Loader();
$loader->registerDirs([
    APP_PATH . '/Controllers/',
    APP_PATH . '/Models/',
    APP_PATH . '/Services/',
])->registerNamespaces([
    'App\\Controllers' => APP_PATH . '/Controllers/',
    'App\\Models'      => APP_PATH . '/Models/',
    'App\\Services'    => APP_PATH . '/Services/',
])->register();

// Create a DI
$di = new FactoryDefault();

// Register the configured services
require APP_PATH . '/config/services.php';
require APP_PATH . '/config/router.php';

// Register the URL service
$di->setShared('url', function () {
    $url = new \Phalcon\Url();
    $url->setBaseUri('/');
    return $url;
});

// Create and run the application
$application = new Application($di);

try {
    // Handle the request
    $response = $application->handle($_SERVER['REQUEST_URI']);
    $response->send();
} catch (\Exception $e) {
    if ($di->get('config')->application->debug) {
        echo 'Exception: ', $e->getMessage(), "\n";
        echo 'File: ', $e->getFile(), "\n";
        echo 'Line: ', $e->getLine(), "\n";
        echo 'Trace: ', $e->getTraceAsString();
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'Internal Server Error'
        ]);
    }
}
