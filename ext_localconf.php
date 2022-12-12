<?php

defined('TYPO3_MODE') or die();

(function () {
    if (empty($GLOBALS['TYPO3_CONF_VARS']['SYS']['locking']['redis']['disabled'] ?? false)) {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['locking']['strategies'][\B13\DistributedLocks\RedisLockingStrategy::class] = [];
        $lockFactory = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Locking\LockFactory::class);
        $lockFactory->addLockingStrategy(\B13\DistributedLocks\RedisLockingStrategy::class);
    }
})();
