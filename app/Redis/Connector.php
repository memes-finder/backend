<?php
/**
 * User: Wajdi Jurry
 * Date: 17/03/19
 * Time: 10:22 م
 */

namespace App\Redis;

use Ehann\RedisRaw\PhpRedisAdapter;
use Ehann\RedisRaw\RedisRawClientInterface;
use Redis;

/**
 * Class Connector
 * @package App\Redis
 *
 * Implement Ehann\RedisRaw\PhpRedisAdapter
 * To enable connection To Redis
 */
class Connector extends PhpRedisAdapter
{
    public function connect($hostname = '127.0.0.1', $port = 6379, $db = 0, $password = null): RedisRawClientInterface
    {
        $this->redis = new Redis();
        $this->redis->connect($hostname, $port);
        
        if (!empty($password)) {
            $this->redis->auth($password);
        }
        
        if ($db !== 0) {
            $this->redis->select($db);
        }
        
        return $this;
    }
}