<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

nj_run(static function (): array {
    $pdo = nj_db();
    $taxonomy = nj_table('term_taxonomy');

    $statement = $pdo->query(
        "SELECT COUNT(*) FROM {$taxonomy} WHERE taxonomy = 'category'"
    );

    return [
        'status' => 'ok',
        'database' => 'reachable',
        'legacyCategories' => (int) $statement->fetchColumn(),
    ];
});
