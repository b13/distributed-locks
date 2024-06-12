<?php

defined('TYPO3') or die();

(function () {
    if (empty($GLOBALS['TYPO3_CONF_VARS']['SYS']['locking']['redis']['disabled'] ?? false)) {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['locking']['strategies'][\B13\DistributedLocks\RedisLockingStrategy::class] = [];
        $lockFactory = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Locking\LockFactory::class);
        $lockFactory->addLockingStrategy(\B13\DistributedLocks\RedisLockingStrategy::class);
    }

    // Can be removed once TYPO3 v11 support is dropped
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][\TYPO3\CMS\Lowlevel\Controller\ConfigurationController::class]['modifyBlindedConfigurationOptions'][] = \B13\DistributedLocks\BlindedConfigurationOptionsHook::class;

})();
