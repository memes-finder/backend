<?php

use Phalcon\Cli\Console;
use Phalcon\Cli\Dispatcher;
use Phalcon\Di\FactoryDefault\Cli as CliDI;
use Phalcon\Loader;
use Dotenv\Dotenv;

define('BASE_PATH', __DIR__);
define('APP_PATH', BASE_PATH . '/app');

// Require Composer's autoloader
require BASE_PATH . '/vendor/autoload.php';

// Load .env
$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->load();

// Register an autoloader
$loader = new Loader();
$loader->registerNamespaces(
    [
        'App' => BASE_PATH . '/app/',
        'App\Models'      => APP_PATH . '/Models/',
        'App\Services'    => APP_PATH . '/Services/',
        'App\Enums'      => APP_PATH . '/Enums/',
    ]
)->register();

// Create a CLI application
$di = new CliDI();

require APP_PATH . '/config/services.php';

// Register the dispatcher service
$di->setShared('dispatcher', function() {
    $dispatcher = new Dispatcher();
    $dispatcher->setDefaultNamespace('App\Tasks');
    return $dispatcher;
});

$console = new Console($di);

try {
    // Process the console arguments
    $arguments = [];
    foreach ($argv as $k => $arg) {
        if ($k === 1) {
            $arguments['task'] = $arg;
        } elseif ($k === 2) {
            $arguments['action'] = $arg;
        } elseif ($k >= 3) {
            $arguments['params'][] = $arg;
        }
    }

    // Handle the request
    $console->handle($arguments);
} catch (\Phalcon\Exception $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
} catch (\Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . PHP_EOL);
    exit(1);
}
