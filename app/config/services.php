<?php

use App\Enums\CacheIndexesEnum;
use App\Redis\Connector;
use Aws\S3\S3Client;
use Ehann\RediSearch\Index;
use Ehann\RediSearch\Suggestion;
use Phalcon\Logger\AdapterFactory;
use Phalcon\Logger\LoggerFactory;
use Phalcon\Mvc\View;
use Phalcon\Url;
use Phalcon\Session\Adapter\Stream as SessionAdapter;
use Phalcon\Session\Manager as SessionManager;
use Phalcon\Logger\Adapter\Stream as FileLogger;

/**
 * Shared configuration service
 */
$di->setShared('config', function () {
    $config = include APP_PATH . "/config/config.php";
    return $config;
});

/**
 * Database connection is created based on parameters defined in the configuration file
 */
$di->setShared('db', function () {
    $config = $this->getConfig();

    $class = 'Phalcon\Db\Adapter\Pdo\\' . $config->database->adapter;
    $params = [
        'host'     => $config->database->host,
        'username' => $config->database->username,
        'password' => $config->database->password,
        'dbname'   => $config->database->dbname,
        'charset'  => $config->database->charset
    ];

    return new $class($params);
});

/**
 * Setting up the view component
 */
$di->setShared('view', function () {
    $view = new View();
    $view->setViewsDir(APP_PATH . '/views/');
    return $view;
});

/**
 * The URL component is used to generate all kind of URLs in the application
 */
$di->setShared('url', function () {
    $config = $this->getConfig();

    $url = new Url();
    $url->setBaseUri($config->application->baseUri);
    return $url;
});

/**
 * Start the session the first time some component requests the session service
 */
$di->setShared('session', function () {
    $session = new SessionManager();
    $files = new SessionAdapter([
        'savePath' => sys_get_temp_dir(),
    ]);
    $session->setAdapter($files);
    $session->start();

    return $session;
});

/**
 * Register the escaper service
 */
$di->setShared('escaper', function () {
    return new \Phalcon\Escaper();
});

/**
 * Register flash service
 */
$di->setShared('flash', function () {
    $escaper = $this->get('escaper');
    $flash = new \Phalcon\Flash\Session($escaper);
    $flash->setCssClasses([
        'error'   => 'errorMessage',
        'success' => 'successMessage',
        'notice'  => 'noticeMessage',
        'warning' => 'warningMessage'
    ]);
    return $flash;
});

/**
 * Register cookies service
 */
$di->setShared('cookies', function () {
    $cookies = new \Phalcon\Http\Response\Cookies();
    $cookies->useEncryption(false);
    return $cookies;
});

/**
 * Logger service
 */
$di->setShared('logger', function () {
    // Create logs directory if it doesn't exist
    $logPath = BASE_PATH . '/storage/logs';
    if (!is_dir($logPath)) {
        mkdir($logPath, 0777, true);
    }

    if (!file_exists($logPath . '/errors.log')) {
        touch($logPath . '/errors.log');
    }

    $adapters = [
        "main" => new FileLogger($logPath . '/errors.log')
    ];

    $adapterFactory = new AdapterFactory();
    $loggerFactory = new LoggerFactory($adapterFactory);

    return $loggerFactory->newInstance('logger', $adapters);
});

/**
 * Register Redis as a service
 */
$di->set('cache', function(int $database = 0) {
    $config = $this->getConfig()->redis;
    $redisInstance = new Connector();
    $redisInstance->connect(
        $config->host,
        $config->port,
        $database,
        $config->auth ?? null
    );
    return ['adapter' => $redisInstance, 'instance' => $redisInstance->redis];
});

/**
 * RedisSearch indexing service
 */
$di->setShared('redisIndex', function () {
    $redisIndex = new Index($this->getCache()['adapter'], 'idx:memes');
    $redisIndex->setIndexName(CacheIndexesEnum::MEME_INDEX_NAME);
    return $redisIndex;
});

/**
 * RedisSearch Suggestion service
 */
$di->setShared('redisSuggest', function () {
    return new Suggestion($this->getCache()['adapter']);
});

$di->setShared('rateLimiter', function () {
    return $this->getCache($_ENV['REDIS_INDEX'])['instance'];
});

// Register the search service
$di->setShared('searchService', function () {
    return new \App\Services\SearchService();
});

/**
 * Register dispatcher service with admin auth middleware
 */
$di->setShared('dispatcher', function () {
    $dispatcher = new \Phalcon\Mvc\Dispatcher();
    $dispatcher->setDefaultNamespace('App\Controllers');
    
    // Create an event manager
    $eventsManager = new \Phalcon\Events\Manager();
    
    // Get DI container
    $container = $this;
    
    // Attach the middleware for admin routes
    $eventsManager->attach(
        'dispatch:beforeExecuteRoute',
        function($event, $dispatcher) use ($container) {
            $controllerName = $dispatcher->getControllerName();
            
            // Apply middleware only to admin routes
            if ($controllerName === 'admin') {
                $middleware = new \App\Middleware\AdminAuthMiddleware();
                $middleware->setDI($container);
                return $middleware->beforeExecuteRoute();
            }
            
            return true;
        }
    );
    
    // Bind the events manager to the dispatcher
    $dispatcher->setEventsManager($eventsManager);
    
    return $dispatcher;
});

$di->setShared('s3Client', function () {
    $config = $this->getConfig()->aws;

    return new S3Client([
        'version' => 'latest',
        'region'  => $config->region,
        'credentials' => [
            'key'    => $config->key,
            'secret' => $config->secret,
        ]
    ]);
});
