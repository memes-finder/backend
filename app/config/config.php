<?php

return new \Phalcon\Config([
    'database' => [
        'adapter'  => 'Mysql',
        'host'     => $_ENV['DB_HOST'] ?? 'mysql',
        'username' => $_ENV['DB_USERNAME'] ?? 'memes_user',
        'password' => $_ENV['DB_PASSWORD'] ?? 'memes_password',
        'dbname'   => $_ENV['DB_DATABASE'] ?? 'memes_db',
        'charset'  => 'utf8mb4',
    ],
    'application' => [
        'baseUri'        => '/',
        'publicUrl'      => $_ENV['APP_URL'] ?? 'http://localhost:8080',
        'cryptSalt'      => 'eEAfR|_&G&f,+vU]:jFr!!A&+71w1Ms9~8_4L!<@[N@DyaIP_2My|:+.u>/6m,$D',
        'viewsDir'       => APP_PATH . '/views/',
        'cacheDir'       => BASE_PATH . '/storage/cache/',
        'sessionSavePath'=> BASE_PATH . '/storage/sessions/',
        'logPath'        => BASE_PATH . '/storage/logs/app.log',
    ],
    'redis' => [
        'host' => $_ENV['REDIS_HOST'] ?? 'redis',
        'port' => $_ENV['REDIS_PORT'] ?? 6379,
        'auth' => $_ENV['REDIS_PASSWORD'] ?? null
    ],
    'aws' => [
        'key' => $_ENV['AWS_ACCESS_KEY_ID'] ?? null,
        'secret' => $_ENV['AWS_SECRET_ACCESS_KEY'] ?? null,
        'region' => $_ENV['AWS_DEFAULT_REGION'] ?? 'us-east-1',
        'bucket' => $_ENV['AWS_BUCKET'] ?? 'memes-storage'
    ],
    'google' => [
        'api_key' => $_ENV['GOOGLE_API_KEY'] ?? null,
        'cx' => $_ENV['GOOGLE_CX'] ?? null,
        'service_account' => BASE_PATH . '/' . $_ENV['GOOGLE_APPLICATION_CREDENTIALS']
    ],
    'openai' => [
        'api_key' => $_ENV['OPENAI_API_KEY'] ?? null
    ],
    'tenor' => [
        'api_key' => getenv('TENOR_API_KEY')
    ],
]);
