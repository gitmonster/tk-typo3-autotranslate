<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\UserFunction\FormEngine;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Populates the "autotranslateWorkspaceId" site-configuration dropdown with the
 * available workspaces (sys_workspace), plus a "Live" option (= 0). Selecting Live
 * keeps the previous behaviour (autotranslate writes the live record); selecting a
 * workspace routes every translation into that workspace as a draft.
 */
final class WorkspaceItems
{
    public function itemsProcFunc(array &$config): void
    {
        $config['items'][] = [
            'label' => 'Live (no workspace)',
            'value' => 0,
        ];

        // Query sys_workspace directly for rows - Records::getRecords() returns only
        // the first column (fetchFirstColumn), so it cannot provide title + uid.
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_workspace');
        $workspaces = $queryBuilder
            ->select('uid', 'title')
            ->from('sys_workspace')
            ->orderBy('title')
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($workspaces as $workspace) {
            $config['items'][] = [
                'label' => trim((string)$workspace['title']) . ' (uid ' . (int)$workspace['uid'] . ')',
                'value' => (int)$workspace['uid'],
            ];
        }
    }
}
