<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

nj_admin_run(['GET'], static function (): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'edit_posts');

    $query = trim((string) ($_GET['q'] ?? ''));
    $exclude = filter_input(INPUT_GET, 'exclude', FILTER_VALIDATE_INT, [
        'options' => ['default' => 0, 'min_range' => 0],
    ]);
    $exclude = is_int($exclude) ? $exclude : 0;

    if (function_exists('mb_substr')) {
        $query = mb_substr($query, 0, 120, 'UTF-8');
    } else {
        $query = substr($query, 0, 120);
    }

    $pdo = nj_db();
    $posts = nj_table('posts');

    $where = [
        "post_type = 'post'",
        "post_status <> 'trash'",
        "post_title <> ''",
    ];
    $params = [];

    if ($exclude > 0) {
        $where[] = 'ID <> :exclude';
        $params['exclude'] = $exclude;
    }

    if ($query !== '') {
        $where[] = '(post_title LIKE :query OR post_name LIKE :query)';
        $params['query'] = '%' . $query . '%';
    }

    $whereSql = implode("\n    AND ", $where);

    $statement = $pdo->prepare(<<<SQL
SELECT
    ID,
    post_title,
    post_name,
    post_status,
    post_date,
    post_modified
FROM {$posts}
WHERE {$whereSql}
ORDER BY
    CASE post_status
        WHEN 'publish' THEN 0
        WHEN 'future' THEN 1
        WHEN 'draft' THEN 2
        WHEN 'pending' THEN 3
        ELSE 4
    END,
    post_modified DESC,
    ID DESC
LIMIT 20
SQL);
    $statement->execute($params);

    $items = [];
    foreach ($statement->fetchAll() as $row) {
        $status = (string) $row['post_status'];
        $slug = trim((string) $row['post_name']);

        $items[] = [
            'id' => (int) $row['ID'],
            'title' => nj_content_clean_text_source((string) $row['post_title']),
            'status' => $status,
            'publishedAt' => nj_content_iso8601((string) $row['post_date']),
            'modifiedAt' => nj_content_iso8601((string) $row['post_modified']),
            'publicUrl' => $status === 'publish' && $slug !== ''
                ? '/noticia/' . rawurlencode($slug)
                : null,
        ];
    }

    return [
        'items' => $items,
        'query' => $query,
    ];
});
