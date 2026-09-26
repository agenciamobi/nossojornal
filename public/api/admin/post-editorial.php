<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

const NJ_EDITORIAL_META_STAGE = '_nj_editorial_stage';
const NJ_EDITORIAL_META_PRIORITY = '_nj_editorial_priority';
const NJ_EDITORIAL_META_DEADLINE = '_nj_editorial_deadline';
const NJ_EDITORIAL_META_ASSIGNEE = '_nj_editorial_assignee';
const NJ_EDITORIAL_META_NOTES = '_nj_reporting_notes';
const NJ_EDITORIAL_META_SOURCES = '_nj_reporting_sources';
const NJ_EDITORIAL_META_CHECKLIST = '_nj_editorial_checklist';
const NJ_EDITORIAL_META_HOME_SLOT = '_nj_home_slot';
const NJ_EDITORIAL_META_HOME_RANK = '_nj_home_rank';
const NJ_EDITORIAL_META_HOME_UNTIL = '_nj_home_until';
const NJ_EDITORIAL_META_HOME_HEADLINE = '_nj_home_headline';
const NJ_EDITORIAL_META_ARTICLE_TYPE = '_nj_article_type';
const NJ_EDITORIAL_META_KICKER = '_nj_kicker';
const NJ_EDITORIAL_META_STANDFIRST = '_nj_standfirst';
const NJ_EDITORIAL_META_DATELINE = '_nj_dateline';
const NJ_EDITORIAL_META_COAUTHORS = '_nj_coauthors';
const NJ_EDITORIAL_META_IMAGE_CREDIT = '_nj_image_credit';
const NJ_EDITORIAL_META_IMAGE_CAPTION = '_nj_image_caption';
const NJ_EDITORIAL_META_ORIGINAL_SOURCE_URL = '_nj_original_source_url';
const NJ_EDITORIAL_META_CANONICAL_URL = '_nj_canonical_url';
const NJ_EDITORIAL_META_SOCIAL_TITLE = '_nj_social_title';
const NJ_EDITORIAL_META_SOCIAL_DESCRIPTION = '_nj_social_description';

function nj_editorial_meta_map(PDO $pdo, int $postId): array
{
    $postmeta = nj_table('postmeta');
    $keys = [
        NJ_EDITORIAL_META_STAGE,
        NJ_EDITORIAL_META_PRIORITY,
        NJ_EDITORIAL_META_DEADLINE,
        NJ_EDITORIAL_META_ASSIGNEE,
        NJ_EDITORIAL_META_NOTES,
        NJ_EDITORIAL_META_SOURCES,
        NJ_EDITORIAL_META_CHECKLIST,
        NJ_EDITORIAL_META_HOME_SLOT,
        NJ_EDITORIAL_META_HOME_RANK,
        NJ_EDITORIAL_META_HOME_UNTIL,
        NJ_EDITORIAL_META_HOME_HEADLINE,
        NJ_EDITORIAL_META_ARTICLE_TYPE,
        NJ_EDITORIAL_META_KICKER,
        NJ_EDITORIAL_META_STANDFIRST,
        NJ_EDITORIAL_META_DATELINE,
        NJ_EDITORIAL_META_COAUTHORS,
        NJ_EDITORIAL_META_IMAGE_CREDIT,
        NJ_EDITORIAL_META_IMAGE_CAPTION,
        NJ_EDITORIAL_META_ORIGINAL_SOURCE_URL,
        NJ_EDITORIAL_META_CANONICAL_URL,
        NJ_EDITORIAL_META_SOCIAL_TITLE,
        NJ_EDITORIAL_META_SOCIAL_DESCRIPTION,
    ];
    $placeholders = implode(',', array_fill(0, count($keys), '?'));

    $statement = $pdo->prepare(
        "SELECT meta_key, meta_value, meta_id
         FROM {$postmeta}
         WHERE post_id = ? AND meta_key IN ({$placeholders})
         ORDER BY meta_id DESC"
    );
    $statement->execute(array_merge([$postId], $keys));

    $meta = [];
    foreach ($statement->fetchAll() as $row) {
        $key = (string) $row['meta_key'];
        if (!array_key_exists($key, $meta)) {
            $meta[$key] = (string) $row['meta_value'];
        }
    }

    return $meta;
}

