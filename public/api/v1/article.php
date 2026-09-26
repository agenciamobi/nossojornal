<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_content.php';

nj_run(static function (): array {
    $slug = trim((string) ($_GET['slug'] ?? ''));

    if ($slug === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) {
        throw new NjApiHttpException(400, 'invalid_slug');
    }

    $pdo = nj_db();
    $posts = nj_table('posts');
    $postmeta = nj_table('postmeta');
    $users = nj_table('users');
    $relationships = nj_table('term_relationships');

    $select = nj_content_article_select($posts, $postmeta, $users);
    $statement = $pdo->prepare($select . <<<SQL

WHERE
    p.post_type = 'post'
    AND p.post_status = 'publish'
    AND p.post_password = ''
    AND p.post_name = :slug
LIMIT 1
SQL);
    $statement->execute(['slug' => $slug]);
    $row = $statement->fetch();

    if (!$row) {
        throw new NjApiHttpException(404, 'article_not_found');
    }

    $articles = nj_content_hydrate_articles($pdo, [$row], true);
    $article = $articles[0];

    $metaStatement = $pdo->prepare(<<<SQL
SELECT meta_key, meta_value
FROM {$postmeta}
WHERE
    post_id = :post_id
    AND meta_key IN ('_yoast_wpseo_title', '_yoast_wpseo_metadesc')
SQL);
    $metaStatement->execute(['post_id' => $article['id']]);

    $seo = ['title' => '', 'description' => ''];
    foreach ($metaStatement->fetchAll() as $meta) {
        if ($meta['meta_key'] === '_yoast_wpseo_title') {
            $seo['title'] = trim((string) $meta['meta_value']);
        }
        if ($meta['meta_key'] === '_yoast_wpseo_metadesc') {
            $seo['description'] = trim((string) $meta['meta_value']);
        }
    }

    $categoryTaxonomyIds = array_values(array_filter(array_map(
        static fn (array $category): int => (int) $category['taxonomyId'],
        $article['categories']
    )));

    $related = [];
    if ($categoryTaxonomyIds !== []) {
        $placeholders = implode(',', array_fill(0, count($categoryTaxonomyIds), '?'));
        $relatedSql = $select . <<<SQL

WHERE
    p.post_type = 'post'
    AND p.post_status = 'publish'
    AND p.post_password = ''
    AND p.ID <> ?
    AND EXISTS (
        SELECT 1
        FROM {$relationships} related_tr
        WHERE
            related_tr.object_id = p.ID
            AND related_tr.term_taxonomy_id IN ({$placeholders})
    )
ORDER BY p.post_date DESC, p.ID DESC
LIMIT 4
SQL;

        $relatedStatement = $pdo->prepare($relatedSql);
        $relatedStatement->execute(array_merge([$article['id']], $categoryTaxonomyIds));
        $related = nj_content_hydrate_articles($pdo, $relatedStatement->fetchAll());
    }

    $corrections = [];
    $correctionStatement = $pdo->prepare(<<<SQL
SELECT
    c.post_title AS type,
    c.post_content AS content,
    c.post_date AS created_at,
    c.post_modified AS modified_at
FROM {$posts} c
WHERE
    c.post_type = 'nj_correction'
    AND c.post_parent = :post_id
    AND c.post_status = 'private'
    AND EXISTS (
        SELECT 1
        FROM {$postmeta} pm
        WHERE
            pm.post_id = c.ID
            AND pm.meta_key = '_nj_correction_public'
            AND pm.meta_value = '1'
    )
ORDER BY c.post_date ASC, c.ID ASC
SQL);
    $correctionStatement->execute(['post_id' => $article['id']]);

    foreach ($correctionStatement->fetchAll() as $correction) {
        $type = (string) $correction['type'];
        if (!in_array($type, ['update', 'correction'], true)) {
            $type = 'update';
        }

        $corrections[] = [
            'type' => $type,
            'text' => nj_content_clean_text_source((string) $correction['content']),
            'createdAt' => nj_content_iso8601((string) $correction['created_at']),
            'modifiedAt' => nj_content_iso8601((string) $correction['modified_at']),
        ];
    }

    $seoDescription = $seo['description'] !== ''
        ? nj_content_excerpt($seo['description'], '', 240)
        : $article['excerpt'];

    return [
        'article' => $article,
        'related' => $related,
        'corrections' => $corrections,
        'seo' => [
            'title' => $seo['title'] !== '' ? nj_content_clean_text_source($seo['title']) : $article['title'],
            'description' => $seoDescription,
            'canonical' => $article['url'],
        ],
    ];
}, 'public, max-age=60, stale-while-revalidate=300');
