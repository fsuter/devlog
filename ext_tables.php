<?php
if (!defined('TYPO3_MODE')) {
    die ('Access denied.');
}

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::allowTableOnStandardPages('tx_devlog_domain_model_entry');

// Add context sensitive help (csh) to the devlog table
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addLLrefForTCAdescr(
        'tx_devlog_domain_model_entry',
        'EXT:devlog/Resources/Private/Language/locallang_csh_txdevlog.xlf'
);

// Load the module only in the BE context
if (TYPO3_MODE === 'BE') {
    // Register the "Data Import" backend module
    \TYPO3\CMS\Extbase\Utility\ExtensionUtility::registerModule(
            'Devlog.Devlog',
            // Make it a submodule of 'ExternalImport'
            'system',
            // Submodule key
            'devlog',
            // Position
            'after:BelogLog',
            array(
                // An array holding the controller-action-combinations that are accessible
                'ListModule' => 'index, get'
            ),
            array(
                    'access' => 'admin',
                    'icon' => 'EXT:' . $_EXTKEY . '/Resources/Public/Images/ModuleIcon.svg',
                    'labels' => 'LLL:EXT:' . $_EXTKEY . '/Resources/Private/Language/Module.xlf'
            )
    );
}
