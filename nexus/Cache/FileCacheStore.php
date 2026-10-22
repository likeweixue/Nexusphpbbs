<?php

namespace Nexus\Cache;

use Illuminate\Cache\CacheLock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;

/**
 * Laravel cache store adapter on top of FileStore.
 *
 * NexusPHP shares its cache keys between the legacy pages (global $Cache) and
 * Laravel (Cache facade).  Using this store for both keeps the namespace and
 * the stored values identical, exactly like the old Redis setup.
 */
class FileCacheStore implements Store, LockProvider
{
    public function __construct(private FileStore $store)
    {
    }

    public function getStore(): FileStore
    {
        return $this->store;
    }

    public function get($key)
    {
        $value = $this->store->get($key);

        return $value === false ? null : $value;
    }

    public function many(array $keys)
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    public function put($key, $value, $seconds)
    {
        $seconds = (int)$seconds;

        return $seconds > 0 ? $this->store->setex($key, $seconds, $value) : $this->store->set($key, $value);
    }

    public function putMany(array $values, $seconds)
    {
        $result = true;
        foreach ($values as $key => $value) {
            $result = $this->put($key, $value, $seconds) && $result;
        }

        return $result;
    }

    public function increment($key, $value = 1)
    {
        return $this->store->incr($key, (int)$value);
    }

    public function decrement($key, $value = 1)
    {
        return $this->store->decr($key, (int)$value);
    }

    public function forever($key, $value)
    {
        return $this->store->set($key, $value);
    }

    public function forget($key)
    {
        return $this->store->del($key) > 0;
    }

    public function flush()
    {
        return $this->store->flush();
    }

    public function getPrefix()
    {
        return '';
    }

    /**
     * Atomic add() used by the rate limiter (throttle middleware).
     */
    public function add($key, $value, $seconds)
    {
        $seconds = (int)$seconds;
        if ($seconds <= 0) {
            return false;
        }

        return $this->store->set($key, $value, ['nx', 'ex' => $seconds]);
    }

    public function touch($key, $seconds)
    {
        $value = $this->store->get($key);
        if ($value === false) {
            return false;
        }

        return $this->put($key, $value, $seconds);
    }

    public function lock($name, $seconds = 0, $owner = null)
    {
        return new CacheLock($this, $name, $seconds, $owner);
    }

    public function restoreLock($name, $owner)
    {
        return new CacheLock($this, $name, 0, $owner);
    }
}
