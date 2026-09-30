<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_content.php';
require_once __DIR__ . '/_authors.php';

nj_run(static function (): array {
    $slug = trim((string) ($_GET['slug'] ?? ''));

    if ($slug === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) {
        throw new NjApiHttpException(400, 'invalid_author_slug');
    }

    $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, [
        'options' => ['default' => 1, 'min_range' => 1],
    ]);
    $perPage = filter_input(INPUT_GET, 'per_page', FILTER_VALIDATE_INT, [
        'options' => ['default' => 12, 'min_range' => 1, 'max_range' => 24],
    ]);

    $page = is_int($page) ? $page : 1;
    $perPage = is_int($perPage) ? $perPage : 12;
    $offset = ($page - 1) * $perPage;

    $pdo = nj_db();
    $profile = nj_author_profile($pdo, $slug);

    if ($profile === null) {
        $profile = nj_columnist_profile_by_slug($pdo, $slug);
    }

    if ($profile === null) {
        throw new NjApiHttpException(404, 'author_not_found');
    }

    $posts = nj_table('posts');
    $postmeta = nj_table('postmeta');
    $users = nj_table('users');

    $select = nj_content_article_select($posts, $postmeta, $users);
    $statement = $pdo->prepare($select . <<<SQL

WHERE
    (
        p.post_author = :primary_author_id
        OR EXISTS (
            SELECT 1
            FROM {$postmeta} coauthor_meta
            WHERE
                coauthor_meta.post_id = p.ID
                AND coauthor_meta.meta_key = '_nj_coauthors'
                AND FIND_IN_SET(
                    CAST(:coauthor_id AS CHAR),
                    REPLACE(
                        REPLACE(
                            REPLACE(
                                REPLACE(
                                    REPLACE(
                                        REPLACE(coauthor_meta.meta_value, '[', ''),
                                        ']', ''
                                    ),
                                    ' ',
                                    ''
                                ),
                                CHAR(10),
                                ''
                            ),
                            CHAR(13),
                            ''
                        ),
                        CHAR(9),
                        ''
                    )
                ) > 0
        )
    )
    AND p.post_type = 'post'
    AND p.post_status = 'publish'
    AND p.post_password = ''
    AND p.post_title <> ''
    AND p.post_name <> ''
ORDER BY p.post_date DESC, p.ID DESC
LIMIT {$perPage} OFFSET {$offset}
SQL);
    $statement->execute([
        'primary_author_id' => (int) $profile['id'],
        'coauthor_id' => (int) $profile['id'],
    ]);
    $items = nj_content_hydrate_articles($pdo, $statement->fetchAll());

    $total = (int) $profile['publishedCount'];
    $totalPages = max(1, (int) ceil($total / $perPage));

    return [
        'author' => $profile,
        'items' => $items,
        'pagination' => [
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'totalPages' => $totalPages,
            'hasPrevious' => $page > 1,
            'hasNext' => $page < $totalPages,
        ],
    ];
}, 'public, max-age=60, stale-while-revalidate=300');
