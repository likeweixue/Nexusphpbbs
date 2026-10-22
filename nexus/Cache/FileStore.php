<?php

namespace Nexus\Cache;

/**
 * A dependency-free, file backed key/value store.
 *
 * NexusPHP used Redis for caching, locks, counters, hashes, lists and sets.
 * This class implements the subset of the phpredis API that NexusPHP actually
 * uses, so the whole application can run with nothing but PHP + MySQL.
 *
 * Every key is stored in its own file below storage/framework/cache/nexus,
 * which keeps reads/writes cheap and avoids a global lock.  Writes are done
 * with a per-key flock() plus an atomic rename(), so concurrent requests can
 * not observe a half written value.
 */
class FileStore
{
    /** phpredis constants used by the application, kept for drop-in usage. */
    public const OPT_SCAN = 1;
    public const SCAN_RETRY = 1;
    public const PIPELINE = 2;
    public const MULTI = 1;

    private const TYPE_STRING = 'string';
    private const TYPE_HASH = 'hash';
    private const TYPE_LIST = 'list';
    private const TYPE_SET = 'set';

    private static ?self $instance = null;

    private string $dir = '';

    private bool $enabled = false;

    private ?string $lastError = null;

    public function __construct(?string $dir = null)
    {
        if ($dir === null || $dir === '') {
            $root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
            $dir = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'storage/framework/cache/nexus';
        }
        $this->setupDirectory($dir);
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    public function getDir(): string
    {
        return $this->dir;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    private function setupDirectory(string $dir): void
    {
        $dir = rtrim($dir, '/\\');
        if ($dir === '') {
            $this->enabled = false;

            return;
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            $this->dir = $dir;
            $this->enabled = true;

            return;
        }
        // Last resort: the system temp dir.  Still better than a fatal error.
        $fallback = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'nexusphp-cache-' . substr(md5($dir), 0, 8);
        if (!is_dir($fallback)) {
            @mkdir($fallback, 0775, true);
        }
        if (is_dir($fallback) && is_writable($fallback)) {
            $this->dir = $fallback;
            $this->enabled = true;
            if (function_exists('do_log')) {
                do_log("cache dir: $dir not writable, fallback to: $fallback", 'warning');
            }

            return;
        }
        $this->enabled = false;
        if (function_exists('do_log')) {
            do_log("cache dir: $dir is not writable, cache disabled", 'error');
        }
    }

    /* ---------------------------------------------------------------------
     * Internal helpers
     * ------------------------------------------------------------------- */

    private function fileFor(string $key): string
    {
        $hash = md5($key);

        return $this->dir . DIRECTORY_SEPARATOR . substr($hash, 0, 2) . DIRECTORY_SEPARATOR . $hash . '.cache';
    }

    private function lockFileFor(string $key): string
    {
        return $this->fileFor($key) . '.lock';
    }

    private function readRecord(string $key): array|false
    {
        if (!$this->enabled) {
            return false;
        }
        $file = $this->fileFor($key);
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return false;
        }
        $record = @unserialize($raw, ['allowed_classes' => true]);
        if (!is_array($record) || !array_key_exists('k', $record) || !array_key_exists('v', $record)) {
            @unlink($file);

            return false;
        }
        $expire = (int)($record['e'] ?? 0);
        if ($expire > 0 && $expire <= time()) {
            @unlink($file);

            return false;
        }

        return $record;
    }

    private function writeRecord(string $key, string $type, mixed $value, int $expire = 0): bool
    {
        if (!$this->enabled) {
            return false;
        }
        $file = $this->fileFor($key);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            $this->lastError = "cache dir not writable: $dir";

            return false;
        }
        $payload = serialize([
            'k' => $key,
            't' => $type,
            'e' => $expire,
            'v' => $value,
        ]);
        $tmp = $dir . DIRECTORY_SEPARATOR . '.tmp_' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $payload) === false) {
            $this->lastError = "can not write cache file: $tmp";

            return false;
        }
        @chmod($tmp, 0664);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            $this->lastError = "can not rename cache file: $file";

            return false;
        }
        $this->maybeGc();

        return true;
    }

    /**
     * Execute a read-modify-write cycle under an exclusive per-key lock.
     */
    private function withLock(string $key, callable $callback): mixed
    {
        if (!$this->enabled) {
            return $callback(false);
        }
        $file = $this->lockFileFor($key);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $handle = @fopen($file, 'c');
        if ($handle === false) {
            $this->lastError = "can not open lock file: $file";

            return $callback(false);
        }
        try {
            @flock($handle, LOCK_EX);

            return $callback($this->readRecord($key));
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    private function maybeGc(): void
    {
        // 1 out of 200 writes triggers a cheap garbage collection pass.
        if (mt_rand(1, 200) !== 1) {
            return;
        }
        $this->gc(300);
    }

    public function gc(int $limit = 300): int
    {
        if (!$this->enabled) {
            return 0;
        }
        $files = glob($this->dir . '/*/*.cache');
        if (empty($files)) {
            return 0;
        }
        if (count($files) > $limit) {
            $offset = mt_rand(0, max(0, count($files) - $limit));
            $files = array_slice($files, $offset, $limit);
        }
        $removed = 0;
        $now = time();
        foreach ($files as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            $record = @unserialize($raw, ['allowed_classes' => true]);
            if (!is_array($record)) {
                @unlink($file);
                $removed++;
                continue;
            }
            $expire = (int)($record['e'] ?? 0);
            if ($expire > 0 && $expire <= $now) {
                @unlink($file);
                $removed++;
            }
        }

        return $removed;
    }

    private function matches(string $pattern, string $key): bool
    {
        if ($pattern === '' || $pattern === '*') {
            return true;
        }
        if (!str_contains($pattern, '*') && !str_contains($pattern, '?') && !str_contains($pattern, '[')) {
            return $pattern === $key;
        }

        return fnmatch($pattern, $key);
    }

    /* ---------------------------------------------------------------------
     * phpredis compatible API
     * ------------------------------------------------------------------- */

    public function get(string $key): mixed
    {
        $record = $this->readRecord($key);
        if ($record === false) {
            return false;
        }

        return $record['v'] ?? false;
    }

    public function set(string $key, mixed $value, mixed $options = null): bool
    {
        if (!$this->enabled) {
            return false;
        }
        $ttl = 0;
        $nx = false;
        $xx = false;
        if (is_int($options)) {
            $ttl = $options;
        } elseif (is_array($options)) {
            foreach ($options as $optionKey => $optionValue) {
                if (is_int($optionKey)) {
                    $flag = strtolower((string)$optionValue);
                    if ($flag === 'nx') {
                        $nx = true;
                    } elseif ($flag === 'xx') {
                        $xx = true;
                    }
                } else {
                    $flag = strtolower((string)$optionKey);
                    if ($flag === 'ex') {
                        $ttl = (int)$optionValue;
                    } elseif ($flag === 'px') {
                        $ttl = (int)ceil(((int)$optionValue) / 1000);
                    } elseif ($flag === 'nx') {
                        $nx = true;
                    } elseif ($flag === 'xx') {
                        $xx = true;
                    }
                }
            }
        }

        return (bool)$this->withLock($key, function ($record) use ($key, $value, $ttl, $nx, $xx) {
            $exists = $record !== false;
            if ($nx && $exists) {
                return false;
            }
            if ($xx && !$exists) {
                return false;
            }

            return $this->writeRecord($key, self::TYPE_STRING, $value, $ttl > 0 ? time() + $ttl : 0);
        });
    }

    public function setex(string $key, int $ttl, mixed $value): bool
    {
        return $this->set($key, $value, ['ex' => $ttl]);
    }

    public function setnx(string $key, mixed $value): bool
    {
        return $this->set($key, $value, ['nx']);
    }

    public function del(string ...$keys): int
    {
        $count = 0;
        foreach ($keys as $key) {
            if (!$this->enabled) {
                break;
            }
            $file = $this->fileFor($key);
            if (is_file($file)) {
                if (@unlink($file)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    public function unlink(string ...$keys): int
    {
        return $this->del(...$keys);
    }

    public function exists(string $key): bool
    {
        return $this->readRecord($key) !== false;
    }

    /**
     * Atomically delete a key only when its current value equals $expected.
     * Used to release a lock that was acquired with set(..., ['nx']).
     */
    public function compareAndDelete(string $key, mixed $expected): bool
    {
        if (!$this->enabled) {
            return false;
        }
        $deleted = false;
        $this->withLock($key, function ($record) use ($key, $expected, &$deleted) {
            if ($record !== false && $record['v'] === $expected) {
                @unlink($this->fileFor($key));
                $deleted = true;
            }

            return null;
        });

        return $deleted;
    }

    public function expire(string $key, int $ttl): bool
    {
        return $this->expireAt($key, time() + $ttl);
    }

    public function expireAt(string $key, int $timestamp): bool
    {
        return (bool)$this->withLock($key, function ($record) use ($key, $timestamp) {
            if ($record === false) {
                return false;
            }
            $record['e'] = (int)$timestamp;

            return $this->writeRecord($key, $record['t'], $record['v'], (int)$timestamp);
        });
    }

    public function ttl(string $key): int
    {
        $record = $this->readRecord($key);
        if ($record === false) {
            return -2;
        }
        $expire = (int)($record['e'] ?? 0);

        return $expire > 0 ? max(0, $expire - time()) : -1;
    }

    public function incr(string $key, int $by = 1): int|false
    {
        return $this->withLock($key, function ($record) use ($key, $by) {
            $current = 0;
            $expire = 0;
            if ($record !== false) {
                $current = is_numeric($record['v']) ? (int)$record['v'] : 0;
                $expire = (int)($record['e'] ?? 0);
            }
            $new = $current + $by;

            return $this->writeRecord($key, self::TYPE_STRING, $new, $expire) ? $new : false;
        });
    }

    public function decr(string $key, int $by = 1): int|false
    {
        return $this->incr($key, -$by);
    }

    /* ------------------------------- hashes --------------------------- */

    public function hSet(string $key, string|int $field, mixed $value): int
    {
        return (int)$this->withLock($key, function ($record) use ($key, $field, $value) {
            $hash = ($record !== false && $record['t'] === self::TYPE_HASH && is_array($record['v'])) ? $record['v'] : [];
            $expire = $record !== false ? (int)($record['e'] ?? 0) : 0;
            $isNew = !array_key_exists((string)$field, $hash);
            $hash[(string)$field] = $value;
            $this->writeRecord($key, self::TYPE_HASH, $hash, $expire);

            return $isNew ? 1 : 0;
        });
    }

    public function hSetNx(string $key, string|int $field, mixed $value): bool
    {
        return (bool)$this->withLock($key, function ($record) use ($key, $field, $value) {
            $hash = ($record !== false && $record['t'] === self::TYPE_HASH && is_array($record['v'])) ? $record['v'] : [];
            $expire = $record !== false ? (int)($record['e'] ?? 0) : 0;
            if (array_key_exists((string)$field, $hash)) {
                return false;
            }
            $hash[(string)$field] = $value;

            return $this->writeRecord($key, self::TYPE_HASH, $hash, $expire);
        });
    }

    public function hGet(string $key, string|int $field): mixed
    {
        $record = $this->readRecord($key);
        if ($record === false || $record['t'] !== self::TYPE_HASH || !is_array($record['v'])) {
            return false;
        }
        $field = (string)$field;

        return array_key_exists($field, $record['v']) ? $record['v'][$field] : false;
    }

    public function hGetAll(string $key): array
    {
        $record = $this->readRecord($key);
        if ($record === false || $record['t'] !== self::TYPE_HASH || !is_array($record['v'])) {
            return [];
        }

        return $record['v'];
    }

    public function hExists(string $key, string|int $field): bool
    {
        $record = $this->readRecord($key);
        if ($record === false || $record['t'] !== self::TYPE_HASH || !is_array($record['v'])) {
            return false;
        }

        return array_key_exists((string)$field, $record['v']);
    }

    public function hDel(string $key, string|int ...$fields): int
    {
        return (int)$this->withLock($key, function ($record) use ($key, $fields) {
            if ($record === false || $record['t'] !== self::TYPE_HASH || !is_array($record['v'])) {
                return 0;
            }
            $hash = $record['v'];
            $removed = 0;
            foreach ($fields as $field) {
                $field = (string)$field;
                if (array_key_exists($field, $hash)) {
                    unset($hash[$field]);
                    $removed++;
                }
            }
            if ($removed > 0) {
                $this->writeRecord($key, self::TYPE_HASH, $hash, (int)($record['e'] ?? 0));
            }

            return $removed;
        });
    }

    public function hLen(string $key): int
    {
        $record = $this->readRecord($key);
        if ($record === false || $record['t'] !== self::TYPE_HASH || !is_array($record['v'])) {
            return 0;
        }

        return count($record['v']);
    }

    public function hIncrBy(string $key, string|int $field, int $by): int|false
    {
        return $this->withLock($key, function ($record) use ($key, $field, $by) {
            $hash = ($record !== false && $record['t'] === self::TYPE_HASH && is_array($record['v'])) ? $record['v'] : [];
            $expire = $record !== false ? (int)($record['e'] ?? 0) : 0;
            $field = (string)$field;
            $current = isset($hash[$field]) && is_numeric($hash[$field]) ? (int)$hash[$field] : 0;
            $new = $current + $by;
            $hash[$field] = $new;

            return $this->writeRecord($key, self::TYPE_HASH, $hash, $expire) ? $new : false;
        });
    }

    /**
     * @param int $it cursor, passed by reference like phpredis does
     * @return array|false
     */
    public function hScan(string $key, ?int &$it, string $pattern = '*', int $count = 10): array|false
    {
        // phpredis semantics: null starts a new iteration, 0 means finished.
        if ($it === 0) {
            return false;
        }
        $record = $this->readRecord($key);
        if ($record === false || $record['t'] !== self::TYPE_HASH || !is_array($record['v'])) {
            $it = 0;

            return false;
        }
        $hash = $record['v'];
        $allFields = array_keys($hash);
        $total = count($allFields);
        $offset = $it === null ? 0 : max(0, $it);
        $result = [];
        $count = max(1, $count);
        while ($offset < $total) {
            $field = (string)$allFields[$offset];
            $offset++;
            if ($this->matches($pattern, $field)) {
                $result[$field] = $hash[$field];
                if (count($result) >= $count) {
                    break;
                }
            }
        }
        if ($offset >= $total) {
            $it = 0;

            return empty($result) ? false : $result;
        }
        $it = $offset;

        return $result;
    }

    /* -------------------------------- lists --------------------------- */

    public function rPush(string $key, mixed ...$values): int
    {
        return (int)$this->withLock($key, function ($record) use ($key, $values) {
            $list = ($record !== false && $record['t'] === self::TYPE_LIST && is_array($record['v'])) ? $record['v'] : [];
            $expire = $record !== false ? (int)($record['e'] ?? 0) : 0;
            foreach ($values as $value) {
                $list[] = $value;
            }
            $this->writeRecord($key, self::TYPE_LIST, $list, $expire);

            return count($list);
        });
    }

    public function lPush(string $key, mixed ...$values): int
    {
        return (int)$this->withLock($key, function ($record) use ($key, $values) {
            $list = ($record !== false && $record['t'] === self::TYPE_LIST && is_array($record['v'])) ? $record['v'] : [];
            $expire = $record !== false ? (int)($record['e'] ?? 0) : 0;
            foreach ($values as $value) {
                array_unshift($list, $value);
            }
            $this->writeRecord($key, self::TYPE_LIST, $list, $expire);

            return count($list);
        });
    }

    public function lRange(string $key, int $start, int $stop): array
    {
        $record = $this->readRecord($key);
        if ($record === false || $record['t'] !== self::TYPE_LIST || !is_array($record['v'])) {
            return [];
        }

        return array_slice($record['v'], $start, $stop === -1 ? null : $stop - $start + 1);
    }

    public function lRem(string $key, mixed $value, int $count = 0): int
    {
        return (int)$this->withLock($key, function ($record) use ($key, $value, $count) {
            if ($record === false || $record['t'] !== self::TYPE_LIST || !is_array($record['v'])) {
                return 0;
            }
            $list = $record['v'];
            $removed = 0;
            if ($count === 0) {
                $new = [];
                foreach ($list as $item) {
                    if ($item === $value) {
                        $removed++;
                    } else {
                        $new[] = $item;
                    }
                }
                $list = $new;
            } elseif ($count > 0) {
                $new = [];
                $limit = $count;
                foreach ($list as $item) {
                    if ($limit > 0 && $item === $value) {
                        $removed++;
                        $limit--;
                    } else {
                        $new[] = $item;
                    }
                }
                $list = $new;
            } else {
                $list = array_reverse($list);
                $limit = -$count;
                $new = [];
                foreach ($list as $item) {
                    if ($limit > 0 && $item === $value) {
                        $removed++;
                        $limit--;
                    } else {
                        $new[] = $item;
                    }
                }
                $list = array_reverse($new);
            }
            if ($removed > 0) {
                $this->writeRecord($key, self::TYPE_LIST, $list, (int)($record['e'] ?? 0));
            }

            return $removed;
        });
    }

    public function lLen(string $key): int
    {
        $record = $this->readRecord($key);

        return ($record !== false && $record['t'] === self::TYPE_LIST && is_array($record['v'])) ? count($record['v']) : 0;
    }

    /* --------------------------------- sets --------------------------- */

    public function sAdd(string $key, mixed ...$members): int
    {
        return (int)$this->withLock($key, function ($record) use ($key, $members) {
            $set = ($record !== false && $record['t'] === self::TYPE_SET && is_array($record['v'])) ? $record['v'] : [];
            $expire = $record !== false ? (int)($record['e'] ?? 0) : 0;
            $added = 0;
            foreach ($members as $member) {
                $member = (string)$member;
                if (!array_key_exists($member, $set)) {
                    $set[$member] = $member;
                    $added++;
                }
            }
            if ($added > 0 || $record === false) {
                $this->writeRecord($key, self::TYPE_SET, $set, $expire);
            }

            return $added;
        });
    }

    public function sMembers(string $key): array
    {
        $record = $this->readRecord($key);
        if ($record === false || $record['t'] !== self::TYPE_SET || !is_array($record['v'])) {
            return [];
        }

        return array_values($record['v']);
    }

    public function sIsMember(string $key, mixed $member): bool
    {
        $record = $this->readRecord($key);
        if ($record === false || $record['t'] !== self::TYPE_SET || !is_array($record['v'])) {
            return false;
        }

        return array_key_exists((string)$member, $record['v']);
    }

    public function sRem(string $key, mixed ...$members): int
    {
        return (int)$this->withLock($key, function ($record) use ($key, $members) {
            if ($record === false || $record['t'] !== self::TYPE_SET || !is_array($record['v'])) {
                return 0;
            }
            $set = $record['v'];
            $removed = 0;
            foreach ($members as $member) {
                $member = (string)$member;
                if (array_key_exists($member, $set)) {
                    unset($set[$member]);
                    $removed++;
                }
            }
            if ($removed > 0) {
                $this->writeRecord($key, self::TYPE_SET, $set, (int)($record['e'] ?? 0));
            }

            return $removed;
        });
    }

    public function sCard(string $key): int
    {
        return count($this->sMembers($key));
    }

    /* -------------------------------- misc ---------------------------- */

    /**
     * @param int $it cursor, passed by reference like phpredis does
     * @return array|false
     */
    public function scan(?int &$it, string $pattern = '*', int $count = 10): array|false
    {
        if (!$this->enabled || $it === 0) {
            $it = 0;

            return false;
        }
        if (!str_contains($pattern, '*') && !str_contains($pattern, '?') && !str_contains($pattern, '[')) {
            $it = 0;

            return $this->exists($pattern) ? [$pattern] : false;
        }
        $files = glob($this->dir . '/*/*.cache');
        if (empty($files)) {
            $it = 0;

            return false;
        }
        sort($files);
        $total = count($files);
        $offset = $it === null ? 0 : max(0, $it);
        $result = [];
        $count = max(1, $count);
        while ($offset < $total) {
            $raw = @file_get_contents($files[$offset]);
            $offset++;
            if ($raw === false) {
                continue;
            }
            $record = @unserialize($raw, ['allowed_classes' => true]);
            if (!is_array($record) || !isset($record['k'])) {
                continue;
            }
            $expire = (int)($record['e'] ?? 0);
            if ($expire > 0 && $expire <= time()) {
                continue;
            }
            if ($this->matches($pattern, (string)$record['k'])) {
                $result[] = (string)$record['k'];
                if (count($result) >= $count) {
                    break;
                }
            }
        }
        if ($offset >= $total) {
            $it = 0;

            return empty($result) ? false : $result;
        }
        $it = $offset;

        return $result;
    }

    public function keys(string $pattern = '*'): array
    {
        $it = null;
        $keys = [];
        do {
            $chunk = $this->scan($it, $pattern, 500);
            if (!empty($chunk)) {
                $keys = array_merge($keys, $chunk);
            }
        } while ($it > 0);

        return $keys;
    }

    public function flush(): bool
    {
        if (!$this->enabled) {
            return false;
        }
        foreach (glob($this->dir . '/*/*.cache') ?: [] as $file) {
            @unlink($file);
        }

        return true;
    }

    public function info(): array
    {
        $files = glob($this->dir . '/*/*.cache') ?: [];
        $bytes = 0;
        foreach ($files as $file) {
            $bytes += (int)@filesize($file);
        }

        return [
            'redis_version' => '0.0.0',
            'nexus_backend' => 'file',
            'nexus_dir' => $this->dir,
            'nexus_keys' => count($files),
            'used_memory' => $bytes,
        ];
    }

    public function publish(string $channel, string $message): int
    {
        if (function_exists('do_log')) {
            do_log(sprintf('[CACHE PUBLISH] channel: %s, message: %s', $channel, $message), 'debug');
        }

        return 0;
    }

    public function setOption(int $option, mixed $value): bool
    {
        return true;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function eval(string $script, array $args = [], int $numKeys = 0): mixed
    {
        $this->lastError = 'eval is not supported by the file cache backend';

        return false;
    }

    public function multi(int $mode = 0): self
    {
        return $this;
    }

    public function exec(): array
    {
        return [];
    }

    public function close(): bool
    {
        return true;
    }

    public function select(int $database): bool
    {
        return true;
    }

    public function connect(string $host, int $port = 6379, float $timeout = 0.0, mixed $reserved = null, int $retryInterval = 0, float $readTimeout = 0.0): bool
    {
        return true;
    }

    public function pconnect(string $host, int $port = 6379, float $timeout = 0.0, mixed $persistentId = null, int $retryInterval = 0, float $readTimeout = 0.0): bool
    {
        return true;
    }

    public function auth(mixed $credentials): bool
    {
        return true;
    }
}
