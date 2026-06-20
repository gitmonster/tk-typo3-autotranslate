<?php

declare(strict_types=1);

use ThieleUndKlose\Autotranslate\Utility\DeeplApiHelper;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use ThieleUndKlose\Autotranslate\Utility\TranslationHelper;

$siteConfiguration = isset($_REQUEST['site'])
    ? GeneralUtility::makeInstance(SiteFinder::class)->getSiteByIdentifier($_REQUEST['site'])->getConfiguration()
    : null;

$palettes = [];
$translationPrefix = 'LLL:EXT:autotranslate/Resources/Private/Language/locallang.xlf:';
$translate = static function (string $key) use ($translationPrefix): string {
    $label = ($GLOBALS['LANG'] ?? null)?->sL($translationPrefix . $key);
    return is_string($label) && $label !== '' ? $label : $key;
};

$deeplAuthKeyDescription = [$translate('site_configuration.deepl.auth_key.help')];

if (!empty($siteConfiguration['deeplAuthKey'])) {
    $source = null;
    $apiKey = $siteConfiguration['deeplAuthKey'];
} else {
    ['key' => $apiKey, 'source' => $source] = TranslationHelper::apiKey();
}

$deeplApiKeyDetails = DeeplApiHelper::checkApiKey($apiKey);
if ($source) {
    $maskedApiKey = str_repeat('*', 20) . substr($apiKey, 20);
    $deeplAuthKeyDescription[] = $translate('site_configuration.deepl.auth_key.defined_prefix') . ' ' . $source . ' (' . $maskedApiKey . ')';
}
if ($deeplApiKeyDetails['usage']) {
    $usage = (string)$deeplApiKeyDetails['usage'];
    $usage = str_replace([PHP_EOL, 'Characters: '], [' ', ''], $usage);
    $deeplAuthKeyDescription[] = trim($usage) . ' ' . $translate('site_configuration.deepl.auth_key.characters_suffix');
}
if ($deeplApiKeyDetails['error']) {
    $deeplAuthKeyDescription[] = $deeplApiKeyDetails['error'];
}

// DeepL Auth Key
$GLOBALS['SiteConfiguration']['site']['columns']['deeplAuthKey'] = [
    'label' => 'LLL:EXT:autotranslate/Resources/Private/Language/locallang.xlf:site_configuration.deepl.auth_key.label',
    'description' => implode(PHP_EOL, $deeplAuthKeyDescription),
    'config' => [
        'type' => 'input',
        'size' => 50,
    ],
];
$palettes['deeplAuthKey'] = ['showitem' => 'deeplAuthKey'];

$GLOBALS['SiteConfiguration']['site']['columns']['autotranslateUseDeeplGlossary'] = [
    'label' => 'LLL:EXT:autotranslate/Resources/Private/Language/locallang.xlf:site_configuration.deepl.glossary.label',
    'description' => 'LLL:EXT:autotranslate/Resources/Private/Language/locallang.xlf:site_configuration.deepl.glossary.description',
    'config' => [
        'type' => 'check',
        'renderType' => 'checkboxToggle',
        'default' => 0,
        'items' => [
            [
                'label' => '',
            ]
        ],
    ],
];
$palettes['deeplGlossary'] = ['showitem' => 'autotranslateUseDeeplGlossary'];

$GLOBALS['SiteConfiguration']['site']['columns']['autotranslateDeeplFormality'] = [
    'label' => 'LLL:EXT:autotranslate/Resources/Private/Language/locallang.xlf:site_configuration.deepl.formality.label',
    'description' => 'LLL:EXT:autotranslate/Resources/Private/Language/locallang.xlf:site_configuration.deepl.formality.description',
    'config' => [
        'type' => 'select',
        'renderType' => 'selectSingle',
        'items' => [
            [
                'label' => 'LLL:EXT:autotranslate/Resources/Private/Language/locallang.xlf:site_configuration.deepl.formality.option.default',
                'value' => 'default',
            ],
            [
                'label' => 'LLL:EXT:autotranslate/Resources/Private/Language/locallang.xlf:site_configuration.deepl.formality.option.informal',
                'value' => 'prefer_less',
            ],
            [
                'label' => 'LLL:EXT:autotranslate/Resources/Private/Language/locallang.xlf:site_configuration.deepl.formality.option.formal',
                'value' => 'prefer_more',
            ],
        ],
        'default' => 'default',
        'minitems' => 1,
        'maxitems' => 1,
        'size' => 1,
    ],
];
$palettes['deeplFormality'] = ['showitem' => 'autotranslateDeeplFormality'];

// Translatable tables configuration
$tablesToTranslate = TranslationHelper::tablesToTranslate();

$possibleTranslationLanguages = array_map(
    fn($v) => $v['languageId'] . ' => ' . ($v['title'] ?? 'no title defined'),
    TranslationHelper::possibleTranslationLanguages($siteConfiguration['languages'] ?? [])
);
$possibleTranslationLanguagesDescription = !empty($possibleTranslationLanguages)
    ? $translate('site_configuration.table.languages.description_prefix') . ' (' . implode(', ', $possibleTranslationLanguages) . ')'
    : $translate('site_configuration.table.languages.description_no_languages');

