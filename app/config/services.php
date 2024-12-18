<?php

use App\Enums\CacheIndexesEnum;
use App\Redis\Connector;
use Ehann\RediSearch\Index;
use Ehann\RediSearch\Suggestion;
use Phalcon\Http\Message\StreamFactory;
use Phalcon\Logger\AdapterFactory;
use Phalcon\Mvc\View;
use Phalcon\Url;
use Phalcon\Session\Adapter\Stream as SessionAdapter;
use Phalcon\Session\Manager as SessionManager;
use Phalcon\Http\Response\Cookies;
use Phalcon\Logger\Adapter\Stream as FileLogger;
use Phalcon\Logger\Adapter\StreamFactory as StreamLogger;
use Phalcon\Logger\LoggerFactory;

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
 * Register the cookies service
 */
$di->setShared('cookies', function () {
    $cookies = new Cookies();
    $cookies->useEncryption(false);
    return $cookies;
});

/**
 * Logger service
 */
$di->setShared('logger', function () {
    $config = $this->getConfig();

    $adapterFactory = new AdapterFactory();
    $loggerFactory  = new LoggerFactory($adapterFactory);
    return $loggerFactory->newInstance('logger', [
        'main' => $loggerFactory->newInstance('main', [
            'fileName' => $config->application->logPath,
        ]),
    ]);
});

/**
 * Register redis service
 */
$di->setShared('redis', function () {
    $config = $this->getConfig();

    $redis = new Redis();
    $redis->connect(
        $config->redis->host,
        $config->redis->port
    );

    if (!empty($config->redis->password)) {
        $redis->auth($config->redis->password);
    }

    return $redis;
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
 * RediSearch Index service
 */
$di->setShared('redisIndex', function () {
    $redisIndex = new Index($this->getCache()['adapter'], 'idx:memes');
    $redisIndex->setIndexName(CacheIndexesEnum::MEME_INDEX_NAME);
    return $redisIndex;
});

/**
 * RediSearch Suggestion service
 */
$di->setShared('redisSuggest', function () {
    return new Suggestion($this->getCache()['adapter']);
});

// Register the search service
$di->setShared('searchService', function () {
    return new \App\Services\SearchService();
});

// Register AITaggingService
$di->setShared('aiTaggingService', function () {
    return new \App\Services\AITaggingService();
});