function nj_editorial_json_array(string $value): array
{
    if ($value === '') {
        return [];
    }

    $decoded = json_decode($value, true);

    return is_array($decoded) ? array_values($decoded) : [];
}

function nj_editorial_json_object(string $value): array
{
    if ($value === '') {
        return [];
    }

    $decoded = json_decode($value, true);

    return is_array($decoded) ? $decoded : [];
}

function nj_editorial_assignees(PDO $pdo): array
{
    $users = nj_table('users');
    $rows = $pdo->query(
        "SELECT ID, user_login, user_email, display_name
         FROM {$users}
         ORDER BY display_name ASC, user_login ASC"
    )->fetchAll();

    $result = [];

    foreach ($rows as $row) {
        $id = (int) $row['ID'];
        $access = nj_admin_user_roles_and_capabilities($pdo, $id);

        if (!in_array('edit_posts', $access['capabilities'], true)) {
            continue;
        }

        $result[] = [
            'id' => $id,
            'login' => (string) $row['user_login'],
            'name' => trim((string) $row['display_name']) !== ''
                ? (string) $row['display_name']
                : (string) $row['user_login'],
            'email' => (string) $row['user_email'],
        ];
    }

    return $result;
}

function nj_editorial_require_post_access(PDO $pdo, array $user, int $postId): array
{
    $posts = nj_table('posts');

    $statement = $pdo->prepare(
        "SELECT ID, post_author, post_status, post_title, post_name, post_excerpt
         FROM {$posts}
         WHERE ID = :id AND post_type = 'post'
         LIMIT 1"
    );
    $statement->execute(['id' => $postId]);
    $post = $statement->fetch();

    if (!$post) {
        throw new NjApiHttpException(404, 'post_not_found');
    }

    $ownsPost = (int) $post['post_author'] === (int) $user['id'];

    if (!$ownsPost && !in_array('edit_others_posts', $user['capabilities'], true)) {
        throw new NjApiHttpException(403, 'insufficient_permissions');
    }

    if (
        in_array((string) $post['post_status'], ['publish', 'future', 'private'], true)
        && !in_array('edit_published_posts', $user['capabilities'], true)
    ) {
        throw new NjApiHttpException(403, 'insufficient_permissions');
    }

    return $post;
}

function nj_editorial_datetime_or_empty(mixed $value, string $errorCode): string
{
    $raw = trim((string) $value);

    if ($raw === '') {
        return '';
    }

    try {
        $timezone = new DateTimeZone('America/Sao_Paulo');
        $date = new DateTimeImmutable($raw, $timezone);

        return $date->format('Y-m-d\TH:i');
    } catch (Throwable) {
        throw new NjApiHttpException(422, $errorCode);
    }
}