foreach ($tablesToTranslate as $table) {
    $tableUpperCamelCase = GeneralUtility::underscoredToUpperCamelCase($table);
    $additionalFields = [];

    $fieldname = TranslationHelper::configurationFieldname($table, 'enabled');
    $GLOBALS['SiteConfiguration']['site']['columns'][$fieldname] = [
        'label' => $translate('site_configuration.table.enabled.label_prefix') . ' ' . $tableUpperCamelCase,
        'config' => [
            'type' => 'check',
            'renderType' => 'checkboxToggle',
            'default' => 0,
            'items' => [
                [
                    'label' => '',
                ]
            ],
        ],
    ];
    $additionalFields[] = $fieldname;

    $fieldname = TranslationHelper::configurationFieldname($table, 'languages');
    $GLOBALS['SiteConfiguration']['site']['columns'][$fieldname] = [
        'label' => 'LLL:EXT:autotranslate/Resources/Private/Language/locallang.xlf:site_configuration.table.languages.label',
        'description' => $possibleTranslationLanguagesDescription,
        'config' => [
            'type' => 'input',
            'size' => 20,
        ],
    ];
    $additionalFields[] = $fieldname;

    // Only show if there are textfields to translate
    if (!empty(TranslationHelper::unusedTranslateableColumns($table, '', TranslationHelper::COLUMNS_TRANSLATEABLE_GROUP_TEXTFIELD))) {
        $fieldname = TranslationHelper::configurationFieldname($table, 'textfields');
        $fieldsUnusedTextField = TranslationHelper::unusedTranslateableColumns($table, $siteConfiguration[$fieldname] ?? '', TranslationHelper::COLUMNS_TRANSLATEABLE_GROUP_TEXTFIELD);
        $descriptionAppendix = !empty($fieldsUnusedTextField) ? PHP_EOL . ' ' . $translate('site_configuration.table.unused_prefix') . ' ' . implode(', ', $fieldsUnusedTextField) : '';
        $GLOBALS['SiteConfiguration']['site']['columns'][$fieldname] = [
            'label' => 'LLL:EXT:autotranslate/Resources/Private/Language/locallang.xlf:site_configuration.table.textfields.label',
            'description' => $translate('site_configuration.table.columns_comma_list.description') . $descriptionAppendix,
            'config' => [
                'type' => 'text',
                'cols' => 80,
                'rows' => 5,
            ],
        ];
        $additionalFields[] = $fieldname;
    }

    // Only show if there are file references to translate
    if (!empty(TranslationHelper::unusedTranslateableColumns($table, '', TranslationHelper::COLUMNS_TRANSLATEABLE_GROUP_FILEREFERENCE))) {
        $fieldname = TranslationHelper::configurationFieldname($table, 'fileReferences');
        $fieldsUnusedFileReference = TranslationHelper::unusedTranslateableColumns($table, $siteConfiguration[$fieldname] ?? '', TranslationHelper::COLUMNS_TRANSLATEABLE_GROUP_FILEREFERENCE);
        $descriptionAppendix = !empty($fieldsUnusedFileReference) ? PHP_EOL . ' ' . $translate('site_configuration.table.unused_prefix') . ' ' . implode(', ', $fieldsUnusedFileReference) : '';
        $GLOBALS['SiteConfiguration']['site']['columns'][$fieldname] = [
            'label' => 'LLL:EXT:autotranslate/Resources/Private/Language/locallang.xlf:site_configuration.table.file_references.label',
            'description' => $translate('site_configuration.table.columns_comma_list.description') . $descriptionAppendix,
            'config' => [
                'type' => 'text',
                'cols' => 80,
                'rows' => 5,
            ],
        ];
        $additionalFields[] = $fieldname;
    }

    if (!empty($additionalFields)) {
        $palettes['autotranslate' . $tableUpperCamelCase] = ['showitem' => implode(', --linebreak--, ', $additionalFields)];
    }
}

$referenceTablesToTranslate = TranslationHelper::additionalReferenceTables();
foreach ($referenceTablesToTranslate as $table) {
    $tableUpperCamelCase = GeneralUtility::underscoredToUpperCamelCase($table);
    $fieldname = TranslationHelper::configurationFieldname($table, 'textfields');
    $fieldsUnusedTextField = TranslationHelper::unusedTranslateableColumns($table, $siteConfiguration[$fieldname] ?? '', TranslationHelper::COLUMNS_TRANSLATEABLE_GROUP_TEXTFIELD);
    $descriptionAppendix = !empty($fieldsUnusedTextField) ? PHP_EOL . ' ' . $translate('site_configuration.table.unused_prefix') . ' ' . implode(', ', $fieldsUnusedTextField) : '';
    $GLOBALS['SiteConfiguration']['site']['columns'][$fieldname] = [
        'label' => $translate('site_configuration.table.reference_textfields.label_prefix') . ' ' . $tableUpperCamelCase,
        'description' => $translate('site_configuration.table.columns_comma_list.description') . $descriptionAppendix,
        'config' => [
            'type' => 'text',
            'cols' => 80,
            'rows' => 5,
        ],
    ];
    $palettes['autotranslate' . $tableUpperCamelCase] = ['showitem' => $fieldname];
}

