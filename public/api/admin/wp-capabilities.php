<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

nj_admin_run(['GET'], static function (): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'manage_options');

    $pdo = nj_db();
    $posts = nj_table('posts');
    $postmeta = nj_table('postmeta');
    $terms = nj_table('terms');
    $taxonomy = nj_table('term_taxonomy');
    $relationships = nj_table('term_relationships');
    $users = nj_table('users');
    $comments = nj_table('comments');
    $options = nj_table('options');

    $postTypesStatement = $pdo->query(<<<SQL
SELECT post_type, post_status, COUNT(*) AS total
FROM {$posts}
GROUP BY post_type, post_status
ORDER BY post_type ASC, total DESC
SQL);

    $postTypes = [];
    foreach ($postTypesStatement->fetchAll() as $row) {
        $type = (string) $row['post_type'];
        $postTypes[$type] ??= [
            'postType' => $type,
            'total' => 0,
            'statuses' => [],
        ];

        $count = (int) $row['total'];
        $postTypes[$type]['total'] += $count;
        $postTypes[$type]['statuses'][(string) $row['post_status']] = $count;
    }

    $taxonomyStatement = $pdo->query(<<<SQL
SELECT taxonomy, COUNT(*) AS terms, SUM(count) AS relationships
FROM {$taxonomy}
GROUP BY taxonomy
ORDER BY terms DESC, taxonomy ASC
SQL);

    $taxonomies = array_map(
        static fn (array $row): array => [
            'taxonomy' => (string) $row['taxonomy'],
            'terms' => (int) $row['terms'],
            'relationships' => (int) ($row['relationships'] ?? 0),
        ],
        $taxonomyStatement->fetchAll()
    );

    $metaStatement = $pdo->query(<<<SQL
SELECT meta_key, COUNT(*) AS total
FROM {$postmeta}
WHERE meta_key <> ''
GROUP BY meta_key
ORDER BY total DESC, meta_key ASC
LIMIT 40
SQL);

    $metaKeys = array_map(
        static fn (array $row): array => [
            'key' => (string) $row['meta_key'],
            'count' => (int) $row['total'],
        ],
        $metaStatement->fetchAll()
    );

    $commentStatement = $pdo->query(<<<SQL
SELECT comment_approved AS status, COUNT(*) AS total
FROM {$comments}
GROUP BY comment_approved
ORDER BY total DESC
SQL);

    $commentCounts = [];
    foreach ($commentStatement->fetchAll() as $row) {
        $commentCounts[(string) $row['status']] = (int) $row['total'];
    }

    $optionNames = [
        'sticky_posts',
        'active_plugins',
        'blogname',
        'blogdescription',
        'posts_per_page',
        'permalink_structure',
        'timezone_string',
        'nav_menu_options',
    ];
    $placeholders = implode(',', array_fill(0, count($optionNames), '?'));
    $optionStatement = $pdo->prepare(
        "SELECT option_name FROM {$options} WHERE option_name IN ({$placeholders})"
    );
    $optionStatement->execute($optionNames);
    $presentOptions = array_fill_keys(
        array_map('strval', $optionStatement->fetchAll(PDO::FETCH_COLUMN)),
        true
    );

    $revisionCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM {$posts} WHERE post_type = 'revision'"
    )->fetchColumn();

    $attachmentCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM {$posts} WHERE post_type = 'attachment'"
    )->fetchColumn();

    $menuCountStatement = $pdo->query(<<<SQL
SELECT COUNT(*)
FROM {$taxonomy}
WHERE taxonomy = 'nav_menu'
SQL);
    $menuCount = (int) $menuCountStatement->fetchColumn();

    $menuItemCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM {$posts} WHERE post_type = 'nav_menu_item'"
    )->fetchColumn();

    $userCount = (int) $pdo->query("SELECT COUNT(*) FROM {$users}")->fetchColumn();

    $orphanRelationshipCount = (int) $pdo->query(<<<SQL
SELECT COUNT(*)
FROM {$relationships} tr
LEFT JOIN {$posts} p ON p.ID = tr.object_id
LEFT JOIN {$taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
WHERE p.ID IS NULL OR tt.term_taxonomy_id IS NULL
SQL)->fetchColumn();

    $orphanTermCount = (int) $pdo->query(<<<SQL
SELECT COUNT(*)
FROM {$taxonomy} tt
LEFT JOIN {$terms} t ON t.term_id = tt.term_id
WHERE t.term_id IS NULL
SQL)->fetchColumn();

    return [
        'engine' => [
            'source' => NJ_API_SOURCE,
            'tablePrefix' => (string) nj_db_config()['table_prefix'],
        ],
        'postTypes' => array_values($postTypes),
        'taxonomies' => $taxonomies,
        'metaKeys' => $metaKeys,
        'counts' => [
            'users' => $userCount,
            'comments' => array_sum($commentCounts),
            'revisions' => $revisionCount,
            'attachments' => $attachmentCount,
            'navMenus' => $menuCount,
            'navMenuItems' => $menuItemCount,
        ],
        'comments' => $commentCounts,
        'options' => [
            'stickyPosts' => isset($presentOptions['sticky_posts']),
            'activePlugins' => isset($presentOptions['active_plugins']),
            'navMenuOptions' => isset($presentOptions['nav_menu_options']),
            'permalinkStructure' => isset($presentOptions['permalink_structure']),
            'timezone' => isset($presentOptions['timezone_string']),
        ],
        'health' => [
            'orphanRelationships' => $orphanRelationshipCount,
            'orphanTaxonomyTerms' => $orphanTermCount,
        ],
        'opportunities' => [
            [
                'key' => 'revisions',
                'available' => $revisionCount > 0,
                'label' => 'Histórico nativo de revisões',
                'description' => 'Posts do tipo revision e post_parent permitem comparar e restaurar versões preservando o modelo WordPress.',
            ],
            [
                'key' => 'menus',
                'available' => $menuCount > 0 || $menuItemCount > 0,
                'label' => 'Menus nativos',
                'description' => 'nav_menu e nav_menu_item podem alimentar cabeçalho e rodapé sem hardcode quando houver menus úteis no acervo.',
            ],
            [
                'key' => 'sticky',
                'available' => isset($presentOptions['sticky_posts']),
                'label' => 'Destaques nativos',
                'description' => 'sticky_posts pode ser usado como sinal editorial complementar para capa e listas, com fallback para a curadoria atual.',
            ],
            [
                'key' => 'comments',
                'available' => array_sum($commentCounts) > 0,
                'label' => 'Discussão e moderação',
                'description' => 'comments e commentmeta permitem threads, moderação, denúncias e histórico sem criar uma tabela social paralela.',
            ],
            [
                'key' => 'attachments',
                'available' => $attachmentCount > 0,
                'label' => 'Biblioteca e metadados de mídia',
                'description' => 'Attachments e _wp_attachment_metadata permitem dimensões, derivados, legendas, alt text e auditoria de uso.',
            ],
            [
                'key' => 'taxonomies',
                'available' => count($taxonomies) > 0,
                'label' => 'Taxonomias reutilizáveis',
                'description' => 'Categorias, tags, menus e taxonomias de plugins podem virar filtros, coleções e páginas de descoberta.',
            ],
        ],
    ];
});