function nj_editorial_payload(PDO $pdo, array $post, array $meta): array
{
    $stageAllowed = ['idea', 'reporting', 'writing', 'review', 'ready', 'scheduled', 'published'];
    $priorityAllowed = ['low', 'normal', 'high', 'urgent'];
    $homeSlots = ['automatic', 'hero', 'featured'];
    $articleTypes = ['news', 'analysis', 'opinion', 'interview', 'service', 'live'];

    $stage = (string) ($meta[NJ_EDITORIAL_META_STAGE] ?? '');
    if (!in_array($stage, $stageAllowed, true)) {
        $stage = match ((string) $post['post_status']) {
            'publish' => 'published',
            'future' => 'scheduled',
            default => 'writing',
        };
    }

    $priority = (string) ($meta[NJ_EDITORIAL_META_PRIORITY] ?? 'normal');
    if (!in_array($priority, $priorityAllowed, true)) {
        $priority = 'normal';
    }

    $homeSlot = (string) ($meta[NJ_EDITORIAL_META_HOME_SLOT] ?? 'automatic');
    if (!in_array($homeSlot, $homeSlots, true)) {
        $homeSlot = 'automatic';
    }

    $checklist = nj_editorial_json_object((string) ($meta[NJ_EDITORIAL_META_CHECKLIST] ?? ''));
    $checklistKeys = [
        'headline',
        'facts',
        'names',
        'dates',
        'sources',
        'imageRights',
        'altText',
        'links',
        'category',
        'seo',
        'review',
    ];
    $normalizedChecklist = [];

    foreach ($checklistKeys as $key) {
        $normalizedChecklist[$key] = ($checklist[$key] ?? false) === true;
    }

    $postmeta = nj_table('postmeta');
    $relationships = nj_table('term_relationships');
    $taxonomy = nj_table('term_taxonomy');

    $hasImage = false;
    $imageStatement = $pdo->prepare(
        "SELECT meta_value
         FROM {$postmeta}
         WHERE post_id = :post_id AND meta_key = '_thumbnail_id'
         ORDER BY meta_id DESC
         LIMIT 1"
    );
    $imageStatement->execute(['post_id' => (int) $post['ID']]);
    $hasImage = (int) ($imageStatement->fetchColumn() ?: 0) > 0;

    $categoryStatement = $pdo->prepare(
        "SELECT COUNT(*)
         FROM {$relationships} tr
         INNER JOIN {$taxonomy} tt
           ON tt.term_taxonomy_id = tr.term_taxonomy_id
          AND tt.taxonomy = 'category'
         WHERE tr.object_id = :post_id"
    );
    $categoryStatement->execute(['post_id' => (int) $post['ID']]);
    $hasCategory = (int) $categoryStatement->fetchColumn() > 0;

    $seoStatement = $pdo->prepare(
        "SELECT meta_key, meta_value
         FROM {$postmeta}
         WHERE post_id = :post_id
           AND meta_key IN ('_yoast_wpseo_title', '_yoast_wpseo_metadesc')"
    );
    $seoStatement->execute(['post_id' => (int) $post['ID']]);
    $seo = [];
    foreach ($seoStatement->fetchAll() as $row) {
        $seo[(string) $row['meta_key']] = trim((string) $row['meta_value']);
    }

    $articleType = (string) ($meta[NJ_EDITORIAL_META_ARTICLE_TYPE] ?? 'news');
    if (!in_array($articleType, $articleTypes, true)) {
        $articleType = 'news';
    }

    $coauthorIds = array_values(array_unique(array_filter(array_map(
        'intval',
        nj_editorial_json_array((string) ($meta[NJ_EDITORIAL_META_COAUTHORS] ?? ''))
    ), static fn (int $id): bool => $id > 0)));

    return [
        'stage' => $stage,
        'priority' => $priority,
        'deadline' => (string) ($meta[NJ_EDITORIAL_META_DEADLINE] ?? ''),
        'assigneeId' => (int) ($meta[NJ_EDITORIAL_META_ASSIGNEE] ?? 0),
        'notes' => (string) ($meta[NJ_EDITORIAL_META_NOTES] ?? ''),
        'sources' => nj_editorial_json_array((string) ($meta[NJ_EDITORIAL_META_SOURCES] ?? '')),
        'checklist' => $normalizedChecklist,
        'automaticChecks' => [
            'title' => trim((string) $post['post_title']) !== '',
            'excerpt' => trim((string) $post['post_excerpt']) !== '',
            'featuredImage' => $hasImage,
            'category' => $hasCategory,
            'seo' => (($seo['_yoast_wpseo_title'] ?? '') !== '' || ($seo['_yoast_wpseo_metadesc'] ?? '') !== ''),
        ],
        'home' => [
            'slot' => $homeSlot,
            'rank' => max(0, min(99, (int) ($meta[NJ_EDITORIAL_META_HOME_RANK] ?? 0))),
            'until' => (string) ($meta[NJ_EDITORIAL_META_HOME_UNTIL] ?? ''),
            'headline' => (string) ($meta[NJ_EDITORIAL_META_HOME_HEADLINE] ?? ''),
        ],
        'identity' => [
            'articleType' => $articleType,
            'kicker' => (string) ($meta[NJ_EDITORIAL_META_KICKER] ?? ''),
            'standfirst' => (string) ($meta[NJ_EDITORIAL_META_STANDFIRST] ?? ''),
            'dateline' => (string) ($meta[NJ_EDITORIAL_META_DATELINE] ?? ''),
            'coauthorIds' => $coauthorIds,
            'imageCredit' => (string) ($meta[NJ_EDITORIAL_META_IMAGE_CREDIT] ?? ''),
            'imageCaption' => (string) ($meta[NJ_EDITORIAL_META_IMAGE_CAPTION] ?? ''),
        ],
        'distribution' => [
            'originalSourceUrl' => (string) ($meta[NJ_EDITORIAL_META_ORIGINAL_SOURCE_URL] ?? ''),
            'canonicalUrl' => (string) ($meta[NJ_EDITORIAL_META_CANONICAL_URL] ?? ''),
            'socialTitle' => (string) ($meta[NJ_EDITORIAL_META_SOCIAL_TITLE] ?? ''),
            'socialDescription' => (string) ($meta[NJ_EDITORIAL_META_SOCIAL_DESCRIPTION] ?? ''),
        ],
    ];
}

