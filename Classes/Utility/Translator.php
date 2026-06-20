<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Utility;

use DeepL\TranslateTextOptions;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use ThieleUndKlose\Autotranslate\Service\GlossaryService;
use ThieleUndKlose\Autotranslate\Service\TranslationCacheService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use ThieleUndKlose\Autotranslate\Domain\Dto\Glossary;

final class Translator implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const AUTOTRANSLATE_LAST = 'autotranslate_last';
    public const AUTOTRANSLATE_EXCLUDE = 'autotranslate_exclude';
    public const AUTOTRANSLATE_LANGUAGES = 'autotranslate_languages';

    public const TRANSLATE_MODE_BOTH = 'create_update';
    public const TRANSLATE_MODE_UPDATE_ONLY = 'update_only';
    public const TRANSLATE_MODE_CREATE_ONLY = 'create_only';

    /** @var list<string> Fields ignored when detecting changes on record update */
    private const CHANGE_DETECTION_IGNORE_FIELDS = [
        'pid',
        'sorting',
        'crdate',
        'tstamp',
        'cruser_id',
        'deleted',
        'hidden',
        'starttime',
        'endtime',
        'fe_group',
        'sys_language_uid',
        'l10n_parent',
        'l10n_diffsource',
        'l10n_state',
        't3ver_oid',
        't3ver_id',
        't3ver_wsid',
        't3ver_label',
        't3ver_state',
        't3ver_stage',
        't3ver_count',
        't3ver_tstamp',
        't3ver_move_id',
        self::AUTOTRANSLATE_LAST,
        self::AUTOTRANSLATE_EXCLUDE,
        self::AUTOTRANSLATE_LANGUAGES,
        SourceHashUtility::FIELD_NAME,
    ];

    private readonly ?string $apiKey;
    private readonly array $siteLanguages;
    private readonly string $deeplFormality;
    private readonly GlossaryService $glossaryService;

    public function __construct(private readonly int $pageId)
    {
        ['key' => $this->apiKey] = TranslationHelper::apiKey($this->pageId);
        $siteConfiguration = TranslationHelper::siteConfigurationValue($this->pageId);
        $this->siteLanguages = TranslationHelper::siteConfigurationValue($this->pageId, ['languages']) ?? [];
        $this->deeplFormality = is_array($siteConfiguration) ? (string)($siteConfiguration['autotranslateDeeplFormality'] ?? 'default') : 'default';
        $this->glossaryService = GeneralUtility::makeInstance(GlossaryService::class);
    }

    /**
     * Translate the loaded record to target languages
     *
     * @throws \RuntimeException If DeepL API key is invalid or has no characters left
     */
    public function translate(
        string $table,
        int $recordUid,
        ?DataHandler $parentObject = null,
        ?string $languagesToTranslate = null,
        string $translateMode = self::TRANSLATE_MODE_BOTH,
        ?string $datamapStatus = null,
        ?array $changedFields = null,
    ): void {
        $record = Records::getRecord($table, $recordUid);

        if ($record === null) {
            LogUtility::log($this->logger, 'Record {table}:{uid} not found, skipping translation.', [
                'table' => $table,
                'uid' => $recordUid,
            ], LogUtility::MESSAGE_WARNING);
            return;
        }

        // Exit if record is a localized one
        $parentField = TranslationHelper::translationOrigPointerField($table);
        if ($parentField === null || (int)($record[$parentField] ?? 0) > 0) {
            return;
        }

        // Exit if record is marked for exclude
        if ((int)($record[self::AUTOTRANSLATE_EXCLUDE] ?? 0) === 1) {
            return;
        }

        // Load translation columns for table
        $columns = TranslationHelper::translationTextfields($this->pageId, $table);
        if ($columns === null || $columns === []) {
            LogUtility::log($this->logger, 'No text fields configured for table {table} on page {pageId}. Check site configuration.', [
                'table' => $table,
                'pageId' => $this->pageId,
            ], LogUtility::MESSAGE_WARNING);
            return;
        }

        $singleTargetLanguageId = $this->resolveSingleTargetLanguageId($languagesToTranslate);

        $columnsToTranslate = $this->resolveColumnsToTranslate(
            $columns,
            $datamapStatus,
            $changedFields,
            $record,
            $singleTargetLanguageId,
            $table,
            $recordUid
        );
        $needsReferenceTranslation = $columnsToTranslate === []
            && $this->recordsNeedReferenceTranslation($table, $recordUid, $datamapStatus, $changedFields, $singleTargetLanguageId);

        if ($columnsToTranslate === [] && !$needsReferenceTranslation) {
            LogUtility::log($this->logger, 'No changed translatable fields for {table}:{uid}, skipping translation.', [
                'table' => $table,
                'uid' => $recordUid,
            ]);
            return;
        }

        $this->assertApiKeyIsUsable();

        // Set target languages by record if null is given
        if ($languagesToTranslate === null) {
            $languagesToTranslate = $record[self::AUTOTRANSLATE_LANGUAGES] ?? '';
        }
        
        // Fall back to site configuration default if record has no languages set
        if ($languagesToTranslate === '' || $languagesToTranslate === null) {
            $siteConfig = TranslationHelper::siteConfigurationValue($this->pageId);
            if (is_array($siteConfig)) {
                $settings = TranslationHelper::translationSettingsDefaults($siteConfig, $table);
                $languagesToTranslate = $settings['autotranslateLanguages'] ?? '';
            }
        }

        $localizedContents = [];
        $languageIds = GeneralUtility::trimExplode(',', $languagesToTranslate, true);

        if ($languageIds === []) {
            LogUtility::log($this->logger, 'No target languages set for record {table}:{uid}.', [
                'table' => $table,
                'uid' => $recordUid,
            ], LogUtility::MESSAGE_WARNING);
            return;
        }

        $didTranslate = false;
        $translatedFieldNames = [];

        foreach ($languageIds as $languageId) {
            $localizedContents[$languageId] = [];

            // Skip translation if language matches original record
            if ((int)$languageId === (int)$record['sys_language_uid']) {
                continue;
            }

            $existingTranslation = Records::getRecordTranslation($table, $recordUid, (int)$languageId);

            if ($translateMode === self::TRANSLATE_MODE_UPDATE_ONLY && !$existingTranslation) {
                LogUtility::log($this->logger, 'No Translation of {table} with uid {uid} because mode "update only".', [
                    'table' => $table,
                    'uid' => $recordUid,
                ]);
                continue;
            }

            if ($translateMode === self::TRANSLATE_MODE_CREATE_ONLY && $existingTranslation) {
                LogUtility::log($this->logger, 'Skipping {table} with uid {uid} because mode "create only" and translation already exists.', [
                    'table' => $table,
                    'uid' => $recordUid,
                ]);
                continue;
            }

            if (!$existingTranslation) {
                $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
                $dataHandler->start([], [], $this->configuredWorkspaceUser());
                $localizedUid = $dataHandler->localize($table, $recordUid, (int)$languageId);
            } else {
                $localizedUid = (int)$existingTranslation['uid'];
            }

            if (empty($localizedUid)) {
                LogUtility::log($this->logger, 'No Translation of {table} with uid {uid} because DataHandler localize failed.', [
                    'table' => $table,
                    'uid' => $recordUid,
                ]);
                continue;
            }

            $columnsForLanguage = $this->resolveColumnsForRecord(
                $columns,
                $existingTranslation,
                $datamapStatus,
                $changedFields,
                $record,
                $singleTargetLanguageId ?? (int)$languageId
            );

            $translateReferences = $this->shouldTranslateReferences(
                $table,
                $recordUid,
                $datamapStatus,
                $changedFields,
                (bool)$existingTranslation,
                $singleTargetLanguageId ?? (int)$languageId
            );

            if ($columnsForLanguage === [] && !$translateReferences) {
                continue;
            }

            $localizedContents[$languageId][$recordUid] = $localizedUid;
            $referenceTables = TranslationHelper::additionalReferenceTables();

            if ($translateReferences) {
                foreach ($referenceTables as $referenceTable) {
                    $columnsReference = TranslationHelper::translationTextfields($this->pageId, $referenceTable);
                    $autotranslateReferences = TranslationHelper::translationReferenceColumns($this->pageId, $table, $referenceTable);

                    if (empty($autotranslateReferences)) {
                        continue;
                    }

                    foreach ($autotranslateReferences as $referenceColumn) {
                        $foreignField = $this->getReferenceForeignField($table, $referenceColumn);
                        if ($foreignField === null) {
                            continue;
                        }

                        $references = $this->findReferenceUids($table, $recordUid, $referenceTable, $referenceColumn, $foreignField);
                        if ($references === null || empty($references) || empty($columnsReference)) {
                            continue;
                        }

                        foreach ($references as $referenceUid) {
                            $referenceUid = (int)$referenceUid;
                            $referenceTranslatedFields = $this->translateReferenceChild(
                                $table,
                                $recordUid,
                                (int)$localizedUid,
                                $referenceTable,
                                $referenceColumn,
                                $referenceUid,
                                (int)$languageId,
                                (string)$foreignField,
                                $columnsReference,
                                $parentObject,
                                $datamapStatus,
                                $changedFields,
                                $translateMode
                            );
                            if ($referenceTranslatedFields !== []) {
                                $didTranslate = true;
                            }
                        }
                    }
                }
            }

            if ($columnsForLanguage !== []) {
                $translatedColumns = $this->translateRecordProperties($record, (int)$languageId, $columnsForLanguage, $table, $localizedUid);

                if (count($translatedColumns) > 0) {
                    $this->updateTranslatedRecord($table, $localizedUid, $translatedColumns);
                    $didTranslate = true;
                    $translatedFieldNames = array_merge(
                        $translatedFieldNames,
                        array_values(array_intersect(array_keys($translatedColumns), $columns))
                    );
                }
            }

            if (!$existingTranslation) {
                $this->generateSlugs($table, $localizedUid);
            }
        }

        if ($didTranslate) {
            $this->persistSourceFieldHashes($table, $recordUid, $record, $columns, $translatedFieldNames);
            Records::updateRecord($table, $recordUid, [
                self::AUTOTRANSLATE_LAST => time(),
            ]);
        }
    }

    /**
     * Translate a reference record saved directly (e.g. sys_file_reference alt/title edit).
     *
     * @throws \RuntimeException If DeepL API key is invalid or has no characters left
     */
    public function translateReferenceRecord(
        string $referenceTable,
        int $referenceUid,
        ?DataHandler $parentObject = null,
        ?string $datamapStatus = null,
        ?array $changedFields = null,
        string $translateMode = self::TRANSLATE_MODE_BOTH,
    ): void {
        $record = Records::getRecord($referenceTable, $referenceUid);
        if ($record === null) {
            return;
        }

        $parentField = TranslationHelper::translationOrigPointerField($referenceTable);
        if ($parentField === null || (int)($record[$parentField] ?? 0) > 0) {
            return;
        }

        if ((int)($record['sys_language_uid'] ?? 0) > 0) {
            return;
        }

        if ((int)($record[self::AUTOTRANSLATE_EXCLUDE] ?? 0) === 1) {
            return;
        }

        $parentInfo = TranslationHelper::resolveReferenceParent($referenceTable, $record);
        if ($parentInfo === null) {
            return;
        }

        $parentTable = $parentInfo['table'];
        $parentUid = $parentInfo['uid'];
        $referenceColumn = $parentInfo['column'];

        if (!TranslationHelper::isReferenceAutotranslateEnabled(
            $this->pageId,
            $parentTable,
            $referenceTable,
            $referenceColumn
        )) {
            return;
        }

        $parentRecord = Records::getRecord($parentTable, $parentUid);
        if ($parentRecord === null) {
            return;
        }

        $parentOrigField = TranslationHelper::translationOrigPointerField($parentTable);
        if ($parentOrigField !== null && (int)($parentRecord[$parentOrigField] ?? 0) > 0) {
            return;
        }

        if ((int)($parentRecord[self::AUTOTRANSLATE_EXCLUDE] ?? 0) === 1) {
            return;
        }

        $columnsReference = TranslationHelper::translationTextfields($this->pageId, $referenceTable);
        if ($columnsReference === null || $columnsReference === []) {
            return;
        }

        $columnsToTranslate = $this->resolveColumnsToTranslate(
            $columnsReference,
            $datamapStatus,
            $changedFields,
            $record
        );
        if ($columnsToTranslate === []) {
            LogUtility::log($this->logger, 'No changed translatable fields for {referenceTable}:{uid}, skipping translation.', [
                'referenceTable' => $referenceTable,
                'uid' => $referenceUid,
            ]);
            return;
        }

        $foreignField = $this->getReferenceForeignField($parentTable, $referenceColumn);
        if ($foreignField === null) {
            return;
        }

        $this->assertApiKeyIsUsable();

        $languagesToTranslate = $parentRecord[self::AUTOTRANSLATE_LANGUAGES] ?? '';
        if ($languagesToTranslate === '') {
            $siteConfig = TranslationHelper::siteConfigurationValue($this->pageId);
            if (is_array($siteConfig)) {
                $settings = TranslationHelper::translationSettingsDefaults($siteConfig, $parentTable);
                $languagesToTranslate = $settings['autotranslateLanguages'] ?? '';
            }
        }

        $languageIds = GeneralUtility::trimExplode(',', $languagesToTranslate, true);
        if ($languageIds === []) {
            return;
        }

        $didTranslate = false;
        $translatedFieldNames = [];

        foreach ($languageIds as $languageId) {
            if ((int)$languageId === (int)($parentRecord['sys_language_uid'] ?? 0)) {
                continue;
            }

            $localizedParent = Records::getRecordTranslation($parentTable, $parentUid, (int)$languageId);
            if ($localizedParent === null) {
                LogUtility::log($this->logger, 'Skipping {referenceTable}:{uid} for language {languageId}: parent {parentTable}:{parentUid} has no translation.', [
                    'referenceTable' => $referenceTable,
                    'uid' => $referenceUid,
                    'languageId' => $languageId,
                    'parentTable' => $parentTable,
                    'parentUid' => $parentUid,
                ]);
                continue;
            }

            $translatedFields = $this->translateReferenceChild(
                $parentTable,
                $parentUid,
                (int)$localizedParent['uid'],
                $referenceTable,
                $referenceColumn,
                $referenceUid,
                (int)$languageId,
                $foreignField,
                $columnsReference,
                $parentObject,
                $datamapStatus,
                $changedFields,
                $translateMode
            );
            if ($translatedFields !== []) {
                $didTranslate = true;
                $translatedFieldNames = array_merge($translatedFieldNames, $translatedFields);
            }
        }

        if ($didTranslate) {
            $this->persistSourceFieldHashes($referenceTable, $referenceUid, $record, $columnsReference, $translatedFieldNames);
            Records::updateRecord($referenceTable, $referenceUid, [
                self::AUTOTRANSLATE_LAST => time(),
            ]);
        }
    }

    /**
     * @param list<string> $columnsReference
     */
    /**
     * @param list<string> $columnsReference
     * @return list<string> Translated field names, empty when nothing was translated
     */
    private function translateReferenceChild(
        string $parentTable,
        int $parentUid,
        int $localizedParentUid,
        string $referenceTable,
        string $referenceColumn,
        int $referenceUid,
        int $languageId,
        string $foreignField,
        array $columnsReference,
        ?DataHandler $parentObject,
        ?string $datamapStatus,
        ?array $changedFields,
        string $translateMode
    ): array {
        $referenceTranslation = Records::getRecordTranslation($referenceTable, $referenceUid, $languageId);

        if ($translateMode === self::TRANSLATE_MODE_UPDATE_ONLY && empty($referenceTranslation)) {
            LogUtility::log($this->logger, 'No {referenceTable} {referenceUid} Translation of {parentTable} with uid {uid} because mode "update only".', [
                'referenceTable' => $referenceTable,
                'parentTable' => $parentTable,
                'uid' => $parentUid,
                'referenceUid' => $referenceUid,
            ]);
            return [];
        }

        if ($translateMode === self::TRANSLATE_MODE_CREATE_ONLY && !empty($referenceTranslation)) {
            LogUtility::log($this->logger, 'Skipping {referenceTable} {referenceUid} of {parentTable} with uid {uid} because mode "create only" and translation already exists.', [
                'referenceTable' => $referenceTable,
                'parentTable' => $parentTable,
                'uid' => $parentUid,
                'referenceUid' => $referenceUid,
            ]);
            return [];
        }

        if (empty($referenceTranslation)) {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([], [], $this->configuredWorkspaceUser());
            $translatedReferenceUid = (int)$dataHandler->localize($referenceTable, $referenceUid, $languageId);

            $this->updateTranslatedRecord(
                $referenceTable,
                $translatedReferenceUid,
                [
                    $foreignField => $localizedParentUid,
                ]
            );
        } else {
            $translatedReferenceUid = (int)$referenceTranslation['uid'];
        }

        if ($translatedReferenceUid <= 0) {
            return [];
        }

        $recordReference = ($parentObject !== null && isset($parentObject->datamap[$referenceTable][$referenceUid]))
            ? $parentObject->datamap[$referenceTable][$referenceUid]
            : Records::getRecord($referenceTable, $referenceUid);

        if ($recordReference === null) {
            return [];
        }

        $referenceChangedFields = ($parentObject !== null && isset($parentObject->datamap[$referenceTable][$referenceUid]))
            ? $parentObject->datamap[$referenceTable][$referenceUid]
            : null;

        $effectiveStatus = $datamapStatus;
        $effectiveChangedFields = $referenceChangedFields;
        if (
            $referenceChangedFields === null
            && $datamapStatus === 'update'
            && $this->isTranslateChangedFieldsOnlyEnabled()
        ) {
            $effectiveStatus = null;
        }

        $columnsForReference = $this->resolveColumnsForRecord(
            $columnsReference,
            $referenceTranslation ?: null,
            $effectiveStatus,
            $effectiveChangedFields,
            $recordReference,
            $languageId
        );
        if ($columnsForReference === []) {
            return [];
        }

        $translatedColumns = $this->translateRecordProperties(
            $recordReference,
            $languageId,
            $columnsForReference,
            $referenceTable,
            $translatedReferenceUid
        );
        if ($translatedColumns === []) {
            return [];
        }

        $this->updateTranslatedRecord($referenceTable, $translatedReferenceUid, $translatedColumns);

        return array_values(array_intersect(array_keys($translatedColumns), $columnsReference));
    }

    private function getReferenceForeignField(string $parentTable, string $referenceColumn): ?string
    {
        $config = $GLOBALS['TCA'][$parentTable]['columns'][$referenceColumn]['config'] ?? null;
        if (!is_array($config)) {
            return null;
        }

        $foreignField = (string)($config['foreign_field'] ?? '');

        return $foreignField !== '' ? $foreignField : null;
    }

    /**
     * @param list<string> $configuredColumns
     * @return list<string>
     */
    private function resolveColumnsForRecord(
        array $configuredColumns,
        ?array $existingTranslation,
        ?string $datamapStatus,
        ?array $changedFields,
        ?array $sourceRecord = null,
        ?int $singleTargetLanguageId = null,
    ): array {
        $columns = $existingTranslation === null
            ? $configuredColumns
            : $this->resolveColumnsToTranslate(
                $configuredColumns,
                $datamapStatus,
                $changedFields,
                $sourceRecord,
                $singleTargetLanguageId
            );

        return $this->filterColumnsRespectingCustomTranslation(
            $columns,
            $existingTranslation,
            $changedFields,
            $datamapStatus
        );
    }

    /**
     * Skip fields marked custom in l10n_state unless the source field changed in this save.
     *
     * @param list<string> $columns
     * @return list<string>
     */
    private function filterColumnsRespectingCustomTranslation(
        array $columns,
        ?array $existingTranslation,
        ?array $changedFields,
        ?string $datamapStatus
    ): array {
        if ($existingTranslation === null || empty($existingTranslation['l10n_state'])) {
            return $columns;
        }

        $l10nState = json_decode((string)$existingTranslation['l10n_state'], true);
        if (!is_array($l10nState) || $l10nState === []) {
            return $columns;
        }

        $sourceChangedFields = null;
        if ($datamapStatus === 'update' && $changedFields !== null) {
            $sourceChangedFields = array_diff(array_keys($changedFields), self::CHANGE_DETECTION_IGNORE_FIELDS);
        }

        return array_values(array_filter(
            $columns,
            static function (string $column) use ($l10nState, $sourceChangedFields, $datamapStatus, $changedFields): bool {
                if (($l10nState[$column] ?? null) !== 'custom') {
                    return true;
                }

                if ($datamapStatus === null && $changedFields === null) {
                    return true;
                }

                if ($sourceChangedFields === null) {
                    return false;
                }

                return in_array($column, $sourceChangedFields, true);
            }
        ));
    }

    /**
     * @param list<string> $configuredColumns
     * @return list<string>
     */
    private function resolveColumnsToTranslate(
        array $configuredColumns,
        ?string $datamapStatus,
        ?array $changedFields,
        ?array $sourceRecord = null,
        ?int $singleTargetLanguageId = null,
        ?string $table = null,
        int $recordUid = 0,
    ): array {
        if (!$this->isTranslateChangedFieldsOnlyEnabled()) {
            return $configuredColumns;
        }

        if ($datamapStatus === 'new') {
            return $configuredColumns;
        }

        if ($datamapStatus === 'update' && $changedFields !== null) {
            $changedFieldNames = array_diff(array_keys($changedFields), self::CHANGE_DETECTION_IGNORE_FIELDS);

            return array_values(array_intersect($configuredColumns, $changedFieldNames));
        }

        if ($datamapStatus === null && $changedFields === null && $sourceRecord !== null) {
            if (
                $singleTargetLanguageId !== null
                && $table !== null
                && $recordUid > 0
                && Records::getRecordTranslation($table, $recordUid, $singleTargetLanguageId) === null
            ) {
                return $configuredColumns;
            }

            return SourceHashUtility::resolveChangedColumns($sourceRecord, $configuredColumns);
        }

        return $configuredColumns;
    }

    private function shouldTranslateReferences(
        string $table,
        int $recordUid,
        ?string $datamapStatus,
        ?array $changedFields,
        bool $existingTranslation,
        ?int $singleTargetLanguageId = null
    ): bool {
        if (!$existingTranslation) {
            return true;
        }

        if (!$this->isTranslateChangedFieldsOnlyEnabled()) {
            return true;
        }

        if ($datamapStatus === 'update' && $changedFields !== null) {
            $changedFieldNames = array_diff(array_keys($changedFields), self::CHANGE_DETECTION_IGNORE_FIELDS);
            $referenceColumns = [];

            foreach (TranslationHelper::additionalReferenceTables() as $referenceTable) {
                $columns = TranslationHelper::translationReferenceColumns($this->pageId, $table, $referenceTable);
                if ($columns !== null) {
                    $referenceColumns = array_merge($referenceColumns, $columns);
                }
            }

            return array_intersect($referenceColumns, $changedFieldNames) !== [];
        }

        if ($datamapStatus === null && $changedFields === null) {
            return $this->referenceRecordsNeedTranslation($table, $recordUid, $singleTargetLanguageId);
        }

        return true;
    }

    private function resolveSingleTargetLanguageId(?string $languagesToTranslate): ?int
    {
        if ($languagesToTranslate === null || $languagesToTranslate === '') {
            return null;
        }

        if (str_contains($languagesToTranslate, ',')) {
            return null;
        }

        return (int)$languagesToTranslate;
    }

    private function recordsNeedReferenceTranslation(
        string $table,
        int $recordUid,
        ?string $datamapStatus,
        ?array $changedFields,
        ?int $singleTargetLanguageId
    ): bool {
        if ($datamapStatus === 'update' && $changedFields !== null) {
            return $this->shouldTranslateReferences($table, $recordUid, $datamapStatus, $changedFields, true, $singleTargetLanguageId);
        }

        if ($datamapStatus === null && $changedFields === null) {
            return $this->referenceRecordsNeedTranslation($table, $recordUid, $singleTargetLanguageId);
        }

        return false;
    }

    private function referenceRecordsNeedTranslation(string $table, int $recordUid, ?int $singleTargetLanguageId): bool
    {
        foreach (TranslationHelper::additionalReferenceTables() as $referenceTable) {
            $columnsReference = TranslationHelper::translationTextfields($this->pageId, $referenceTable);
            if ($columnsReference === null || $columnsReference === []) {
                continue;
            }

            $autotranslateReferences = TranslationHelper::translationReferenceColumns($this->pageId, $table, $referenceTable);
            if (empty($autotranslateReferences)) {
                continue;
            }

            foreach ($autotranslateReferences as $referenceColumn) {
                $foreignField = $this->getReferenceForeignField($table, $referenceColumn);
                if ($foreignField === null) {
                    continue;
                }

                $references = $this->findReferenceUids($table, $recordUid, $referenceTable, $referenceColumn, $foreignField);
                if ($references === null || $references === []) {
                    continue;
                }

                foreach ($references as $referenceUid) {
                    $refRecord = Records::getRecord($referenceTable, (int)$referenceUid);
                    if ($refRecord === null) {
                        continue;
                    }

                    if ($singleTargetLanguageId !== null) {
                        $refTranslation = Records::getRecordTranslation($referenceTable, (int)$referenceUid, $singleTargetLanguageId);
                        if ($refTranslation === null) {
                            return true;
                        }
                    }

                    if (SourceHashUtility::resolveChangedColumns($refRecord, $columnsReference) !== []) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @return list<int>|null
     */
    private function findReferenceUids(
        string $table,
        int $recordUid,
        string $referenceTable,
        string $referenceColumn,
        string $foreignField
    ): ?array {
        $type = $GLOBALS['TCA'][$table]['columns'][$referenceColumn]['config']['type'] ?? null;

        $references = match ($type) {
            'file' => Records::getRecords($referenceTable, 'uid', static function (QueryBuilder $queryBuilder) use ($foreignField, $recordUid, $table, $referenceColumn): void {
                $queryBuilder->where(
                    $queryBuilder->expr()->eq($foreignField, $queryBuilder->createNamedParameter($recordUid, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter($table)),
                    $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter($referenceColumn))
                );
            }),
            'inline' => Records::getRecords($referenceTable, 'uid', static function (QueryBuilder $queryBuilder) use ($foreignField, $recordUid, $referenceTable, $referenceColumn): void {
                $queryBuilder->where(
                    $queryBuilder->expr()->eq($foreignField, $queryBuilder->createNamedParameter($recordUid, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
                );
                if (isset($GLOBALS['TCA'][$referenceTable]['columns']['fieldname'])) {
                    $queryBuilder->andWhere(
                        $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter($referenceColumn))
                    );
                }
            }),
            default => null,
        };

        if ($references === null) {
            LogUtility::log($this->logger, 'Unsupported reference type {type} for column {referenceColumn} in table {table}.', [
                'type' => $type,
                'referenceColumn' => $referenceColumn,
                'table' => $table,
            ], LogUtility::MESSAGE_WARNING);

            return null;
        }

        return array_map(static fn($uid): int => (int)$uid, $references);
    }

    /**
     * @param list<string> $configuredColumns
     * @param list<string> $translatedFieldNames
     */
    private function persistSourceFieldHashes(
        string $table,
        int $recordUid,
        array $record,
        array $configuredColumns,
        array $translatedFieldNames
    ): void {
        $translatedFieldNames = array_values(array_unique(array_intersect($translatedFieldNames, $configuredColumns)));
        if ($translatedFieldNames === []) {
            return;
        }

        try {
            Records::updateRecord($table, $recordUid, [
                SourceHashUtility::FIELD_NAME => SourceHashUtility::mergeHashesForTranslatedFields(
                    $record,
                    $configuredColumns,
                    $translatedFieldNames
                ),
            ]);
        } catch (\JsonException $exception) {
            LogUtility::log($this->logger, 'Failed to persist source field hashes for {table}:{uid}: {error}', [
                'table' => $table,
                'uid' => $recordUid,
                'error' => $exception->getMessage(),
            ], LogUtility::MESSAGE_ERROR);
        }
    }

    private function isTranslateChangedFieldsOnlyEnabled(): bool
    {
        try {
            $extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('autotranslate');
        } catch (\Exception) {
            return false;
        }

        return (bool)($extensionConfiguration['translateChangedFieldsOnly'] ?? true);
    }

    /**
     * @throws \RuntimeException If DeepL API key is invalid or has no characters left
     */
    private function assertApiKeyIsUsable(): void
    {
        $deeplApiKeyDetails = DeeplApiHelper::checkApiKey($this->apiKey);
        if ($deeplApiKeyDetails['error']) {
            LogUtility::log($this->logger, 'DeepL API Key is not valid: {error}', [
                'error' => $deeplApiKeyDetails['error'],
            ]);
            throw new \RuntimeException('DeepL API Key is not valid: ' . $deeplApiKeyDetails['error']);
        }
        if (!$deeplApiKeyDetails['isValid']) {
            LogUtility::log($this->logger, 'DeepL API Key is not valid: {error}', [
                'error' => 'No API Key given.',
            ]);
            throw new \RuntimeException('DeepL API Key is not valid: No API Key given.');
        }
        if ($deeplApiKeyDetails['charactersLeft'] !== null && $deeplApiKeyDetails['charactersLeft'] <= 0) {
            LogUtility::log($this->logger, 'DeepL API Key has no characters left: {charactersLeft}', [
                'charactersLeft' => $deeplApiKeyDetails['charactersLeft'],
            ]);
            throw new \RuntimeException('DeepL API Key has no characters left: ' . $deeplApiKeyDetails['charactersLeft']);
        }
    }

    /**
     * Translate the given record properties
     */
    public function translateRecordProperties(array $record, int $targetLanguageUid, array $columns, string $table, int $localizedUid): array
    {
        $translatedColumns = [];

        try {
            $toTranslateObject = array_intersect_key($record, array_flip($columns));
            $toTranslate = array_filter($toTranslateObject, static fn($value) => is_string($value) && $value !== '');
            $deeplSourceLang = $this->deeplSourceLanguage();
            $deeplTargetLang = $this->deeplTargetLanguage($targetLanguageUid);
            $result = null;
            $glossary = null;

            if ($deeplTargetLang === null) {
                throw new \RuntimeException(
                    'No DeepL target language configured for language uid ' . $targetLanguageUid
                    . '. Please set "deeplTargetLang" in Site Configuration → Languages for this language.'
                );
            }

            if (count($toTranslate) > 0) {
                $toTranslate = $this->extractAndReplaceTranslatableHtmlAttributes($toTranslate);
                $translator = new \DeepL\Translator($this->apiKey);

                // Get optional glossary handled by 3rd party extension
                if ($deeplSourceLang && $deeplTargetLang && TranslationHelper::glossaryEnabled($this->pageId)) {
                    $glossary = $this->glossaryService->getGlossary($deeplSourceLang, $deeplTargetLang, $this->pageId, $translator);
                }

                // Experimental: translate flexform fields
                // TODO: Let user define which fields in flexform should be translated to prevent translating settings
                if (isset($toTranslate['pi_flexform'])) {
                    $xml = simplexml_load_string($record['pi_flexform']);

                    foreach ($xml->xpath('//field') as $field) {
                        $value = (string)$field->value;
                        if (!empty(trim($value))
                            && !str_contains($value, '<')
                            && is_string($value)
                            && !is_numeric($value)
                            && $value !== ''
                        ) {
                            $translationResult = $this->translateItems(
                                $record,
                                $table,
                                [$value],
                                $deeplSourceLang,
                                $deeplTargetLang,
                                $glossary
                            );
                            if (!empty($translationResult)) {
                                $field->value[0] = $translationResult[0]->text;
                            }
                        }
                    }

                    $translatedColumns['pi_flexform'] = $xml->asXML();
                    unset($toTranslate['pi_flexform']);
                }

                $result = empty($toTranslate) ? [] : $this->translateItems($record, $table, $toTranslate, $deeplSourceLang, $deeplTargetLang, $glossary);
            }

            $keys = array_keys($toTranslate);
            if (!empty($result)) {
                $translatedAttributes = [];
                foreach ($result as $k => $v) {
                    if ($v === null) {
                        continue;
                    }

                    $field = $keys[$k];
                    if (str_starts_with($field, '__ATTR__')) {
                        $translatedAttributes[$field] = $v->text;
                    }
                }

                foreach ($result as $k => $v) {
                    if ($v === null) {
                        continue;
                    }

                    $field = $keys[$k];
                    if (str_starts_with($field, '__ATTR__')) {
                        continue;
                    }
                    $translatedValue = $this->restoreTranslatedHtmlAttributes($v->text, $translatedAttributes);
                    $translatedColumns[$field] = $translatedValue;
                }
            }

            // Add fields to copy in translation from extension configuration
            $fieldsToCopy = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('autotranslate', 'fieldsToCopy');
            $fields = $fieldsToCopy ? GeneralUtility::trimExplode(',', $fieldsToCopy, true) : [];
            foreach ($record as $field => $value) {
                if (isset($record[$field]) && !isset($translatedColumns[$field]) && in_array($field, $fields, true)) {
                    $translatedColumns[$field] = $value;
                }
            }

            if (!empty($translatedColumns)) {
                $translatedColumns['l10n_state'] = $this->buildL10nState(
                    $table,
                    $targetLanguageUid,
                    array_keys($translatedColumns),
                    $localizedUid,
                    (int)($record['uid'] ?? 0)
                );
            }

            // Set date and time of translation
            $translatedColumns[self::AUTOTRANSLATE_LAST] = time();

            LogUtility::log($this->logger, 'Successful translated to target language {deeplTargetLang}.', [
                'deeplTargetLang' => $deeplTargetLang,
                'fieldCount' => count($translatedColumns),
                'fields' => array_keys($translatedColumns),
            ]);
        } catch (\Exception $e) {
            LogUtility::log($this->logger, 'Translation Error: {error}.', ['error' => $e->getMessage()], LogUtility::MESSAGE_ERROR);
            throw $e;
        }

        return $translatedColumns;
    }

    private function deeplSourceLanguage(): ?string
    {
        foreach ($this->siteLanguages as $language) {
            if ($language['languageId'] === 0) {
                return empty($language['deeplSourceLang']) ? null : $language['deeplSourceLang'];
            }
        }

        return null;
    }

    private function deeplTargetLanguage(int $languageId): ?string
    {
        foreach ($this->siteLanguages as $language) {
            if ($language['languageId'] === $languageId) {
                return $language['deeplTargetLang'] ?? null;
            }
        }

        return null;
    }

    /**
     * Replaces translatable HTML attributes with placeholders and returns the modified array.
     */
    private function extractAndReplaceTranslatableHtmlAttributes(array $toTranslate): array
    {
        $attributeMap = [
            ['tag' => 'a', 'attr' => 'title'],
        ];

        $attrMap = [];
        $attrCounter = 1;

        foreach ($toTranslate as $field => &$value) {
            if (!is_string($value) || trim($value) === '' || !$this->isHtml($value)) {
                continue;
            }

            foreach ($attributeMap as $map) {
                $found = $this->extractHtmlAttributes($value, $map['tag'], $map['attr']);
                foreach ($found as $attrValue) {
                    $placeholder = '__ATTR__' . $attrCounter . '__';
                    $attrMap[$placeholder] = $attrValue;
                    $value = $this->replaceHtmlAttributeWithPlaceholder($value, $map['tag'], $map['attr'], $attrValue, $placeholder);
                    $attrCounter++;
                }
            }
        }
        unset($value);

        // Add the attributes as separate entries to translate
        foreach ($attrMap as $placeholder => $original) {
            $toTranslate[$placeholder] = $original;
        }

        return $toTranslate;
    }

    private function isHtml(string $value): bool
    {
        return $value !== strip_tags($value);
    }

    /**
     * Extracts all values of a specific attribute from a specific tag in HTML.
     */
    private function extractHtmlAttributes(string $html, string $tagName, string $attributeName): array
    {
        $values = [];
        if (trim($html) === '') {
            return $values;
        }

        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);

        $xpath = new \DOMXPath($doc);
        $query = '//' . $tagName . '[@' . $attributeName . ']';
        foreach ($xpath->query($query) as $node) {
            /** @var \DOMElement $node */
            $values[] = $node->getAttribute($attributeName);
        }

        return $values;
    }

    /**
     * Replaces a specific attribute of a tag in an HTML string with a placeholder.
     */
    private function replaceHtmlAttributeWithPlaceholder(string $html, string $tag, string $attr, string $original, string $placeholder): string
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new \DOMXPath($doc);
        $query = '//' . $tag . '[@' . $attr . ']';

        foreach ($xpath->query($query) as $node) {
            /** @var \DOMElement $node */
            if ($node->getAttribute($attr) === $original) {
                $node->setAttribute($attr, $placeholder);
            }
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        $innerHTML = '';
        foreach ($body->childNodes as $child) {
            $innerHTML .= $doc->saveHTML($child);
        }

        return $innerHTML;
    }

    private function translateItems(array $record, string $table, array $toTranslate, ?string $deeplSourceLang, string $deeplTargetLang, ?Glossary $glossary): array
    {
        $translator = new \DeepL\Translator($this->apiKey);
        $baseOptions = [
            TranslateTextOptions::SPLIT_SENTENCES => true,
        ];
        $formality = $this->resolvedFormalityOption();
        if ($formality !== null) {
            $baseOptions[TranslateTextOptions::FORMALITY] = $formality;
        }

        if ($glossary) {
            $baseOptions[TranslateTextOptions::GLOSSARY] = $glossary->glossaryId;
        }

        $htmlOptions = array_merge($baseOptions, [
            TranslateTextOptions::TAG_HANDLING => 'html',
        ]);

        $richtextMap = $this->mapRichtextFields($toTranslate, $table, $record);

        $toTranslateText = [];
        $toTranslateHtml = [];

        foreach ($toTranslate as $field => $value) {
            if (!($richtextMap[$field] ?? false)) {
                $toTranslateText[$field] = $value;
            } else {
                $toTranslateHtml[$field] = $value;
            }
        }

        $cacheService = GeneralUtility::makeInstance(TranslationCacheService::class);

        // Translate text fields with cache
        $translatedTextFields = [];
        if (!empty($toTranslateText)) {
            $translatedTextFields = $this->translateWithCache(
                array_values($toTranslateText),
                $deeplSourceLang,
                $deeplTargetLang,
                $baseOptions,
                $translator,
                $cacheService
            );
        }

        // Translate HTML fields with cache
        $translatedHtmlFields = [];
        if (!empty($toTranslateHtml)) {
            $translatedHtmlFields = $this->translateWithCache(
                array_values($toTranslateHtml),
                $deeplSourceLang,
                $deeplTargetLang,
                $htmlOptions,
                $translator,
                $cacheService
            );
        }

        // Restore field order from $toTranslate
        $mergedResults = [];
        $textIndex = 0;
        $htmlIndex = 0;

        foreach (array_keys($toTranslate) as $field) {
            if (array_key_exists($field, $toTranslateText)) {
                $mergedResults[] = $translatedTextFields[$textIndex] ?? null;
                $textIndex++;
            } elseif (array_key_exists($field, $toTranslateHtml)) {
                $mergedResults[] = $translatedHtmlFields[$htmlIndex] ?? null;
                $htmlIndex++;
            }
        }

        return $mergedResults;
    }

    private function resolvedFormalityOption(): ?string
    {
        return match ($this->deeplFormality) {
            'prefer_less', 'prefer_more' => $this->deeplFormality,
            default => null,
        };
    }

    /**
     * Returns an array indicating whether each field in $toTranslate is a richtext field.
     */
    public function mapRichtextFields(array $toTranslate, string $table, array $record): array
    {
        $result = [];
        foreach (array_keys($toTranslate) as $columnName) {
            $result[$columnName] = $this->isRichtextField($record, $table, $columnName);
        }

        return $result;
    }

    /**
     * Check if the field is a richtext field
     */
    public function isRichtextField(array $record, string $table, string $columnName): bool
    {
        $fieldConfig = $GLOBALS['TCA'][$table]['columns'][$columnName]['config'] ?? null;
        if (!$fieldConfig) {
            return false;
        }

        // Check for CType specific configuration
        $ctype = $record['CType'] ?? null;
        if ($ctype && isset($GLOBALS['TCA'][$table]['types'][$ctype]['columnsOverrides'][$columnName]['config'])) {
            $fieldConfig = $GLOBALS['TCA'][$table]['types'][$ctype]['columnsOverrides'][$columnName]['config'];
        }

        return isset($fieldConfig['enableRichtext']) && $fieldConfig['enableRichtext'] === true;
    }

    /**
     * Translate texts with caching support
     */
    private function translateWithCache(
        array $texts,
        ?string $sourceLang,
        string $targetLang,
        array $options,
        \DeepL\Translator $translator,
        TranslationCacheService $cacheService
    ): array {
        if (empty($texts)) {
            return [];
        }

        // Check for complete cache hit first
        $completeCacheKey = $cacheService->generateCacheKey($texts, $sourceLang, $targetLang, $options);
        $completeCache = $cacheService->getCachedTranslation($completeCacheKey);

        if ($completeCache !== null) {
            LogUtility::log($this->logger, 'Complete cache hit for {count} texts', ['count' => count($texts)]);
            return $completeCache;
        }

        // Check for partial cache hits
        $partialCache = $cacheService->getPartialCacheHits($texts, $sourceLang, $targetLang, $options);

        $finalResults = array_fill(0, count($texts), null);

        // Fill cached results
        foreach ($partialCache['cached'] as $index => $result) {
            $finalResults[$index] = $result;
        }

        // Translate uncached texts
        if (!empty($partialCache['uncached'])) {
            LogUtility::log($this->logger, 'Translating {uncached} texts, {cached} from cache', [
                'uncached' => count($partialCache['uncached']),
                'cached' => count($partialCache['cached']),
            ]);

            $freshTranslations = $translator->translateText(
                $partialCache['uncached'],
                $sourceLang,
                $targetLang,
                $options
            );

            foreach ($freshTranslations as $resultIndex => $result) {
                $originalIndex = $partialCache['mapping'][$resultIndex];
                $finalResults[$originalIndex] = $result;
            }

            $cacheService->cacheIndividualTranslations(
                $partialCache['uncached'],
                $freshTranslations,
                $sourceLang,
                $targetLang,
                $options
            );
        }

        // Cache complete result (only if all results are valid)
        $validResults = array_filter($finalResults, static fn($result) => $result !== null);
        if (count($validResults) === count($finalResults)) {
            $cacheService->setCachedTranslation($completeCacheKey, $finalResults);
        } else {
            LogUtility::log($this->logger, 'Not caching complete result due to null values: {valid}/{total}', [
                'valid' => count($validResults),
                'total' => count($finalResults),
            ]);
        }

        return $finalResults;
    }

    /**
     * Replaces placeholders in the HTML with the translated attribute values.
     */
    private function restoreTranslatedHtmlAttributes(string $html, array $attrTranslations): string
    {
        foreach ($attrTranslations as $placeholder => $translatedValue) {
            if ($translatedValue !== null) {
                $html = str_replace($placeholder, $translatedValue, $html);
            }
        }

        return $html;
    }

    /**
     * Builds l10n_state array for translated fields
     */
    private function buildL10nState(string $table, int $targetLanguageUid, array $translatedFields, int $localizedUid, int $sourceUid): string
    {
        if (!isset($GLOBALS['TCA'][$table]['ctrl']['transOrigDiffSourceField'])) {
            return '{}';
        }

        try {
            $existingTranslation = null;
            if ($sourceUid > 0) {
                $existingTranslation = Records::getRecordTranslation($table, $sourceUid, $targetLanguageUid);
            }
            if ($existingTranslation === null) {
                $existingTranslation = Records::getRecord($table, $localizedUid);
            }

            $l10nState = [];
            if ($existingTranslation && !empty($existingTranslation['l10n_state'])) {
                $l10nState = json_decode($existingTranslation['l10n_state'], true) ?: [];
            }

            foreach ($translatedFields as $field) {
                $l10nState[$field] = 'custom';
            }

            return json_encode($l10nState);
        } catch (\Exception $e) {
            LogUtility::log($this->logger, 'Error building l10n_state: {error}', [
                'error' => $e->getMessage(),
                'table' => $table,
            ], LogUtility::MESSAGE_ERROR);

            return '{}';
        }
    }

    /**
     * Generate slugs for a translated record
     */
    private function generateSlugs(string $table, int $uid): void
    {
        $slugFields = SlugUtility::slugFields($table);
        if (empty($slugFields)) {
            return;
        }

        $record = Records::getRecord($table, $uid);
        $fieldsToUpdate = [];

        foreach (array_keys($slugFields) as $field) {
            $slug = SlugUtility::generateSlug($record, $table, $field);
            if ($slug !== null) {
                $fieldsToUpdate[$field] = $slug;
            }
        }

        if (!empty($fieldsToUpdate)) {
            $this->updateTranslatedRecord($table, $uid, $fieldsToUpdate);
        }
    }

    /**
     * Persist translated record fields through DataHandler instead of a direct
     * database write, so the update respects the active workspace: it lands in a
     * draft version when the executing user (e.g. the scheduler's _cli_ user) is in
     * a workspace, and on the live row otherwise. localize() above already routes
     * through DataHandler; the content and slug writes must do the same to stay
     * consistent - otherwise they would bypass workspace versioning and hit the live
     * row directly. Source-side bookkeeping (autotranslate_last,
     * autotranslate_source_hash) is intentionally kept as a direct write on the live
     * source record.
     */
    private function updateTranslatedRecord(string $table, int $uid, array $data): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([$table => [$uid => $data]], [], $this->configuredWorkspaceUser());
        $dataHandler->process_datamap();
    }

    /**
     * Build a backend-user context for the configured translation workspace WITHOUT
     * touching the global $GLOBALS['BE_USER']. DataHandler decides the workspace for
     * its writes from $this->BE_USER->workspace; passing a workspace-scoped user as the
     * third argument to start() (the documented way to "force another backend user")
     * routes localize() / process_datamap() into that workspace. Cloning the current
     * user keeps its permissions while changing only the workspace, so the global user
     * is never switched - safe even if other code shares the request. Returns null
     * (=> DataHandler falls back to the global user / live) when no workspace is set.
     */
    private function configuredWorkspaceUser(): ?BackendUserAuthentication
    {
        $workspaceId = (int)(TranslationHelper::siteConfigurationValue(
            $this->pageId,
            ['autotranslateWorkspaceId']
        ) ?? 0);
        if ($workspaceId <= 0 || !isset($GLOBALS['BE_USER'])) {
            return null;
        }
        $workspaceUser = clone $GLOBALS['BE_USER'];
        $workspaceUser->workspace = $workspaceId;
        return $workspaceUser;
    }
}
