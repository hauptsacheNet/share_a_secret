<?php
defined('TYPO3_MODE') || die('Access denied.');

\TYPO3\CMS\Extbase\Utility\ExtensionUtility::configurePlugin(
    'Hn.HnShareSecret',
    'Secret',
    [
        'Secret' => 'index,new,create,showLink,showSecret,inputPassword,validatePassword,show',
    ],
    // non-cacheable actions
    [
        'Secret' => 'index,new,create,showLink,showSecret,inputPassword,validatePassword,show',
    ]
);