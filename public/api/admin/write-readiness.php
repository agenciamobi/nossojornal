<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

function nj_admin_probe_sql(PDO $pdo, string $sql): array
{
    try {
        $statement = $pdo->prepare($sql);
        $statement->execute();

        return [
            'available' => true,
            'reason' => null,
        ];
    } catch (PDOException $error) {
        $sqlState = (string) $error->getCode();

        return [
            'available' => false,
            'reason' => $sqlState === '42000' ? 'privilege_denied' : 'probe_failed',
        ];
    }
}

nj_admin_run(['GET'], static function (): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'edit_posts');

    $pdo = nj_db();
    $termmeta = nj_table('termmeta');

    $probes = [
        'select' => [
            'available' => true,
            'reason' => null,
        ],
        'insert' => [
            'available' => false,
            'reason' => 'not_checked',
        ],
        'update' => [
            'available' => false,
            'reason' => 'not_checked',
        ],
        'delete' => [
            'available' => false,
            'reason' => 'not_checked',
        ],
    ];

    try {
        $selectStatement = $pdo->query("SELECT 1 AS ok");
        $probes['select'] = [
            'available' => (int) $selectStatement->fetchColumn() === 1,
            'reason' => null,
        ];
    } catch (Throwable) {
        $probes['select'] = [
            'available' => false,
            'reason' => 'probe_failed',
        ];
    }

    $transactionStarted = false;

    try {
        $transactionStarted = $pdo->beginTransaction();

        $probes['insert'] = nj_admin_probe_sql(
            $pdo,
            "INSERT INTO {$termmeta} (term_id, meta_key, meta_value)
             SELECT 0, '__nj_write_probe__', ''
             WHERE 1 = 0"
        );

        $probes['update'] = nj_admin_probe_sql(
            $pdo,
            "UPDATE {$termmeta}
             SET meta_value = meta_value
             WHERE 1 = 0"
        );

        $probes['delete'] = nj_admin_probe_sql(
            $pdo,
            "DELETE FROM {$termmeta}
             WHERE 1 = 0"
        );
    } finally {
        if ($transactionStarted && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    $writeReady = $probes['insert']['available']
        && $probes['update']['available']
        && $probes['delete']['available'];

    return [
        'database' => [
            'select' => $probes['select'],
            'insert' => $probes['insert'],
            'update' => $probes['update'],
            'delete' => $probes['delete'],
            'runtimeWriteReady' => $writeReady,
        ],
        'nextCapabilities' => [
            'categoryColorWrite' => $writeReady,
            'draftPostWrite' => $writeReady,
            'publishPostWrite' => $writeReady,
            'mediaUploadWrite' => false,
        ],
        'probe' => [
            'mutatedRows' => 0,
            'transactionRolledBack' => true,
        ],
    ];
});
