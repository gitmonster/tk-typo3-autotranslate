<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\UserFunction\FormEngine;

use ThieleUndKlose\Autotranslate\Utility\Records;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

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

        $workspaces = Records::getRecords(
            'sys_workspace',
            'uid,title',
            static function (QueryBuilder $queryBuilder): void {
                $queryBuilder->orderBy('title');
            }
        );

        foreach ($workspaces as $workspace) {
            $config['items'][] = [
                'label' => trim((string)$workspace['title']) . ' (uid ' . (int)$workspace['uid'] . ')',
                'value' => (int)$workspace['uid'],
            ];
        }
    }
}
