<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_content.php';
require_once __DIR__ . '/_authors.php';

nj_run(static function (): array {
    $pdo = nj_db();
    $items = nj_columnist_profiles($pdo);

    return [
        'items' => $items,
        'count' => count($items),
    ];
}, 'public, max-age=60, stale-while-revalidate=300');
