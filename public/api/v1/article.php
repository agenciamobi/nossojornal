<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_content.php';

nj_run(static function (): array {
    $slug = trim((string) ($_GET['slug'] ?? ''));

    if ($slug === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) {
        nj_json_response(400, [
            'ok' => false,
            'error' => ['code' => 'invalid_slug'],
        ]);
    }

    $pdo = nj_db();
    $posts = nj_table('posts');
    $postmeta = nj_table('postmeta');
    $users = nj_table('users');
    $relationships = nj_table('term_relationships');
    $taxonomy = nj_table('term_taxonomy');

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
        nj_json_response(404, [
            'ok' => false,
            'error' => ['code' => 'article_not_found'],
        ]);
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

INNER JOIN {$relationships} related_tr
    ON related_tr.object_id = p.ID
WHERE
    p.post_type = 'post'
    AND p.post_status = 'publish'
    AND p.post_password = ''
    AND p.ID <> ?
    AND related_tr.term_taxonomy_id IN ({$placeholders})
GROUP BY p.ID
ORDER BY p.post_date DESC, p.ID DESC
LIMIT 4
SQL;

        $relatedStatement = $pdo->prepare($relatedSql);
        $relatedStatement->execute(array_merge([$article['id']], $categoryTaxonomyIds));
        $related = nj_content_hydrate_articles($pdo, $relatedStatement->fetchAll());
    }

    return [
        'article' => $article,
        'related' => $related,
        'seo' => [
            'title' => $seo['title'] !== '' ? $seo['title'] : $article['title'],
            'description' => $seo['description'] !== '' ? $seo['description'] : $article['excerpt'],
            'canonical' => $article['url'],
        ],
    ];
}, 'public, max-age=60, stale-while-revalidate=300');
