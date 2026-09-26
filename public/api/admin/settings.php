<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

nj_admin_run(['GET'], static function (): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'manage_options');

    $pdo = nj_db();
    $options = nj_table('options');

    $allowed = [
        'blogname',
        'blogdescription',
        'home',
        'siteurl',
        'admin_email',
        'posts_per_page',
        'date_format',
        'time_format',
        'timezone_string',
        'permalink_structure',
    ];

    $placeholders = implode(',', array_fill(0, count($allowed), '?'));
    $statement = $pdo->prepare(
        "SELECT option_name, option_value FROM {$options} WHERE option_name IN ({$placeholders})"
    );
    $statement->execute($allowed);

    $values = [];

    foreach ($statement->fetchAll() as $row) {
        $values[(string) $row['option_name']] = (string) $row['option_value'];
    }

    return [
        'options' => $values,
    ];
});
