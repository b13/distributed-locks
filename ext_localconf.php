<?php

defined('TYPO3_MODE') or die();

(function () {
    use TYPO3\CMS\Core\Utility\GeneralUtility;
    use TYPO3\CMS\Core\Locking\LockFactory;
    use B13\DistributedLocks\RedisLockingStrategy;

    $lockFactory = GeneralUtility::makeInstance(LockFactory::class);
    $lockFactory->addLockingStrategy(RedisLockingStrategy::class);
})();

