<?php
declare(strict_types=1);

require_once __DIR__ . '/_pautas.php';
require_once __DIR__ . '/../../admin/_post_categories.php';

const NJ_M2M_META_GENERATED_BY = '_nj_mobi_generated_by';
const NJ_M2M_META_GENERATED_VIA = '_nj_mobi_generated_via';
const NJ_M2M_META_HUMAN_REVIEW = '_nj_mobi_human_review_required';

function nj_m2m_default_author_id(PDO $pdo): int
{
    $users = nj_table('users');
    $statement = $pdo->prepare(
        "SELECT ID
         FROM {$users}
         WHERE user_login = :login AND user_status = 0
         LIMIT 1"
    );
    $statement->execute(['login' => NJ_PAUTAS_OWNER_LOGIN]);
    $id = (int) ($statement->fetchColumn() ?: 0);

    if ($id <= 0) {
        throw new NjApiHttpException(503, 'editorial_default_author_unavailable');
    }

    return $id;
}

function nj_m2m_resolve_author_id(PDO $pdo, array $pauta): int
{
    $candidate = (int) ($pauta['assignee_id'] ?? 0);
    if ($candidate <= 0) {
        return nj_m2m_default_author_id($pdo);
    }

    $users = nj_table('users');
    $statement = $pdo->prepare(
        "SELECT ID
         FROM {$users}
         WHERE ID = :id AND user_status = 0
         LIMIT 1"
    );
    $statement->execute(['id' => $candidate]);

    return (int) ($statement->fetchColumn() ?: 0) > 0
        ? $candidate
        : nj_m2m_default_author_id($pdo);
}

function nj_m2m_create_draft(PDO $pdo, array $pauta, string $title): int
{
    $posts = nj_table('posts');
    $authorId = nj_m2m_resolve_author_id($pdo, $pauta);

    $insert = $pdo->prepare(<<<SQL
INSERT INTO {$posts} (
    post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt,
    post_status, comment_status, ping_status, post_password, post_name, to_ping,
    pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent,
    guid, menu_order, post_type, post_mime_type, comment_count
) VALUES (
    :author_id, NOW(), UTC_TIMESTAMP(), '', :title, '', 'draft', 'closed', 'closed',
    '', '', '', '', NOW(), UTC_TIMESTAMP(), '', 0, '', 0, 'post', '', 0
)
SQL);
    $insert->execute([
        'author_id' => $authorId,
        'title' => $title,
    ]);

    $draftId = (int) $pdo->lastInsertId();
    if ($draftId <= 0) {
        throw new RuntimeException('draft_insert_missing_id');
    }

    nj_admin_upsert_postmeta($pdo, $draftId, '_nj_editorial_stage', 'writing');
    nj_admin_upsert_postmeta(
        $pdo,
        $draftId,
        '_nj_editorial_priority',
        trim((string) ($pauta['priority'] ?? '')) !== '' ? (string) $pauta['priority'] : 'normal'
    );
    nj_admin_upsert_postmeta(
        $pdo,
        $draftId,
        '_nj_editorial_deadline',
        (string) ($pauta['deadline'] ?? '')
    );
    nj_admin_upsert_postmeta(
        $pdo,
        $draftId,
        '_nj_editorial_assignee',
        (string) $authorId
    );
    nj_admin_upsert_postmeta(
        $pdo,
        $draftId,
        '_nj_reporting_notes',
        (string) ($pauta['notes'] ?? '')
    );

    $source = [];
    if (
        trim((string) ($pauta['source_name'] ?? '')) !== ''
        || trim((string) ($pauta['source_url'] ?? '')) !== ''
    ) {
        $source[] = [
            'name' => '',
            'organization' => (string) ($pauta['source_name'] ?? ''),
            'contact' => '',
            'url' => (string) ($pauta['source_url'] ?? ''),
            'note' => 'Origem da pauta',
        ];
    }

    nj_admin_upsert_postmeta(
        $pdo,
        $draftId,
        '_nj_reporting_sources',
        json_encode($source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]'
    );

    $hasExternalOrigin = trim((string) ($pauta['source_url'] ?? '')) !== '';
    nj_admin_upsert_postmeta(
        $pdo,
        $draftId,
        NJ_PROVENANCE_META_MODE,
        $hasExternalOrigin ? 'adapted' : 'original'
    );
    nj_admin_upsert_postmeta(
        $pdo,
        $draftId,
        NJ_PROVENANCE_META_SOURCE_NAME,
        (string) ($pauta['source_name'] ?? '')
    );
    nj_admin_upsert_postmeta(
        $pdo,
        $draftId,
        NJ_PROVENANCE_META_SOURCE_URL,
        (string) ($pauta['source_url'] ?? '')
    );
    nj_admin_upsert_postmeta(
        $pdo,
        $draftId,
        NJ_PROVENANCE_META_EXTERNAL_ID,
        (string) ($pauta['external_id'] ?? '')
    );
    nj_admin_upsert_postmeta(
        $pdo,
        $draftId,
        NJ_PROVENANCE_META_FEED_URL,
        (string) ($pauta['feed_url'] ?? '')
    );
    nj_admin_upsert_postmeta(
        $pdo,
        $draftId,
        NJ_PROVENANCE_META_CAPTURED_AT,
        (string) ($pauta['captured_at'] ?? '')
    );
    nj_admin_upsert_postmeta(
        $pdo,
        $draftId,
        NJ_PROVENANCE_META_SOURCE_PUBLISHED_AT,
        (string) ($pauta['source_published_at'] ?? '')
    );
    nj_admin_upsert_postmeta(
        $pdo,
        $draftId,
        NJ_PROVENANCE_META_SOURCE_HASH,
        (string) ($pauta['source_hash'] ?? '')
    );
    nj_admin_upsert_postmeta(
        $pdo,
        $draftId,
        NJ_PROVENANCE_META_PAUTA_ID,
        (string) (int) $pauta['id']
    );

    if ($hasExternalOrigin) {
        nj_admin_upsert_postmeta(
            $pdo,
            $draftId,
            '_nj_original_source_url',
            (string) $pauta['source_url']
        );
    }

    return $draftId;
}

