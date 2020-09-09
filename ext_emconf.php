<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Redis-based Locking',
    'description' => 'Adds a Locking Strategy for TYPO3 frontend page generation, useful on distributed systems with NFS.',
    'category' => 'fe',
    'version' => '1.1.0',
    'state' => 'stable',
    'clearcacheonload' => true,
    'author' => 'Benni Mack',
    'author_email' => 'typo3@b13.com',
    'author_company' => 'b13 GmbH',
    'constraints' => [
        'depends' => [
                'typo3' => '8.7.0-9.9.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];