nj_admin_run(['GET', 'POST'], static function (string $method): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'edit_posts');

    $pdo = nj_db();

    if ($method === 'GET') {
        $postId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if (!is_int($postId) || $postId <= 0) {
            throw new NjApiHttpException(422, 'invalid_post_id');
        }

        $post = nj_editorial_require_post_access($pdo, $user, $postId);
        $meta = nj_editorial_meta_map($pdo, $postId);

        return [
            'editorial' => nj_editorial_payload($pdo, $post, $meta),
            'assignees' => nj_editorial_assignees($pdo),
        ];
    }

    nj_admin_require_csrf();

    $body = nj_admin_request_body();
    $postId = filter_var(
        $body['postId'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if (!is_int($postId) || $postId <= 0) {
        throw new NjApiHttpException(422, 'invalid_post_id');
    }

    $post = nj_editorial_require_post_access($pdo, $user, $postId);

    $stage = trim((string) ($body['stage'] ?? 'writing'));
    if (!in_array($stage, ['idea', 'reporting', 'writing', 'review', 'ready', 'scheduled', 'published'], true)) {
        throw new NjApiHttpException(422, 'invalid_editorial_stage');
    }

    $priority = trim((string) ($body['priority'] ?? 'normal'));
    if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
        throw new NjApiHttpException(422, 'invalid_editorial_priority');
    }

    $deadline = nj_editorial_datetime_or_empty($body['deadline'] ?? '', 'invalid_editorial_deadline');
    $homeUntil = nj_editorial_datetime_or_empty($body['homeUntil'] ?? '', 'invalid_home_until');

    $assigneeId = max(0, (int) ($body['assigneeId'] ?? 0));
    if ($assigneeId > 0) {
        $validAssignee = false;
        foreach (nj_editorial_assignees($pdo) as $assignee) {
            if ((int) $assignee['id'] === $assigneeId) {
                $validAssignee = true;
                break;
            }
        }

        if (!$validAssignee) {
            throw new NjApiHttpException(422, 'invalid_editorial_assignee');
        }
    }

    $notes = (string) ($body['notes'] ?? '');
    if ((function_exists('mb_strlen') ? mb_strlen($notes, 'UTF-8') : strlen($notes)) > 100000) {
        throw new NjApiHttpException(422, 'editorial_notes_too_large');
    }

    $sourcesInput = is_array($body['sources'] ?? null) ? $body['sources'] : [];
    if (count($sourcesInput) > 30) {
        throw new NjApiHttpException(422, 'too_many_editorial_sources');
    }

    $sources = [];
    foreach ($sourcesInput as $source) {
        if (!is_array($source)) {
            continue;
        }

        $name = trim((string) ($source['name'] ?? ''));
        $organization = trim((string) ($source['organization'] ?? ''));
        $contact = trim((string) ($source['contact'] ?? ''));
        $url = trim((string) ($source['url'] ?? ''));
        $note = trim((string) ($source['note'] ?? ''));

        if ($name === '' && $organization === '' && $contact === '' && $url === '' && $note === '') {
            continue;
        }

        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new NjApiHttpException(422, 'invalid_editorial_source_url');
        }

        $sources[] = [
            'name' => substr($name, 0, 250),
            'organization' => substr($organization, 0, 250),
            'contact' => substr($contact, 0, 250),
            'url' => substr($url, 0, 1000),
            'note' => substr($note, 0, 2000),
        ];
    }

    $checklistInput = is_array($body['checklist'] ?? null) ? $body['checklist'] : [];
    $checklistKeys = [
        'headline',
        'facts',
        'names',
        'dates',
        'sources',
        'imageRights',
        'altText',
        'links',
        'category',
        'seo',
        'review',
    ];
    $checklist = [];

    foreach ($checklistKeys as $key) {
        $checklist[$key] = ($checklistInput[$key] ?? false) === true;
    }

    $homeSlot = trim((string) ($body['homeSlot'] ?? 'automatic'));
    if (!in_array($homeSlot, ['automatic', 'hero', 'featured'], true)) {
        throw new NjApiHttpException(422, 'invalid_home_slot');
    }

    $homeRank = max(0, min(99, (int) ($body['homeRank'] ?? 0)));
    $homeHeadline = trim((string) ($body['homeHeadline'] ?? ''));

    $articleType = trim((string) ($body['articleType'] ?? 'news'));
    if (!in_array($articleType, ['news', 'analysis', 'opinion', 'interview', 'service', 'live'], true)) {
        throw new NjApiHttpException(422, 'invalid_article_type');
    }

    $kicker = trim((string) ($body['kicker'] ?? ''));
    $standfirst = trim((string) ($body['standfirst'] ?? ''));
    $dateline = trim((string) ($body['dateline'] ?? ''));
    $imageCredit = trim((string) ($body['imageCredit'] ?? ''));
    $imageCaption = trim((string) ($body['imageCaption'] ?? ''));
    $originalSourceUrl = trim((string) ($body['originalSourceUrl'] ?? ''));
    $canonicalUrl = trim((string) ($body['canonicalUrl'] ?? ''));
    $socialTitle = trim((string) ($body['socialTitle'] ?? ''));
    $socialDescription = trim((string) ($body['socialDescription'] ?? ''));

    $textLimits = [
        'kicker' => [$kicker, 160],
        'standfirst' => [$standfirst, 1000],
        'dateline' => [$dateline, 160],
        'imageCredit' => [$imageCredit, 300],
        'imageCaption' => [$imageCaption, 1200],
        'socialTitle' => [$socialTitle, 300],
        'socialDescription' => [$socialDescription, 1000],
    ];

    foreach ($textLimits as $field => [$value, $limit]) {
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length > $limit) {
            throw new NjApiHttpException(422, 'editorial_' . $field . '_too_large');
        }
    }

    foreach ([
        'original_source_url' => $originalSourceUrl,
        'canonical_url' => $canonicalUrl,
    ] as $field => $url) {
        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new NjApiHttpException(422, 'invalid_' . $field);
        }
    }

    $coauthorIdsInput = is_array($body['coauthorIds'] ?? null) ? $body['coauthorIds'] : [];
    $coauthorIds = array_values(array_unique(array_filter(
        array_map('intval', $coauthorIdsInput),
        static fn (int $id): bool => $id > 0
    )));

    if (count($coauthorIds) > 10) {
        throw new NjApiHttpException(422, 'too_many_coauthors');
    }

    if ($coauthorIds !== []) {
        $allowedAssignees = array_column(nj_editorial_assignees($pdo), 'id');
        foreach ($coauthorIds as $coauthorId) {
            if (!in_array($coauthorId, $allowedAssignees, true)) {
                throw new NjApiHttpException(422, 'invalid_coauthor');
            }
        }
    }

    if ((function_exists('mb_strlen') ? mb_strlen($homeHeadline, 'UTF-8') : strlen($homeHeadline)) > 280) {
        throw new NjApiHttpException(422, 'home_headline_too_large');
    }

    if (
        ($homeSlot !== 'automatic' || $homeHeadline !== '')
        && !in_array('publish_posts', $user['capabilities'], true)
    ) {
        throw new NjApiHttpException(403, 'insufficient_permissions');
    }

    if (
        ($homeSlot !== 'automatic' || $homeHeadline !== '')
        && !in_array('edit_others_posts', $user['capabilities'], true)
    ) {
        throw new NjApiHttpException(403, 'insufficient_permissions');
    }

    try {
        $pdo->beginTransaction();

        nj_admin_upsert_postmeta($pdo, $postId, NJ_EDITORIAL_META_STAGE, $stage);
        nj_admin_upsert_postmeta($pdo, $postId, NJ_EDITORIAL_META_PRIORITY, $priority);
        nj_admin_upsert_postmeta($pdo, $postId, NJ_EDITORIAL_META_DEADLINE, $deadline);
        nj_admin_upsert_postmeta($pdo, $postId, NJ_EDITORIAL_META_ASSIGNEE, $assigneeId > 0 ? (string) $assigneeId : '');
        nj_admin_upsert_postmeta($pdo, $postId, NJ_EDITORIAL_META_NOTES, $notes);
        nj_admin_upsert_postmeta(
            $pdo,
            $postId,
            NJ_EDITORIAL_META_SOURCES,
            json_encode($sources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]'
        );
        nj_admin_upsert_postmeta(
            $pdo,
            $postId,
            NJ_EDITORIAL_META_CHECKLIST,
            json_encode($checklist, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'
        );
        nj_admin_upsert_postmeta($pdo, $postId, NJ_EDITORIAL_META_HOME_SLOT, $homeSlot);
        nj_admin_upsert_postmeta($pdo, $postId, NJ_EDITORIAL_META_HOME_RANK, (string) $homeRank);
        nj_admin_upsert_postmeta($pdo, $postId, NJ_EDITORIAL_META_HOME_UNTIL, $homeUntil);
        nj_admin_upsert_postmeta($pdo, $postId, NJ_EDITORIAL_META_HOME_HEADLINE, $homeHeadline);

        nj_admin_upsert_postmeta($pdo, $postId, NJ_EDITORIAL_META_ARTICLE_TYPE, $articleType);
        nj_admin_upsert_postmeta($pdo, $postId, NJ_EDITORIAL_META_KICKER, $kicker);
        nj_admin_upsert_postmeta($pdo, $postId, NJ_EDITORIAL_META_STANDFIRST, $standfirst);
        nj_admin_upsert_postmeta($pdo, $postId, NJ_EDITORIAL_META_DATELINE, $dateline);
        nj_admin_upsert_postmeta(
            $pdo,
            $postId,
            NJ_EDITORIAL_META_COAUTHORS,
            json_encode($coauthorIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]'
        );
        nj_admin_upsert_postmeta($pdo, $postId, NJ_EDITORIAL_META_IMAGE_CREDIT, $imageCredit);
        nj_admin_upsert_postmeta($pdo, $postId, NJ_EDITORIAL_META_IMAGE_CAPTION, $imageCaption);
        nj_admin_upsert_postmeta($pdo, $postId, NJ_EDITORIAL_META_ORIGINAL_SOURCE_URL, $originalSourceUrl);
        nj_admin_upsert_postmeta($pdo, $postId, NJ_EDITORIAL_META_CANONICAL_URL, $canonicalUrl);
        nj_admin_upsert_postmeta($pdo, $postId, NJ_EDITORIAL_META_SOCIAL_TITLE, $socialTitle);
        nj_admin_upsert_postmeta($pdo, $postId, NJ_EDITORIAL_META_SOCIAL_DESCRIPTION, $socialDescription);

        nj_admin_log_post_activity(
            $pdo,
            $postId,
            (int) $user['id'],
            'editorial_metadata_saved',
            [
                'articleType' => $articleType,
                'coauthorCount' => count($coauthorIds),
                'hasCanonical' => $canonicalUrl !== '',
                'hasOriginalSource' => $originalSourceUrl !== '',
            ]
        );

        $posts = nj_table('posts');
        $touch = $pdo->prepare(
            "UPDATE {$posts}
             SET post_modified = NOW(), post_modified_gmt = UTC_TIMESTAMP()
             WHERE ID = :id
             LIMIT 1"
        );
        $touch->execute(['id' => $postId]);

        $pdo->commit();
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ((string) $error->getCode() === '42000') {
            throw new NjApiHttpException(409, 'database_write_unavailable');
        }

        throw $error;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }

    $meta = nj_editorial_meta_map($pdo, $postId);

    return [
        'editorial' => nj_editorial_payload($pdo, $post, $meta),
        'assignees' => nj_editorial_assignees($pdo),
    ];
});