function nj_m2m_category_map(PDO $pdo, array $slugs): array
{
    $slugs = array_values(array_unique(array_map(
        static fn (mixed $value): string => strtolower(trim((string) $value)),
        $slugs
    )));

    if ($slugs === []) {
        return [];
    }

    $terms = nj_table('terms');
    $taxonomy = nj_table('term_taxonomy');
    $placeholders = implode(',', array_fill(0, count($slugs), '?'));
    $statement = $pdo->prepare(
        "SELECT t.term_id, t.slug, tt.term_taxonomy_id
         FROM {$terms} t
         INNER JOIN {$taxonomy} tt
           ON tt.term_id = t.term_id AND tt.taxonomy = 'category'
         WHERE t.slug IN ({$placeholders})"
    );
    $statement->execute($slugs);

    $found = [];
    foreach ($statement->fetchAll() as $row) {
        $found[(string) $row['slug']] = [
            'term_id' => (int) $row['term_id'],
            'taxonomy_id' => (int) $row['term_taxonomy_id'],
        ];
    }

    foreach ($slugs as $slug) {
        if (!isset($found[$slug])) {
            throw new NjApiHttpException(422, 'category_not_found');
        }
    }

    return $found;
}

function nj_m2m_replace_categories(PDO $pdo, int $postId, array $slugs): int
{
    $taxonomy = nj_table('term_taxonomy');
    $relationships = nj_table('term_relationships');
    $map = nj_m2m_category_map($pdo, $slugs);
    $categoryIds = array_map(
        static fn (string $slug): int => (int) $map[$slug]['term_id'],
        $slugs
    );
    $policy = nj_post_category_policy($pdo, $categoryIds);

    $oldStatement = $pdo->prepare(
        "SELECT tr.term_taxonomy_id
         FROM {$relationships} tr
         INNER JOIN {$taxonomy} tt
           ON tt.term_taxonomy_id = tr.term_taxonomy_id
          AND tt.taxonomy = 'category'
         WHERE tr.object_id = :post_id"
    );
    $oldStatement->execute(['post_id' => $postId]);
    $oldIds = array_map('intval', $oldStatement->fetchAll(PDO::FETCH_COLUMN));

    $delete = $pdo->prepare(
        "DELETE tr
         FROM {$relationships} tr
         INNER JOIN {$taxonomy} tt
           ON tt.term_taxonomy_id = tr.term_taxonomy_id
          AND tt.taxonomy = 'category'
         WHERE tr.object_id = :post_id"
    );
    $delete->execute(['post_id' => $postId]);

    $newIds = [];
    if ($policy['taxonomyIds'] !== []) {
        $insert = $pdo->prepare(
            "INSERT INTO {$relationships} (object_id, term_taxonomy_id, term_order)
             VALUES (:post_id, :taxonomy_id, 0)"
        );

        foreach ($policy['categoryIds'] as $categoryId) {
            $taxonomyId = (int) $policy['taxonomyIds'][$categoryId];
            if (in_array($taxonomyId, $newIds, true)) {
                continue;
            }

            $newIds[] = $taxonomyId;
            $insert->execute([
                'post_id' => $postId,
                'taxonomy_id' => $taxonomyId,
            ]);
        }
    }

    nj_admin_recount_categories($pdo, array_merge($oldIds, $newIds));

    return (int) $policy['primaryCategoryId'];
}