// Translation workspace: route every autotranslate into the selected workspace
// (as a draft) instead of the live record. "Live" (= 0) keeps the default behaviour.
$GLOBALS['SiteConfiguration']['site']['columns']['autotranslateWorkspaceId'] = [
    'label' => 'Translation workspace',
    'description' => 'Workspace all autotranslate output is routed into (via DataHandler). '
        . 'Leave "Live" to write translations directly to the live record as before. '
        . 'When a workspace is selected, batch and single translations land there as '
        . 'drafts until published, regardless of where the translation is triggered.',
    'config' => [
        'type' => 'select',
        'renderType' => 'selectSingle',
        'itemsProcFunc' => 'ThieleUndKlose\\Autotranslate\\UserFunction\\FormEngine\\WorkspaceItems->itemsProcFunc',
        'default' => 0,
    ],
];
$palettes['autotranslateWorkspace'] = ['showitem' => 'autotranslateWorkspaceId'];

$GLOBALS['SiteConfiguration']['site']['palettes'] = array_merge($GLOBALS['SiteConfiguration']['site']['palettes'], $palettes);
$showItem = ',--palette--;;' . implode(',--palette--;;', array_keys($palettes));
$GLOBALS['SiteConfiguration']['site']['types']['0']['showitem'] .= ', --div--;Autotranslate' . $showItem;

// DeepL source language selection
$deeplSourceLangItems = [];
if (!$deeplApiKeyDetails['isValid']) {
    $deeplSourceLangItems[] = ['label' => $translate('site_configuration.deepl.select.invalid_api_key'), 'value' => ''];
} else {
    $deeplSourceLangItems[] = ['label' => $translate('site_configuration.deepl.select.please_choose'), 'value' => ''];
    foreach (DeeplApiHelper::getCachedLanguages($apiKey, 'source') as $langItem) {
        $deeplSourceLangItems[] = ['label' => $langItem[0], 'value' => $langItem[1]];
    }
}

$GLOBALS['SiteConfiguration']['site_language']['columns']['deeplSourceLang'] = [
    'label' => 'LLL:EXT:autotranslate/Resources/Private/Language/locallang.xlf:site_configuration.deepl.source_language.label',
    'displayCond' => 'FIELD:languageId:=:0',
    'description' => 'LLL:EXT:autotranslate/Resources/Private/Language/locallang.xlf:site_configuration.deepl.source_language.description',
    'config' => [
        'type' => 'select',
        'renderType' => 'selectSingle',
        'items' => $deeplSourceLangItems,
        'minitems' => 0,
        'maxitems' => 1,
        'size' => 1,
        'readOnly' => !$deeplApiKeyDetails['isValid'] || count($deeplSourceLangItems) <= 1,
    ],
];

// DeepL target language selection
$deeplTargetLangItems = [];
if (!$deeplApiKeyDetails['isValid']) {
    $deeplTargetLangItems[] = ['label' => $translate('site_configuration.deepl.select.invalid_api_key'), 'value' => ''];
} else {
    $deeplTargetLangItems[] = ['label' => $translate('site_configuration.deepl.select.please_choose'), 'value' => ''];
    foreach (DeeplApiHelper::getCachedLanguages($apiKey, 'target') as $langItem) {
        $deeplTargetLangItems[] = ['label' => $langItem[0], 'value' => $langItem[1]];
    }
}

$GLOBALS['SiteConfiguration']['site_language']['columns']['deeplTargetLang'] = [
    'label' => 'LLL:EXT:autotranslate/Resources/Private/Language/locallang.xlf:site_configuration.deepl.target_language.label',
    'displayCond' => 'FIELD:languageId:>:0',
    'description' => 'LLL:EXT:autotranslate/Resources/Private/Language/locallang.xlf:site_configuration.deepl.target_language.description',
    'config' => [
        'type' => 'select',
        'renderType' => 'selectSingle',
        'items' => $deeplTargetLangItems,
        'minitems' => 0,
        'maxitems' => 1,
        'size' => 1,
        'readOnly' => !$deeplApiKeyDetails['isValid'] || count($deeplTargetLangItems) <= 1,
    ],
];

$GLOBALS['SiteConfiguration']['site_language']['palettes']['autotranslate'] = [
    'showitem' => 'deeplSourceLang,deeplTargetLang',
];

$GLOBALS['SiteConfiguration']['site_language']['types']['1']['showitem'] = str_replace(
    '--palette--;;default,',
    '--palette--;;default, --palette--;LLL:EXT:autotranslate/Resources/Private/Language/locallang.xlf:site_configuration.deepl.title;autotranslate,',
    $GLOBALS['SiteConfiguration']['site_language']['types']['1']['showitem']
);
