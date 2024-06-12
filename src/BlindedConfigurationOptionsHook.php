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

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use TYPO3\CMS\Lowlevel\Event\ModifyBlindedConfigurationOptionsEvent;

/**
 * Hook for $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][\TYPO3\CMS\Lowlevel\Controller\ConfigurationController::class]['modifyBlindedConfigurationOptions']
 */
class BlindedConfigurationOptionsHook
{
    #[AsEventListener]
    public function __invoke(ModifyBlindedConfigurationOptionsEvent $event): void
    {
        $event->setBlindedConfigurationOptions(
            $this->modifyBlindedConfigurationOptions($event->getBlindedConfigurationOptions())
        );
    }

    /**
     * Blind password in ConfigurationOptions
     */
    public function modifyBlindedConfigurationOptions(array $blindedConfigurationOptions): array
    {
        if (!empty($GLOBALS['TYPO3_CONF_VARS']['SYS']['locking']['redis']['password'])) {
            $blindedConfigurationOptions['TYPO3_CONF_VARS']['SYS']['locking']['redis']['password'] = '******';
        }

        return $blindedConfigurationOptions;
    }
}