function nj_m2m_replace_tags(PDO $pdo, int $postId, array $tagNames): void
{
    $terms = nj_table('terms');
    $taxonomy = nj_table('term_taxonomy');
    $relationships = nj_table('term_relationships');

    $oldStatement = $pdo->prepare(
        "SELECT tr.term_taxonomy_id
         FROM {$relationships} tr
         INNER JOIN {$taxonomy} tt
           ON tt.term_taxonomy_id = tr.term_taxonomy_id
          AND tt.taxonomy = 'post_tag'
         WHERE tr.object_id = :post_id"
    );
    $oldStatement->execute(['post_id' => $postId]);
    $oldIds = array_map('intval', $oldStatement->fetchAll(PDO::FETCH_COLUMN));

    $delete = $pdo->prepare(
        "DELETE tr
         FROM {$relationships} tr
         INNER JOIN {$taxonomy} tt
           ON tt.term_taxonomy_id = tr.term_taxonomy_id
          AND tt.taxonomy = 'post_tag'
         WHERE tr.object_id = :post_id"
    );
    $delete->execute(['post_id' => $postId]);

    $find = $pdo->prepare(
        "SELECT t.term_id, tt.term_taxonomy_id
         FROM {$terms} t
         INNER JOIN {$taxonomy} tt
           ON tt.term_id = t.term_id AND tt.taxonomy = 'post_tag'
         WHERE t.slug = :slug OR t.name = :name
         ORDER BY CASE WHEN t.slug = :preferred_slug THEN 0 ELSE 1 END, t.term_id ASC
         LIMIT 1"
    );
    $slugExists = $pdo->prepare("SELECT 1 FROM {$terms} WHERE slug = :slug LIMIT 1");
    $insertTerm = $pdo->prepare(
        "INSERT INTO {$terms} (name, slug, term_group) VALUES (:name, :slug, 0)"
    );
    $insertTaxonomy = $pdo->prepare(
        "INSERT INTO {$taxonomy} (term_id, taxonomy, description, parent, count)
         VALUES (:term_id, 'post_tag', '', 0, 0)"
    );
    $insertRelationship = $pdo->prepare(
        "INSERT INTO {$relationships} (object_id, term_taxonomy_id, term_order)
         VALUES (:post_id, :taxonomy_id, 0)"
    );

    $newIds = [];
    foreach ($tagNames as $rawName) {
        $name = trim((string) $rawName);
        if ($name === '') {
            continue;
        }

        $baseSlug = nj_admin_slugify($name);
        if ($baseSlug === '') {
            continue;
        }

        $find->execute([
            'slug' => $baseSlug,
            'name' => $name,
            'preferred_slug' => $baseSlug,
        ]);
        $existing = $find->fetch();

        if ($existing) {
            $taxonomyId = (int) $existing['term_taxonomy_id'];
        } else {
            $slug = $baseSlug;
            $suffix = 2;

            while (true) {
                $slugExists->execute(['slug' => $slug]);
                if (!$slugExists->fetchColumn()) {
                    break;
                }
                $slug = substr($baseSlug, 0, 170) . '-' . $suffix;
                $suffix++;
                if ($suffix > 500) {
                    throw new RuntimeException('unique_tag_slug_exhausted');
                }
            }

            $insertTerm->execute(['name' => $name, 'slug' => $slug]);
            $termId = (int) $pdo->lastInsertId();
            $insertTaxonomy->execute(['term_id' => $termId]);
            $taxonomyId = (int) $pdo->lastInsertId();
        }

        if ($taxonomyId <= 0 || in_array($taxonomyId, $newIds, true)) {
            continue;
        }

        $newIds[] = $taxonomyId;
        $insertRelationship->execute([
            'post_id' => $postId,
            'taxonomy_id' => $taxonomyId,
        ]);
    }

    nj_admin_recount_categories($pdo, array_merge($oldIds, $newIds));
}

