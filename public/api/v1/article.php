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
    AND meta_key IN (
        '_yoast_wpseo_title',
        '_yoast_wpseo_metadesc',
        '_nj_article_type',
        '_nj_kicker',
        '_nj_standfirst',
        '_nj_dateline',
        '_nj_coauthors',
        '_nj_image_credit',
        '_nj_image_caption',
        '_nj_editorial_video_attachment_ids',
        '_nj_original_source_url',
        '_nj_canonical_url',
        '_nj_provenance_mode',
        '_nj_provenance_source_name',
        '_nj_provenance_source_url',
        '_nj_provenance_source_published_at',
        '_nj_social_title',
        '_nj_social_description',
        '_nj_related_post_ids',
        '_nj_series_name',
        '_nj_series_slug',
        '_nj_series_order'
    )
ORDER BY meta_id DESC
SQL);
    $metaStatement->execute(['post_id' => $article['id']]);

    $metaValues = [];
    foreach ($metaStatement->fetchAll() as $meta) {
        $key = (string) $meta['meta_key'];
        if (!array_key_exists($key, $metaValues)) {
            $metaValues[$key] = trim((string) $meta['meta_value']);
        }
    }

    $seo = [
        'title' => (string) ($metaValues['_yoast_wpseo_title'] ?? ''),
        'description' => (string) ($metaValues['_yoast_wpseo_metadesc'] ?? ''),
    ];

    $articleType = (string) ($metaValues['_nj_article_type'] ?? 'news');
    if (!in_array($articleType, ['news', 'analysis', 'opinion', 'interview', 'service', 'live'], true)) {
        $articleType = 'news';
    }

    $provenanceMode = (string) ($metaValues['_nj_provenance_mode'] ?? 'original');
    if (!in_array($provenanceMode, ['original', 'adapted', 'republished'], true)) {
        $provenanceMode = 'original';
    }

    $coauthorIds = [];
    $decodedCoauthors = json_decode((string) ($metaValues['_nj_coauthors'] ?? '[]'), true);
    if (is_array($decodedCoauthors)) {
        $coauthorIds = array_values(array_unique(array_filter(
            array_map('intval', $decodedCoauthors),
            static fn (int $id): bool => $id > 0
        )));
    }

    $coauthors = [];
    if ($coauthorIds !== []) {
        $placeholders = implode(',', array_fill(0, count($coauthorIds), '?'));
        $coauthorStatement = $pdo->prepare(
            "SELECT ID, user_login, user_nicename, display_name
             FROM {$users}
             WHERE ID IN ({$placeholders})
             ORDER BY display_name ASC, user_login ASC"
        );
        $coauthorStatement->execute($coauthorIds);

        foreach ($coauthorStatement->fetchAll() as $coauthor) {
            $coauthorSlug = trim((string) ($coauthor['user_nicename'] ?? ''));

            $coauthors[] = [
                'id' => (int) $coauthor['ID'],
                'name' => trim((string) $coauthor['display_name']) !== ''
                    ? (string) $coauthor['display_name']
                    : (string) $coauthor['user_login'],
                'slug' => $coauthorSlug,
                'url' => $coauthorSlug !== ''
                    ? '/autor/' . rawurlencode($coauthorSlug)
                    : null,
            ];
        }
    }

    $categoryTaxonomyIds = array_values(array_filter(array_map(
        static fn (array $category): int => (int) $category['taxonomyId'],
        $article['categories']
    )));

    $manualRelatedIds = [];
    $decodedRelated = json_decode((string) ($metaValues['_nj_related_post_ids'] ?? '[]'), true);
    if (is_array($decodedRelated)) {
        $manualRelatedIds = array_values(array_unique(array_filter(
            array_map('intval', $decodedRelated),
            static fn (int $id): bool => $id > 0 && $id !== (int) $article['id']
        )));
    }

    $related = [];
    if ($manualRelatedIds !== []) {
        $placeholders = implode(',', array_fill(0, count($manualRelatedIds), '?'));
        $manualSql = $select . <<<SQL

WHERE
    p.post_type = 'post'
    AND p.post_status = 'publish'
    AND p.post_password = ''
    AND p.ID IN ({$placeholders})
ORDER BY FIELD(p.ID, {$placeholders})
LIMIT 4
SQL;

        $manualStatement = $pdo->prepare($manualSql);
        $manualStatement->execute(array_merge($manualRelatedIds, $manualRelatedIds));
        $related = nj_content_hydrate_articles($pdo, $manualStatement->fetchAll());
    }

    if (count($related) < 4 && $categoryTaxonomyIds !== []) {
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
LIMIT 8
SQL;

        $relatedStatement = $pdo->prepare($relatedSql);
        $relatedStatement->execute(array_merge([$article['id']], $categoryTaxonomyIds));
        $automaticRelated = nj_content_hydrate_articles($pdo, $relatedStatement->fetchAll());
        $alreadyRelated = array_fill_keys(array_map(
            static fn (array $item): int => (int) $item['id'],
            $related
        ), true);

        foreach ($automaticRelated as $candidate) {
            $candidateId = (int) $candidate['id'];
            if (isset($alreadyRelated[$candidateId])) {
                continue;
            }

            $related[] = $candidate;
            $alreadyRelated[$candidateId] = true;

            if (count($related) >= 4) {
                break;
            }
        }
    }


    // Attachment metadata stays authoritative for library-edited credit/caption.
    $featuredCredit = '';
    $featuredCaption = '';
    $featuredMetadata = $pdo->prepare(
        "SELECT a.post_excerpt AS caption, m.meta_value AS credit
         FROM {$postmeta} thumb
         INNER JOIN {$posts} a ON a.ID = CAST(thumb.meta_value AS UNSIGNED)
            AND a.post_type = 'attachment' AND a.post_mime_type LIKE 'image/%'
         LEFT JOIN {$postmeta} m ON m.post_id = a.ID AND m.meta_key = '_nj_media_credit'
         WHERE thumb.post_id = :id AND thumb.meta_key = '_thumbnail_id'
         ORDER BY m.meta_id DESC LIMIT 1"
    );
    $featuredMetadata->execute(['id' => $article['id']]);
    $featuredRow = $featuredMetadata->fetch();
    if (is_array($featuredRow)) {
        $featuredCredit = trim((string) ($featuredRow['credit'] ?? ''));
        $featuredCaption = trim((string) ($featuredRow['caption'] ?? ''));
    }

    // Each video is a metadata-only media library attachment; no video bytes
    // are stored or served by Nosso Jornal. Iframe URLs are constructed from
    // strictly validated YouTube IDs, never copied from an external input.
    $linkedVideoIds = json_decode((string) ($metaValues['_nj_editorial_video_attachment_ids'] ?? '[]'), true);
    $linkedVideoIds = is_array($linkedVideoIds)
        ? array_slice(array_values(array_unique(array_filter(array_map('intval', $linkedVideoIds), static fn (int $v): bool => $v > 0))), 0, 6)
        : [];
    if ($linkedVideoIds !== []) {
        $placeholders = implode(',', array_fill(0, count($linkedVideoIds), '?'));
        $videoRows = $pdo->prepare(
            "SELECT a.ID, a.post_title AS title, a.post_excerpt AS caption,
             MAX(CASE WHEN m.meta_key = '_nj_embed_youtube_id' THEN m.meta_value END) AS youtube_id,
             MAX(CASE WHEN m.meta_key = '_nj_media_credit' THEN m.meta_value END) AS credit,
             MAX(CASE WHEN m.meta_key = '_nj_media_license' THEN m.meta_value END) AS license,
             MAX(CASE WHEN m.meta_key = '_nj_media_seo_title' THEN m.meta_value END) AS seo_title,
             MAX(CASE WHEN m.meta_key = '_nj_media_seo_description' THEN m.meta_value END) AS seo_description,
             MAX(CASE WHEN m.meta_key = '_nj_remote_media_source_page' THEN m.meta_value END) AS source_page
             FROM {$posts} a
             INNER JOIN {$postmeta} m ON m.post_id = a.ID
             WHERE a.ID IN ({$placeholders}) AND a.post_type = 'attachment'
             AND a.post_mime_type = 'video/x-embed'
             GROUP BY a.ID, a.post_title, a.post_excerpt"
        );
        $videoRows->execute($linkedVideoIds);
        $videos = [];
        foreach ($videoRows->fetchAll() as $video) {
            $id = (string) ($video['youtube_id'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) continue;
            $videos[(int) $video['ID']] = [
                'provider' => 'youtube',
                'id' => $id,
                'embedUrl' => 'https://www.youtube-nocookie.com/embed/' . $id,
                'title' => nj_content_clean_text_source((string) $video['title']),
                'caption' => nj_content_clean_text_source((string) $video['caption']),
                'credit' => nj_content_clean_text_source((string) ($video['credit'] ?? '')),
                'license' => nj_content_clean_text_source((string) ($video['license'] ?? '')),
                'seoTitle' => nj_content_clean_text_source((string) ($video['seo_title'] ?? '')),
                'seoDescription' => nj_content_clean_text_source((string) ($video['seo_description'] ?? '')),
                'sourcePage' => (string) ($video['source_page'] ?? ''),
            ];
        }
        $existingVideoIds = array_fill_keys(array_column($article['videos'] ?? [], 'id'), true);
        foreach ($linkedVideoIds as $linkedId) {
            if (!isset($videos[$linkedId])) continue;
            $video = $videos[$linkedId];
            if (!isset($existingVideoIds[$video['id']])) {
                $article['videos'][] = $video;
                $existingVideoIds[$video['id']] = true;
            }
        }
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

    $canonical = (string) ($metaValues['_nj_canonical_url'] ?? '');
    if ($canonical === '' || !filter_var($canonical, FILTER_VALIDATE_URL)) {
        $canonical = $article['url'];
    }

    $socialTitle = nj_content_clean_text_source((string) ($metaValues['_nj_social_title'] ?? ''));
    $socialDescription = nj_content_clean_text_source((string) ($metaValues['_nj_social_description'] ?? ''));

    return [
        'article' => $article,
        'related' => $related,
        'corrections' => $corrections,
        'editorial' => [
            'articleType' => $articleType,
            'kicker' => nj_content_clean_text_source((string) ($metaValues['_nj_kicker'] ?? '')),
            'standfirst' => nj_content_clean_text_source((string) ($metaValues['_nj_standfirst'] ?? '')),
            'dateline' => nj_content_clean_text_source((string) ($metaValues['_nj_dateline'] ?? '')),
            'coauthors' => $coauthors,
            'imageCredit' => nj_content_clean_text_source($featuredCredit !== '' ? $featuredCredit : (string) ($metaValues['_nj_image_credit'] ?? '')),
            'imageCaption' => nj_content_clean_text_source($featuredCaption !== '' ? $featuredCaption : (string) ($metaValues['_nj_image_caption'] ?? '')),
            'originalSourceUrl' => (string) ($metaValues['_nj_original_source_url'] ?? ''),
            'provenance' => [
                'mode' => $provenanceMode,
                'sourceName' => nj_content_clean_text_source((string) ($metaValues['_nj_provenance_source_name'] ?? '')),
                'sourceUrl' => (string) ($metaValues['_nj_provenance_source_url'] ?? ''),
                'sourcePublishedAt' => (string) ($metaValues['_nj_provenance_source_published_at'] ?? ''),
            ],
            'series' => [
                'name' => nj_content_clean_text_source((string) ($metaValues['_nj_series_name'] ?? '')),
                'slug' => (string) ($metaValues['_nj_series_slug'] ?? ''),
                'order' => max(0, min(999, (int) ($metaValues['_nj_series_order'] ?? 0))),
                'url' => trim((string) ($metaValues['_nj_series_slug'] ?? '')) !== ''
                    ? '/dossie/' . rawurlencode((string) $metaValues['_nj_series_slug'])
                    : null,
            ],
        ],
        'seo' => [
            'title' => $seo['title'] !== '' ? nj_content_clean_text_source($seo['title']) : $article['title'],
            'description' => $seoDescription,
            'canonical' => $canonical,
            'socialTitle' => $socialTitle,
            'socialDescription' => $socialDescription,
        ],
    ];
}, 'public, max-age=60, stale-while-revalidate=300');
