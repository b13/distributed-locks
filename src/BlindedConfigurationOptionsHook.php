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

use TYPO3\CMS\Lowlevel\Event\ModifyBlindedConfigurationOptionsEvent;

/**
 * Blinds the redis password in the backend "Configuration" module.
 * Registered as a PSR-14 event listener in Configuration/Services.yaml.
 */
class BlindedConfigurationOptionsHook
{
    public function __invoke(ModifyBlindedConfigurationOptionsEvent $event): void
    {
        $event->setBlindedConfigurationOptions(
            $this->modifyBlindedConfigurationOptions($event->getBlindedConfigurationOptions()),
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
