<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';
require_once __DIR__ . '/../v1/_category_theme.php';

nj_admin_run(['GET'], static function (): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'edit_posts');

    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if (!is_int($id) || $id <= 0) {
        throw new NjApiHttpException(400, 'invalid_post_id');
    }

    $pdo = nj_db();
    $posts = nj_table('posts');
    $users = nj_table('users');
    $postmeta = nj_table('postmeta');
    $relationships = nj_table('term_relationships');
    $taxonomy = nj_table('term_taxonomy');
    $terms = nj_table('terms');

    $statement = $pdo->prepare(<<<SQL
SELECT
    p.ID AS id,
    p.post_author AS author_id,
    p.post_title AS title,
    p.post_name AS slug,
    p.post_excerpt AS excerpt,
    p.post_content AS content,
    p.post_status AS status,
    p.post_date AS published_at,
    p.post_modified AS modified_at,
    COALESCE(u.display_name, u.user_login, '') AS author_name
FROM {$posts} p
LEFT JOIN {$users} u ON u.ID = p.post_author
WHERE
    p.ID = :id
    AND p.post_type = 'post'
LIMIT 1
SQL);
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();

    if (!$row) {
        throw new NjApiHttpException(404, 'post_not_found');
    }

    $categoryStatement = $pdo->prepare(<<<SQL
SELECT
    t.term_id AS id,
    t.name,
    t.slug
FROM {$relationships} tr
INNER JOIN {$taxonomy} tt
    ON tt.term_taxonomy_id = tr.term_taxonomy_id
    AND tt.taxonomy = 'category'
INNER JOIN {$terms} t ON t.term_id = tt.term_id
WHERE tr.object_id = :post_id
ORDER BY t.name ASC
SQL);
    $categoryStatement->execute(['post_id' => $id]);
    $selectedCategories = $categoryStatement->fetchAll();

    $allCategoryRows = $pdo->query(<<<SQL
SELECT
    t.term_id AS id,
    t.name,
    t.slug,
    tt.parent AS parent_id
FROM {$terms} t
INNER JOIN {$taxonomy} tt
    ON tt.term_id = t.term_id
    AND tt.taxonomy = 'category'
ORDER BY
    CASE WHEN tt.parent = 0 THEN 0 ELSE 1 END,
    t.name ASC
SQL)->fetchAll();

    $colorOverrides = nj_category_color_overrides(
        $pdo,
        array_map(static fn (array $category): int => (int) $category['id'], $allCategoryRows)
    );

    $allCategories = [];
    foreach ($allCategoryRows as $category) {
        $categoryId = (int) $category['id'];
        $slug = (string) $category['slug'];

        $allCategories[] = [
            'id' => $categoryId,
            'name' => (string) $category['name'],
            'slug' => $slug,
            'parentId' => (int) $category['parent_id'] > 0 ? (int) $category['parent_id'] : null,
            'color' => nj_category_color_for($categoryId, $slug, $colorOverrides),
        ];
    }

    $metaStatement = $pdo->prepare(<<<SQL
SELECT meta_key, meta_value
FROM {$postmeta}
WHERE
    post_id = :post_id
    AND meta_key IN (
        '_thumbnail_id',
        '_yoast_wpseo_title',
        '_yoast_wpseo_metadesc',
        '_yoast_wpseo_primary_category'
    )
ORDER BY meta_id DESC
SQL);
    $metaStatement->execute(['post_id' => $id]);

    $meta = [];
    foreach ($metaStatement->fetchAll() as $metaRow) {
        $key = (string) $metaRow['meta_key'];

        if (!array_key_exists($key, $meta)) {
            $meta[$key] = (string) $metaRow['meta_value'];
        }
    }

    $featuredImage = null;
    $thumbnailId = (int) ($meta['_thumbnail_id'] ?? 0);

    if ($thumbnailId > 0) {
        $imageStatement = $pdo->prepare(<<<SQL
SELECT
    p.ID AS id,
    p.guid AS url,
    p.post_title AS title,
    COALESCE(alt.meta_value, '') AS alt
FROM {$posts} p
LEFT JOIN {$postmeta} alt
    ON alt.post_id = p.ID
    AND alt.meta_key = '_wp_attachment_image_alt'
WHERE
    p.ID = :id
    AND p.post_type = 'attachment'
LIMIT 1
SQL);
        $imageStatement->execute(['id' => $thumbnailId]);
        $imageRow = $imageStatement->fetch();

        if ($imageRow) {
            $featuredImage = [
                'id' => (int) $imageRow['id'],
                'url' => nj_content_local_media_url((string) $imageRow['url']),
                'title' => (string) $imageRow['title'],
                'alt' => (string) $imageRow['alt'],
            ];
        }
    }

    return [
        'post' => [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'slug' => (string) $row['slug'],
            'excerpt' => (string) $row['excerpt'],
            'content' => (string) $row['content'],
            'status' => (string) $row['status'],
            'publishedAt' => nj_content_iso8601((string) $row['published_at']),
            'modifiedAt' => nj_content_iso8601((string) $row['modified_at']),
            'author' => [
                'id' => (int) $row['author_id'],
                'name' => (string) $row['author_name'],
            ],
            'categories' => array_map(
                static fn (array $category): array => [
                    'id' => (int) $category['id'],
                    'name' => (string) $category['name'],
                    'slug' => (string) $category['slug'],
                ],
                $selectedCategories
            ),
            'featuredImage' => $featuredImage,
            'seo' => [
                'title' => nj_content_clean_text_source((string) ($meta['_yoast_wpseo_title'] ?? '')),
                'description' => nj_content_clean_text_source((string) ($meta['_yoast_wpseo_metadesc'] ?? '')),
                'primaryCategoryId' => (int) ($meta['_yoast_wpseo_primary_category'] ?? 0),
            ],
            'publicUrl' => trim((string) $row['slug']) !== ''
                ? '/noticia/' . rawurlencode((string) $row['slug'])
                : null,
        ],
        'categories' => $allCategories,
    ];
});
