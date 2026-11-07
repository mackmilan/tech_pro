<?php

namespace Tests\Feature;

use Illuminate\Cache\RedisStore;
use Illuminate\Queue\RedisQueue;
use Illuminate\Session\CacheBasedSessionHandler;
use ReflectionProperty;
use Tests\TestCase;

class RedisInfrastructureTest extends TestCase
{
    public function test_database_redis_has_dedicated_connections(): void
    {
        $redisConfig = config('database.redis');

        $this->assertArrayHasKey('default', $redisConfig);
        $this->assertArrayHasKey('cache', $redisConfig);
        $this->assertArrayHasKey('queue', $redisConfig);
        $this->assertArrayHasKey('session', $redisConfig);

        $this->assertSame('0', (string) $redisConfig['default']['database']);
        $this->assertSame('1', (string) $redisConfig['cache']['database']);
        $this->assertSame('2', (string) $redisConfig['queue']['database']);
        $this->assertSame('3', (string) $redisConfig['session']['database']);
    }

    public function test_config_files_default_to_redis_drivers(): void
    {
        $originalCache = $_SERVER['CACHE_STORE'] ?? null;
        $originalSession = $_SERVER['SESSION_DRIVER'] ?? null;
        $originalQueue = $_SERVER['QUEUE_CONNECTION'] ?? null;

        putenv('CACHE_STORE');
        unset($_ENV['CACHE_STORE'], $_SERVER['CACHE_STORE']);
        putenv('SESSION_DRIVER');
        unset($_ENV['SESSION_DRIVER'], $_SERVER['SESSION_DRIVER']);
        putenv('QUEUE_CONNECTION');
        unset($_ENV['QUEUE_CONNECTION'], $_SERVER['QUEUE_CONNECTION']);

        try {
            $cacheConfig = require config_path('cache.php');
            $sessionConfig = require config_path('session.php');
            $queueConfig = require config_path('queue.php');

            $this->assertSame('redis', $cacheConfig['default']);
            $this->assertSame('redis', $sessionConfig['driver']);
            $this->assertSame('session', $sessionConfig['connection']);
            $this->assertSame('redis', $queueConfig['default']);
            $this->assertSame('queue', $queueConfig['connections']['redis']['connection']);
        } finally {
            if ($originalCache !== null) {
                $_ENV['CACHE_STORE'] = $_SERVER['CACHE_STORE'] = $originalCache;
                putenv("CACHE_STORE={$originalCache}");
            }
            if ($originalSession !== null) {
                $_ENV['SESSION_DRIVER'] = $_SERVER['SESSION_DRIVER'] = $originalSession;
                putenv("SESSION_DRIVER={$originalSession}");
            }
            if ($originalQueue !== null) {
                $_ENV['QUEUE_CONNECTION'] = $_SERVER['QUEUE_CONNECTION'] = $originalQueue;
                putenv("QUEUE_CONNECTION={$originalQueue}");
            }
        }
    }

    public function test_redis_session_driver_resolves_handler_with_session_connection(): void
    {
        config([
            'session.driver' => 'redis',
            'session.connection' => 'session',
        ]);

        $session = $this->app->make('session')->driver('redis');
        $handler = $session->getHandler();

        $this->assertInstanceOf(CacheBasedSessionHandler::class, $handler);

        $store = $handler->getCache()->getStore();
        $this->assertInstanceOf(RedisStore::class, $store);

        $ref = new ReflectionProperty($store, 'connection');
        $ref->setAccessible(true);
        $this->assertSame('session', $ref->getValue($store));
    }

    public function test_redis_queue_driver_resolves_with_queue_connection(): void
    {
        config([
            'queue.default' => 'redis',
            'queue.connections.redis.connection' => 'queue',
        ]);

        $queue = $this->app->make('queue')->connection('redis');

        $this->assertInstanceOf(RedisQueue::class, $queue);

        $ref = new ReflectionProperty($queue, 'connection');
        $ref->setAccessible(true);
        $this->assertSame('queue', $ref->getValue($queue));
    }

    public function test_redis_cache_store_resolves_with_cache_connection(): void
    {
        config([
            'cache.default' => 'redis',
        ]);

        $cache = $this->app->make('cache')->store('redis');
        $store = $cache->getStore();

        $this->assertInstanceOf(RedisStore::class, $store);

        $ref = new ReflectionProperty($store, 'connection');
        $ref->setAccessible(true);
        $this->assertSame('cache', $ref->getValue($store));
    }
}
