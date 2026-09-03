<?php

declare(strict_types=1);

namespace B13\DistributedLocks;

/*
 * This file is part of TYPO3 CMS-based extension "distributed_locks" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireException;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\Exception\LockCreateException;
use TYPO3\CMS\Core\Locking\FileLockStrategy;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Locking Strategy based on \Redis
 */
class RedisLockingStrategy implements LockingStrategyInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * Default priority for this locking strategy
     */
    private const DEFAULT_PRIORITY = 95;

    /**
     * Time in seconds an unreachable Redis server is not contacted again within the same PHP process.
     */
    private const CONNECTION_RETRY_INTERVAL = 30;

    /**
     * Time of the last failed connection attempt, shared by all locks of this PHP process, so a
     * request does not run into the connection timeout again for every single lock it creates.
     */
    private static ?float $connectionFailureTime = null;

    /**
     * The Redis connection, NULL if the Redis server is not available (see $fallbackStrategy)
     */
    private ?\Redis $backend = null;

    /**
     * Used instead of Redis while the Redis server is unavailable. NULL means "no locking at all".
     */
    private ?LockingStrategyInterface $fallbackStrategy = null;

    /**
     * The configuration of $GLOBALS['TYPO3_CONF_VARS']['SYS']['locking']['redis']
     */
    private array $configuration;

    /**
     * Whether an unavailable Redis server should be survived instead of throwing an exception
     */
    private bool $gracefulDegradation;

    /**
     * The locking subject (e.g. "pagesection")
     */
    private string $subject;

    /**
     * The name of the lock
     */
    private string $name;

    /**
     * The name for the mutex lock
     */
    private string $mutexName;

    /**
     * The value to store into Redis
     */
    private string $value;

    /**
     * TRUE if lock is acquired by this locker
     */
    private bool $isAcquired = false;

    /**
     * The max amount of time within the database for locking in seconds.
     */
    private int $ttl = 30;

    public function __construct($subject)
    {
        $configuration = $GLOBALS['TYPO3_CONF_VARS']['SYS']['locking']['redis'] ?? null;
        if (!is_array($configuration)) {
            throw new LockCreateException(
                'No configuration for Redis Locking Strategy found. Please configure the redis locking properly',
                1561444886
            );
        }
        if (!isset($configuration['hostname'])) {
            throw new LockCreateException(
                'No hostname for Redis Locking Strategy found. Please adapt your configuration.',
                1561444887
            );
        }
        if (!isset($configuration['database'])) {
            throw new LockCreateException(
                'No database for Redis Locking Strategy found. Please adapt your configuration.',
                1561444888
            );
        }

        if (!isset($configuration['port'])) {
            $configuration['port'] = 6379;
        }
        if (isset($configuration['ttl'])) {
            $this->ttl = (int)$configuration['ttl'];
        }

        $redisKeyPrefix = sha1($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] . '_REDIS_LOCKING');
        $this->configuration = $configuration;
        $this->gracefulDegradation = (bool)($configuration['gracefulDegradation'] ?? true);
        $this->subject = $subject;
        $this->name = sprintf('%s:lock:name:%s', $redisKeyPrefix, $subject);
        $this->mutexName = sprintf('%s:lock:mutex:%s', $redisKeyPrefix, $subject);
        $this->value = uniqid();

        if ($this->gracefulDegradation && self::hasRecentConnectionFailure()) {
            // The Redis server was unreachable a moment ago, do not wait for the connection timeout again
            $this->fallbackStrategy = $this->createFallbackStrategy();
            return;
        }

        try {
            $this->backend = $this->connectBackend($configuration);
            self::$connectionFailureTime = null;
        } catch (\Throwable $e) {
            if (!$this->gracefulDegradation) {
                throw new LockCreateException(
                    'Could not connect to the Redis server for locking: ' . $e->getMessage(),
                    1788393600,
                    $e
                );
            }
            $this->handleRedisFailure('Could not connect to Redis for locking', $e);
        }
    }

    /**
     * Set up redis backend.
     */
    private function connectBackend(array $configuration): \Redis
    {
        $backend = new \Redis();
        $host = $configuration['hostname'];
        $port = (int)$configuration['port'];
        $connectionTimeout = (float)($configuration['connectionTimeout'] ?? 0.0);
        $database = (int)$configuration['database'];

        if (($configuration['persistentConnection'] ?? false)) {
            $backend->pconnect(
                $host,
                $port,
                $connectionTimeout,
                (string)$database,
            );
        } else {
            $backend->connect(
                $host,
                $port,
                $connectionTimeout,
            );
        }
        $authentication = $this->getAuthentication($configuration);
        if ($authentication !== null) {
            $backend->auth($authentication);
        }
        $backend->select($database);
        return $backend;
    }

    /**
     * Releases lock automatically when instance is destroyed and release resources
     */
    public function __destruct()
    {
        $this->release();
    }

    public static function getCapabilities(): int
    {
        return self::LOCK_CAPABILITY_EXCLUSIVE | self::LOCK_CAPABILITY_NOBLOCK;
    }

    public static function getPriority(): int
    {
        $configuration = $GLOBALS['TYPO3_CONF_VARS']['SYS']['locking']['redis'] ?? null;
        if (is_array($configuration) && isset($configuration['priority'])) {
            $priority = (int)$configuration['priority'];
        } else {
            $priority = self::DEFAULT_PRIORITY;
        }
        return $priority;
    }

    public function acquire($mode = self::LOCK_CAPABILITY_EXCLUSIVE): bool
    {
        if ($this->isAcquired) {
            return true;
        }
        if ($mode & self::LOCK_CAPABILITY_EXCLUSIVE) {
            if ($mode & self::LOCK_CAPABILITY_NOBLOCK) {
                // try to acquire the lock - non-blocking
                if (!$this->isAcquired = $this->lock(false)) {
                    throw new LockAcquireWouldBlockException(
                        'Could not acquire exclusive lock (non-blocking).',
                        1561445651
                    );
                }
            } else {
                // try to acquire the lock - blocking
                // N.B. we do this in a loop because between
                // wait() and lock() another process may acquire the lock
                while (!$this->isAcquired = $this->lock()) {
                    // this blocks till the lock gets released or timeout is reached
                    // if Redis went away while waiting, the next lock() uses the fallback strategy
                    if ($this->wait() === null && $this->backend !== null) {
                        throw new LockAcquireException(
                            'Could not acquire exclusive lock (blocking+exclusive).',
                            1561445710
                        );
                    }
                }
            }
        } else {
            throw new LockAcquireException('Could not acquire lock due to insufficient capabilities.', 1561445737);
        }

        return $this->isAcquired;
    }

    public function release(): bool
    {
        if (!$this->isAcquired) {
            return true;
        }
        $this->isAcquired = false;
        if ($this->backend === null) {
            return $this->fallbackStrategy?->release() ?? true;
        }
        // Even in an error, the release is locked
        $this->unlockAndSignal();
        return true;
    }

    public function destroy(): void
    {
        $this->release();
        $this->fallbackStrategy?->destroy();
    }

    public function isAcquired(): bool
    {
        return $this->isAcquired;
    }

    /**
     * Try to lock in the Redis backend
     *
     * @param bool $blocking whether the lock is set or not
     * @return bool TRUE on success, FALSE otherwise
     */
    private function lock(bool $blocking = true): bool
    {
        if ($this->backend === null) {
            return $this->lockWithFallback($blocking);
        }
        try {
            // option NX: set value if key is not present
            $result = (bool)$this->backend->set($this->name, $this->value, ['NX', 'EX' => $this->ttl]);
            // Non-blocking, but the current request is the same, you're fine.
            if (!$blocking && !$result) {
                if ($this->backend->get($this->name) === $this->value) {
                    return true;
                }
            }
            return $result;
        } catch (\RedisException $e) {
            $this->handleRedisFailure('Could not lock in Redis', $e);
            if ($this->backend === null) {
                return $this->lockWithFallback($blocking);
            }
        } catch (\Throwable $e) {
            $this->logger()->critical('Could not lock in Redis', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
        return false;
    }

    /**
     * Acquire the lock without Redis, either via the configured fallback strategy or - if there is
     * none - by not locking at all. The latter may result in the same page being generated by
     * several processes in parallel, which is still better than a broken website.
     */
    private function lockWithFallback(bool $blocking): bool
    {
        if ($this->fallbackStrategy === null) {
            return true;
        }
        return $this->fallbackStrategy->acquire(
            $blocking
                ? self::LOCK_CAPABILITY_EXCLUSIVE
                : self::LOCK_CAPABILITY_EXCLUSIVE | self::LOCK_CAPABILITY_NOBLOCK
        );
    }

    /**
     * Wait on the mutex for the lock being released.
     *
     * See "blPop" (pop the blocking entry based on the ttl). Can probably hardened
     * by using "blPush" and "blPop" in the future.
     *
     * @return string|null The popped value, null on timeout
     */
    private function wait(): ?string
    {
        try {
            $blockingTo = max(1, $this->backend->ttl($this->name));
            $result = $this->backend->blPop([$this->mutexName], $blockingTo);
            return $result[1] ?? null;
        } catch (\RedisException $e) {
            $this->handleRedisFailure('Failure while waiting on redis', $e);
        } catch (\Throwable $e) {
            $this->logger()->critical('Failure while waiting on redis', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
        return null;
    }

    /**
     * Try to unlock and if succeeds, signal the mutex for others.
     * By using EVAL transactional behavior is enforced.
     *
     * Thanks to Alexander Miehe <alexander.miehe@tourstream.eu>
     *
     * @return bool TRUE on success, FALSE otherwise
     */
    private function unlockAndSignal(): bool
    {
        try {
            $script = '
            if (redis.call("GET", KEYS[1]) == ARGV[1]) and (redis.call("DEL", KEYS[1]) == 1) then
                return redis.call("RPUSH", KEYS[2], ARGV[1]) and redis.call("EXPIRE", KEYS[2], ARGV[2])
            else
                return 0
            end
        ';
            return (bool)$this->backend->eval($script, [$this->name, $this->mutexName, $this->value, $this->ttl], 2);
        } catch (\RedisException $e) {
            $this->handleRedisFailure('Failure while unlocking in Redis', $e);
        } catch (\Throwable $e) {
            $this->logger()->critical('Failure while unlocking in Redis', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
        return false;
    }

    /**
     * The Redis server is not available (anymore): log the problem and - unless graceful degradation
     * has been switched off - continue with the fallback strategy for this and all further locks of
     * this PHP process, instead of letting a Redis outage take down the whole website.
     */
    private function handleRedisFailure(string $message, \Throwable $e): void
    {
        $this->logger()->critical($message, [
            'message' => $e->getMessage(),
            'exception' => $e,
        ]);
        if (!$this->gracefulDegradation) {
            return;
        }
        $this->backend = null;
        self::$connectionFailureTime = microtime(true);
        $this->fallbackStrategy = $this->createFallbackStrategy();
    }

    /**
     * Create the locking strategy to use while Redis is unavailable, by default TYPO3's
     * FileLockStrategy. Set the option "fallbackStrategy" to NULL to run without any locking
     * in that case. Returns NULL if no (usable) fallback strategy is available.
     */
    private function createFallbackStrategy(): ?LockingStrategyInterface
    {
        $className = $this->configuration['fallbackStrategy'] ?? FileLockStrategy::class;
        if (empty($className)) {
            return null;
        }
        try {
            if (!is_string($className) || !is_subclass_of($className, LockingStrategyInterface::class)) {
                throw new LockCreateException(
                    'The configured fallback locking strategy does not implement the LockingStrategyInterface.',
                    1788393601
                );
            }
            if (($className::getCapabilities() & self::getCapabilities()) !== self::getCapabilities()) {
                throw new LockCreateException(
                    'The configured fallback locking strategy "' . $className . '" does not provide the required capabilities.',
                    1788393602
                );
            }
            return new $className($this->subject);
        } catch (\Throwable $e) {
            $this->logger()->critical('Could not create the fallback locking strategy, continuing without locking', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
        return null;
    }

    private static function hasRecentConnectionFailure(): bool
    {
        return self::$connectionFailureTime !== null
            && (microtime(true) - self::$connectionFailureTime) < self::CONNECTION_RETRY_INTERVAL;
    }

    /**
     * LockFactory instantiates locking strategies via "new" instead of makeInstance(), so the
     * logger is never injected - fetch it lazily, otherwise logging a Redis problem would end in
     * "Call to a member function critical() on null".
     */
    private function logger(): LoggerInterface
    {
        if ($this->logger === null) {
            try {
                $this->logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(static::class);
            } catch (\Throwable) {
                // Locking may be used very early in the bootstrap process, where this is not available yet
                $this->logger = new NullLogger();
            }
        }
        return $this->logger;
    }

    /**
     * Build the authentication value based on the configuration, returning an associative array
     * in case `username` and `password` or `password` if only password has been configured.
     * Return `null` to indicate no-authentication configuration, which is also possible to be used with `redis`.
     */
    private function getAuthentication($configuration): ?array
    {
        return match (true) {
            empty($configuration['username']) && !empty($configuration['password']) => [
                (string)$configuration['password'],
            ],
            !empty($configuration['username']) && !empty($configuration['password']) => [
                (string)$configuration['username'],
                (string)$configuration['password'],
            ],
            default => null,
        };
    }
}