function nj_m2m_validate_draft_input(array $body): array
{
    $title = trim((string) ($body['title'] ?? ''));
    $summary = trim((string) ($body['summary'] ?? ''));
    $content = (string) ($body['content'] ?? '');
    $seo = is_array($body['seo'] ?? null) && !array_is_list($body['seo'])
        ? $body['seo']
        : [];
    $categorySlugs = is_array($body['category_slugs'] ?? null)
        ? array_values($body['category_slugs'])
        : [];
    $tags = is_array($body['tags'] ?? null) ? array_values($body['tags']) : [];

    if ($title === '' || strlen($title) > 500 || trim($content) === '') {
        throw new NjApiHttpException(422, 'draft_payload_invalid');
    }
    if (
        strlen($summary) > 5000
        || strlen($content) > 120000
        || count($categorySlugs) > 20
        || count($tags) > 30
    ) {
        throw new NjApiHttpException(422, 'draft_payload_too_large');
    }

    foreach (array_keys($seo) as $key) {
        if (!in_array($key, ['title', 'description', 'focus_keyword', 'slug'], true)) {
            throw new NjApiHttpException(422, 'seo_field_not_allowed');
        }
    }

    if (
        strlen((string) ($seo['title'] ?? '')) > 300
        || strlen((string) ($seo['description'] ?? '')) > 1000
        || strlen((string) ($seo['focus_keyword'] ?? '')) > 200
        || strlen((string) ($seo['slug'] ?? '')) > 220
    ) {
        throw new NjApiHttpException(422, 'seo_payload_too_large');
    }

    foreach ($categorySlugs as $slug) {
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $slug)) {
            throw new NjApiHttpException(422, 'category_slug_invalid');
        }
    }

    $normalizedTags = [];
    foreach ($tags as $tag) {
        $value = trim((string) $tag);
        if ($value === '' || strlen($value) > 100) {
            continue;
        }
        $normalizedTags[strtolower($value)] = $value;
    }

    $cleanTitle = nj_content_clean_text_source($title);
    $cleanContent = nj_content_sanitize_html($content);

    if ($cleanTitle === '' || trim(nj_content_clean_text_source($cleanContent)) === '') {
        throw new NjApiHttpException(422, 'draft_payload_invalid');
    }

    return [
        'title' => $cleanTitle,
        'summary' => nj_content_clean_text_source($summary),
        'content' => $cleanContent,
        'seo' => [
            'title' => nj_content_clean_text_source((string) ($seo['title'] ?? '')),
            'description' => nj_content_clean_text_source((string) ($seo['description'] ?? '')),
            'focus_keyword' => nj_content_clean_text_source((string) ($seo['focus_keyword'] ?? '')),
            'slug' => nj_admin_slugify((string) ($seo['slug'] ?? '')),
        ],
        'category_slugs' => array_values(array_unique(array_map('strtolower', $categorySlugs))),
        'tags' => array_values($normalizedTags),
    ];
}

