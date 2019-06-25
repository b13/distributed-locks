# Distributed Redis Locker for TYPO3 Locking Mechanism

TYPO3 has three built-in Locking Strategies, that are chosen at runtime which fits best for the current system
setup.

* SemaphoreLockStrategy
* SimpleLockStrategy
* FileLockStrategy

However, when dealing with a multi-tier system and a shared file system with multiple frontend nodes with a NFS
filesystem, it is especially helpful to use a better suitable format. Redis is our weapon of choice for handling
multi-node scenarios, and works just fine with TYPO3 and Caching.

This extension provides a Redis Locking mechanism to store the locks in a (shared) Redis database.

## Requirements

This extension is available for TYPO3 v8+ LTS, and requires the PHP package `php-redis` as well as a Redis server.

## Installation

Install this extension via composer `composer require b13/redis-locker` or extensions.typo3.org / Extension Manager,
and activate it in the Extension Manager.

Now add the following lines to your LocalConfiguration / AdditionalConfiguration to activate Redis Locking.

    $GLOBALS['TYPO3_CONF_VARS']['SYS']['locking']['redis'] = [
        'hostname' => '127.0.0.1',
        'database' => 12
    ];

Other options:

    'ttl' (numeric, default is 30sec)
    'port' (numeric, default is 6379)
    'authentication' (contains the password, necessary for secure authentication if required by redis)

## Future Development

Should be switched to `symfony/lock` to allow distributed redis services and other lockers.

## Credits & Background 

Inspiration was taken from the now unmaintained extension "redis_lock_strategy" which we used several times, however
with some drawbacks:

* No stable version for TYPO3 v9 in composer mode
* No maintainer available for releases anymore
* Destroying an object did not remove locks, ending in certain dead lock scenarios in broken scripts

Thanks to Alexander Miehe for the initial extension and the conceptual work.
