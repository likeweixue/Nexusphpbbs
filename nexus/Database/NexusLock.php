<?php

namespace Nexus\Database;

use App\Exceptions\LockFailException;
use Nexus\Cache\FileStore;

/**
 * Lock replacement for the old Redis based lock.
 *
 * Locks with a TTL are stored in the shared file cache and expire
 * automatically, which keeps the original rate limiting semantics (for
 * example "you are speaking too often" in the shoutbox).  Locks without a
 * TTL fall back to a real flock() so they are released when the process ends.
 */
class NexusLock
{
    protected string $name;

    protected int $seconds;

    protected string $owner;

    protected string $cacheKey;

    /** @var resource|null */
    protected $handle = null;

    public function __construct($name, $seconds = 0, $owner = null)
    {
        $this->name = (string)$name;
        $this->seconds = (int)$seconds;
        $this->owner = $owner ?: sprintf(
            '%s|%s|%s',
            gethostname() ?: 'localhost',
            getmypid(),
            bin2hex(random_bytes(8))
        );
        $this->cacheKey = 'nexus_lock:' . $this->name;
    }

    public function __destruct()
    {
        if ($this->seconds <= 0) {
            $this->release();
        }
    }

    /**
     * Attempt to acquire the lock.
     */
    public function acquire(): bool
    {
        if ($this->seconds > 0) {
            return FileStore::instance()->set($this->cacheKey, $this->owner, ['nx', 'ex' => $this->seconds]);
        }

        $file = $this->lockFile();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $handle = @fopen($file, 'c');
        if ($handle === false) {
            return false;
        }
        if (@flock($handle, LOCK_EX | LOCK_NB)) {
            $this->handle = $handle;

            return true;
        }
        @fclose($handle);

        return false;
    }

    /**
     * Alias kept for compatibility with Illuminate\Cache\Lock::get().
     */
    public function get(): bool
    {
        return $this->acquire();
    }

    /**
     * Wait up to $seconds for the lock.
     */
    public function block($seconds): bool
    {
        $deadline = microtime(true) + (float)$seconds;
        do {
            if ($this->acquire()) {
                return true;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        return false;
    }

    public function release(): bool
    {
        if ($this->seconds > 0) {
            return FileStore::instance()->compareAndDelete($this->cacheKey, $this->owner);
        }
        if ($this->handle !== null) {
            @flock($this->handle, LOCK_UN);
            @fclose($this->handle);
            $this->handle = null;

            return true;
        }

        return false;
    }

    public function owner(): string
    {
        return $this->owner;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSeconds(): int
    {
        return $this->seconds;
    }

    private function lockFile(): string
    {
        $root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
        $dir = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'storage/framework/cache/nexus/locks';

        return $dir . DIRECTORY_SEPARATOR . md5($this->name) . '.lock';
    }

    /**
     * @throws LockFailException
     */
    public static function lockOrFail($name, $seconds, $owner = null): NexusLock
    {
        $lock = new self($name, $seconds, $owner);
        if (!$lock->acquire()) {
            do_log("$name failed to acquire lock", 'error');
            throw new LockFailException($name, $lock->owner());
        }

        return $lock;
    }
}
