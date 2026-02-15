<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Content Importer',
    'description' => 'Import Markdown content files as TYPO3 pages and content elements',
    'category' => 'module',
    'author' => 'dkd',
    'state' => 'beta',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-13.4.99',
        ],
    ],
];
