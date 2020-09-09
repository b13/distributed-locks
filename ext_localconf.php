<?php

defined('TYPO3_MODE') or die();

(function () {
    $lockFactory = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Locking\LockFactory::class);
    $lockFactory->addLockingStrategy(\B13\DistributedLocks\RedisLockingStrategy::class);
})();