function nj_m2m_upsert_draft(
    PDO $pdo,
    int $pautaId,
    string $claim,
    string $sourceHash,
    array $body,
    string $requestId
): array {
    $input = nj_m2m_validate_draft_input($body);

    $pdo->beginTransaction();

    try {
        $pauta = nj_m2m_load_pauta($pdo, $pautaId, true);
        nj_m2m_verify_claim($pauta, $claim);

        $currentHash = strtolower(trim((string) ($pauta['source_hash'] ?? '')));
        if (!preg_match('/^[0-9a-f]{64}$/', $currentHash) || !hash_equals($currentHash, strtolower($sourceHash))) {
            throw new NjApiHttpException(409, 'source_hash_mismatch');
        }

        $draftId = (int) ($pauta['draft_post_id'] ?? 0);
        $created = false;

        if ($draftId > 0) {
            $posts = nj_table('posts');
            $check = $pdo->prepare(
                "SELECT ID, post_status
                 FROM {$posts}
                 WHERE ID = :id AND post_type = 'post'
                 LIMIT 1"
            );
            $check->execute(['id' => $draftId]);
            $draft = $check->fetch();

            if (!is_array($draft) || (string) $draft['post_status'] !== 'draft') {
                throw new NjApiHttpException(409, 'draft_not_editable');
            }

            nj_admin_create_revision(
                $pdo,
                $draftId,
                nj_m2m_resolve_author_id($pdo, $pauta),
                'mobi_editorial_upsert'
            );
        } else {
            $draftId = nj_m2m_create_draft($pdo, $pauta, $input['title']);
            $created = true;
        }

        $posts = nj_table('posts');
        $slug = nj_admin_unique_post_slug(
            $pdo,
            $draftId,
            (string) $input['seo']['slug'],
            (string) $input['title']
        );

        $update = $pdo->prepare(
            "UPDATE {$posts}
             SET post_title = :title,
                 post_name = :slug,
                 post_excerpt = :excerpt,
                 post_content = :content,
                 post_modified = NOW(),
                 post_modified_gmt = UTC_TIMESTAMP()
             WHERE ID = :id AND post_type = 'post' AND post_status = 'draft'
             LIMIT 1"
        );
        $update->execute([
            'title' => $input['title'],
            'slug' => $slug,
            'excerpt' => $input['summary'],
            'content' => $input['content'],
            'id' => $draftId,
        ]);

        $primaryCategoryId = nj_m2m_replace_categories(
            $pdo,
            $draftId,
            $input['category_slugs']
        );
        nj_m2m_replace_tags($pdo, $draftId, $input['tags']);

        nj_admin_upsert_postmeta(
            $pdo,
            $draftId,
            '_yoast_wpseo_title',
            (string) $input['seo']['title']
        );
        nj_admin_upsert_postmeta(
            $pdo,
            $draftId,
            '_yoast_wpseo_metadesc',
            (string) $input['seo']['description']
        );
        nj_admin_upsert_postmeta(
            $pdo,
            $draftId,
            '_yoast_wpseo_focuskw',
            (string) $input['seo']['focus_keyword']
        );
        nj_admin_upsert_postmeta(
            $pdo,
            $draftId,
            '_yoast_wpseo_primary_category',
            $primaryCategoryId > 0 ? (string) $primaryCategoryId : ''
        );

        nj_admin_upsert_postmeta($pdo, $draftId, NJ_M2M_META_GENERATED_BY, 'ember');
        nj_admin_upsert_postmeta($pdo, $draftId, NJ_M2M_META_GENERATED_VIA, 'mobi_core');
        nj_admin_upsert_postmeta($pdo, $draftId, NJ_M2M_META_HUMAN_REVIEW, '1');
        nj_admin_upsert_postmeta($pdo, $draftId, NJ_M2M_META_LAST_REQUEST_ID, $requestId);

        nj_admin_upsert_postmeta($pdo, $pautaId, NJ_M2M_PAUTA_DRAFT_ID, (string) $draftId);
        nj_admin_upsert_postmeta($pdo, $pautaId, NJ_M2M_PAUTA_STAGE, 'writing');
        nj_admin_upsert_postmeta($pdo, $pautaId, NJ_M2M_META_STATE, 'drafted');
        nj_admin_upsert_postmeta($pdo, $pautaId, NJ_M2M_META_LAST_REQUEST_ID, $requestId);

        nj_admin_log_post_activity(
            $pdo,
            $draftId,
            nj_m2m_resolve_author_id($pdo, $pauta),
            'mobi_editorial_draft_upserted',
            [
                'pautaId' => $pautaId,
                'requestId' => $requestId,
                'generatedBy' => 'ember',
                'generatedVia' => 'mobi_core',
                'humanReviewRequired' => true,
                'created' => $created,
            ]
        );

        $readBack = $pdo->prepare(
            "SELECT ID, post_title, post_name, post_excerpt, post_content, post_status
             FROM {$posts}
             WHERE ID = :id AND post_type = 'post'
             LIMIT 1"
        );
        $readBack->execute(['id' => $draftId]);
        $persisted = $readBack->fetch();

        if (
            !is_array($persisted)
            || (string) $persisted['post_status'] !== 'draft'
            || (string) $persisted['post_title'] !== (string) $input['title']
            || (string) $persisted['post_excerpt'] !== (string) $input['summary']
            || (string) $persisted['post_content'] !== (string) $input['content']
        ) {
            throw new RuntimeException('draft_upsert_readback_mismatch');
        }

        $freshPauta = nj_m2m_load_pauta($pdo, $pautaId, false);
        $pdo->commit();

        return [
            'pauta_id' => (string) $pautaId,
            'draft_id' => (string) $draftId,
            'created' => $created,
            'reused' => !$created,
            'state_machine' => nj_m2m_state_machine($freshPauta),
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
