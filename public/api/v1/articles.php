<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_content.php';

nj_run(static function (): array {
    $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, [
        'options' => ['default' => 1, 'min_range' => 1],
    ]);
    $perPage = filter_input(INPUT_GET, 'per_page', FILTER_VALIDATE_INT, [
        'options' => ['default' => 12, 'min_range' => 1, 'max_range' => 24],
    ]);

    $page = is_int($page) ? $page : 1;
    $perPage = is_int($perPage) ? $perPage : 12;
    $offset = ($page - 1) * $perPage;

    $categorySlug = trim((string) ($_GET['category'] ?? ''));
    $tagSlug = trim((string) ($_GET['tag'] ?? ''));
    $query = trim((string) ($_GET['q'] ?? ''));

    if ($categorySlug !== '' && !preg_match('/^[a-z0-9-]+$/', $categorySlug)) {
        throw new NjApiHttpException(400, 'invalid_category');
    }

    if ($tagSlug !== '' && !preg_match('/^[a-z0-9-]+$/', $tagSlug)) {
        throw new NjApiHttpException(400, 'invalid_tag');
    }

    if ($categorySlug !== '' && $tagSlug !== '') {
        throw new NjApiHttpException(400, 'conflicting_taxonomy_filter');
    }

    if (function_exists('mb_substr')) {
        $query = mb_substr($query, 0, 120, 'UTF-8');
    } else {
        $query = substr($query, 0, 120);
    }

    $pdo = nj_db();
    $posts = nj_table('posts');
    $postmeta = nj_table('postmeta');
    $users = nj_table('users');
    $terms = nj_table('terms');
    $taxonomy = nj_table('term_taxonomy');
    $relationships = nj_table('term_relationships');

    $where = [
        "p.post_type = 'post'",
        "p.post_status = 'publish'",
        "p.post_password = ''",
        "p.post_title <> ''",
        "p.post_name <> ''",
    ];
    $params = [];
    $category = null;
    $tag = null;

    if ($categorySlug !== '') {
        $categoryStatement = $pdo->prepare(<<<SQL
SELECT
    t.term_id AS id,
    tt.term_taxonomy_id AS taxonomy_id,
    t.name,
    t.slug,
    tt.parent AS parent_id
FROM {$terms} t
INNER JOIN {$taxonomy} tt
    ON tt.term_id = t.term_id
    AND tt.taxonomy = 'category'
WHERE t.slug = :slug
LIMIT 1
SQL);
        $categoryStatement->execute(['slug' => $categorySlug]);
        $categoryRow = $categoryStatement->fetch();

        if (!$categoryRow) {
            throw new NjApiHttpException(404, 'category_not_found');
        }

        $categoryColorOverrides = nj_category_color_overrides(
            $pdo,
            [(int) $categoryRow['id']]
        );

        $category = [
            'id' => (int) $categoryRow['id'],
            'taxonomyId' => (int) $categoryRow['taxonomy_id'],
            'name' => (string) $categoryRow['name'],
            'slug' => (string) $categoryRow['slug'],
            'parentId' => (int) $categoryRow['parent_id'] > 0 ? (int) $categoryRow['parent_id'] : null,
            'url' => '/categoria/' . rawurlencode((string) $categoryRow['slug']),
            'color' => nj_category_color_for(
                (int) $categoryRow['id'],
                (string) $categoryRow['slug'],
                $categoryColorOverrides
            ),
            'colorSource' => nj_category_color_source_for(
                (int) $categoryRow['id'],
                $categoryColorOverrides
            ),
        ];

        $where[] = "EXISTS (
            SELECT 1
            FROM {$relationships} category_tr
            WHERE
                category_tr.object_id = p.ID
                AND category_tr.term_taxonomy_id = :taxonomy_id
        )";
        $params['taxonomy_id'] = $category['taxonomyId'];
    }

    if ($tagSlug !== '') {
        $tagStatement = $pdo->prepare(<<<SQL
SELECT
    t.term_id AS id,
    tt.term_taxonomy_id AS taxonomy_id,
    t.name,
    t.slug
FROM {$terms} t
INNER JOIN {$taxonomy} tt
    ON tt.term_id = t.term_id
    AND tt.taxonomy = 'post_tag'
WHERE t.slug = :slug
LIMIT 1
SQL);
        $tagStatement->execute(['slug' => $tagSlug]);
        $tagRow = $tagStatement->fetch();

        if (!$tagRow) {
            throw new NjApiHttpException(404, 'tag_not_found');
        }

        $tag = [
            'id' => (int) $tagRow['id'],
            'taxonomyId' => (int) $tagRow['taxonomy_id'],
            'name' => (string) $tagRow['name'],
            'slug' => (string) $tagRow['slug'],
            'url' => '/tag/' . rawurlencode((string) $tagRow['slug']),
        ];

        $where[] = "EXISTS (
            SELECT 1
            FROM {$relationships} tag_tr
            WHERE
                tag_tr.object_id = p.ID
                AND tag_tr.term_taxonomy_id = :tag_taxonomy_id
        )";
        $params['tag_taxonomy_id'] = $tag['taxonomyId'];
    }

    if ($query !== '') {
        $where[] = "(
            p.post_title LIKE :search_title
            OR p.post_excerpt LIKE :search_excerpt
            OR p.post_content LIKE :search_content
        )";
        $searchValue = '%' . $query . '%';
        $params['search_title'] = $searchValue;
        $params['search_excerpt'] = $searchValue;
        $params['search_content'] = $searchValue;
    }

    $whereSql = implode("\n    AND ", $where);

    $countStatement = $pdo->prepare("SELECT COUNT(*) FROM {$posts} p WHERE {$whereSql}");
    $countStatement->execute($params);
    $total = (int) $countStatement->fetchColumn();

    $select = nj_content_article_select($posts, $postmeta, $users);
    $sql = $select . <<<SQL

WHERE {$whereSql}
ORDER BY p.post_date DESC, p.ID DESC
LIMIT {$perPage} OFFSET {$offset}
SQL;

    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $items = nj_content_hydrate_articles($pdo, $statement->fetchAll());

    $totalPages = max(1, (int) ceil($total / $perPage));

    return [
        'items' => $items,
        'category' => $category,
        'tag' => $tag,
        'query' => $query,
        'pagination' => [
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'totalPages' => $totalPages,
            'hasPrevious' => $page > 1,
            'hasNext' => $page < $totalPages,
        ],
    ];
}, 'public, max-age=10, stale-while-revalidate=30');
