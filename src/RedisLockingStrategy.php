<?php
declare(strict_types=1);

namespace B13\DistributedLocks;

/*
 * This file is part of TYPO3 CMS-based extension "redis_locker" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use TYPO3\CMS\Core\Locking\Exception\LockAcquireException;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\Exception\LockCreateException;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

/**
 * Locking Strategy based on \Redis
 */
class RedisLockingStrategy implements LockingStrategyInterface
{
    /**
     * @var \Redis
     */
    private $backend;

    /**
     * The locking subject (e.g. "pagesection")
     * @var string
     */
    private $subject;

    /**
     * The name of the lock
     * @var string
     */
    private $name;

    /**
     * The name for the mutex lock
     * @var string
     */
    private $mutexName;

    /**
     * The value to store into Redis
     *
     * @var string
     */
    private $value;

    /**
     * @var boolean TRUE if lock is acquired by this locker
     */
    private $isAcquired = false;

    /**
     * The max amount of time within the database for locking in seconds.
     *
     * @var int
     */
    private $ttl = 30;

    /**
     * @inheritdoc
     */
    public function __construct($subject)
    {
        $configuration = $GLOBALS['TYPO3_CONF_VARS']['SYS']['locking']['redis'] ?? null;
        if (!is_array($configuration)) {
            throw new LockCreateException('No configuration for Redis Locking Strategy found. Please configure the redis locking properly',
                1561444886);
        }
        if (!isset($configuration['hostname'])) {
            throw new LockCreateException('No hostname for Redis Locking Strategy found. Please adapt your configuration.',
                1561444887);
        }
        if (!isset($configuration['database'])) {
            throw new LockCreateException('No database for Redis Locking Strategy found. Please adapt your configuration.',
                1561444888);
        }

        if (!isset($configuration['port'])) {
            $configuration['port'] = 6379;
        }
        if (isset($configuration['ttl'])) {
            $this->ttl = (int)$configuration['ttl'];
        }

        $this->subject = $subject;
        $this->name = sprintf('lock:name:%s', $subject);
        $this->mutexName = sprintf('lock:mutex:%s', $subject);
        $this->value = uniqid();

        $this->backend = $this->connectBackend($configuration);
    }

    /**
     * Set up redis backend.
     *
     * @param $configuration
     * @return \Redis
     */
    private function connectBackend($configuration): \Redis
    {
        $backend = new \Redis();
        $backend->connect($configuration['hostname'], (int)$configuration['port']);
        if (!empty($configuration['authentication'])) {
            $backend->auth($configuration['authentication']);
        }
        $backend->select((int)$configuration['database']);
        return $backend;
    }

    /**
     * Releases lock automatically when instance is destroyed and release resources
     */
    public function __destruct()
    {
        $this->release();
    }

    /**
     * @inheritdoc
     */
    public static function getCapabilities()
    {
        return self::LOCK_CAPABILITY_EXCLUSIVE | self::LOCK_CAPABILITY_NOBLOCK;
    }

    /**
     * @inheritdoc
     */
    public static function getPriority()
    {
        return 95;
    }

    /**
     * @inheritdoc
     */
    public function acquire($mode = self::LOCK_CAPABILITY_EXCLUSIVE)
    {
        if ($this->isAcquired) {
            return true;
        }
        if ($mode & self::LOCK_CAPABILITY_EXCLUSIVE) {
            if ($mode & self::LOCK_CAPABILITY_NOBLOCK) {
                // try to acquire the lock - non-blocking
                if (!$this->isAcquired = $this->lock(false)) {
                    throw new LockAcquireWouldBlockException('Could not acquire exclusive lock (non-blocking).',
                        1561445651);
                }
            } else {
                // try to acquire the lock - blocking
                // N.B. we do this in a loop because between
                // wait() and lock() another process may acquire the lock
                while (!$this->isAcquired = $this->lock()) {

                    // this blocks till the lock gets released or timeout is reached
                    if (!$this->wait()) {
                        throw new LockAcquireException('Could not acquire exclusive lock (blocking+exclusive).',
                            1561445710);
                    }
                }
            }
        } else {
            throw new LockAcquireException('Could not acquire lock due to insufficient capabilities.', 1561445737);
        }

        return $this->isAcquired;
    }

    /**
     * @inheritdoc
     */
    public function release()
    {
        if (!$this->isAcquired) {
            return true;
        }
        // Even in an error, the release is locked
        $this->unlockAndSignal();
        $this->isAcquired = false;
        return !$this->isAcquired;
    }

    /**
     * @inheritdoc
     */
    public function destroy()
    {
        $this->release();
    }

    /**
     * @inheritdoc
     */
    public function isAcquired()
    {
        return $this->isAcquired;
    }

    /**
     * Try to lock in the Redis backend
     *
     * @param bool $blocking whether the lock is set or not
     * @return boolean TRUE on success, FALSE otherwise
     */
    private function lock(bool $blocking = true): bool
    {
        // option NX: set value if key is not present
        $result = (bool)$this->backend->set($this->name, $this->value, ['NX', 'EX' => $this->ttl]);
        // Non blocking, but the current request is the same, you're fine.
        if (!$blocking && !$result) {
            if ($this->backend->get($this->name) === $this->value) {
                return true;
            }
        }
        return $result;
    }

    /**
     * Wait on the mutex for the lock being released.
     *
     * See "blPop" (pop the blocking entry based on the ttl). Can probably hardened
     * by using "blPush" and "blPop" in the future.
     *
     * @return string The popped value, FALSE on timeout
     */
    private function wait()
    {
        $blockingTo = max(1, $this->backend->ttl($this->name));
        $result = $this->backend->blPop([$this->mutexName], $blockingTo);

        return is_array($result) ? $result[1] : false;
    }

    /**
     * Try to unlock and if succeeds, signal the mutex for others.
     * By using EVAL transactional behavior is enforced.
     *
     * Thanks to Alexander Miehe <alexander.miehe@tourstream.eu>
     *
     * @return boolean TRUE on success, FALSE otherwise
     */
    private function unlockAndSignal(): bool
    {
        $script = '
            if (redis.call("GET", KEYS[1]) == ARGV[1]) and (redis.call("DEL", KEYS[1]) == 1) then
                return redis.call("RPUSH", KEYS[2], ARGV[1]) and redis.call("EXPIRE", KEYS[2], ARGV[2])
            else
                return 0
            end
        ';
        return (bool)$this->backend->eval($script, [$this->name, $this->mutexName, $this->value, $this->ttl], 2);
    }
}
