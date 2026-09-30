<?php
declare(strict_types=1);

require __DIR__ . '/_m2m.php';

nj_m2m_run('GET', 'editorial.integration.status', static function (array $context): array {
    $pdo = $context['pdo'];
    $pdo->query('SELECT 1')->fetchColumn();

    return [
        'status' => 'available',
        'version' => NJ_EDITORIAL_M2M_VERSION,
    ];
});
