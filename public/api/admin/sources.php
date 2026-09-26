<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

const NJ_SOURCE_TYPE = 'nj_source';
const NJ_SOURCE_META_ORGANIZATION = '_nj_source_organization';
const NJ_SOURCE_META_ROLE = '_nj_source_role';
const NJ_SOURCE_META_PHONE = '_nj_source_phone';
const NJ_SOURCE_META_WHATSAPP = '_nj_source_whatsapp';
const NJ_SOURCE_META_EMAIL = '_nj_source_email';
const NJ_SOURCE_META_CITY = '_nj_source_city';
const NJ_SOURCE_META_TOPICS = '_nj_source_topics';
const NJ_SOURCE_META_URL = '_nj_source_url';

function nj_sources_items(PDO $pdo, string $query = ''): array
{
    $posts = nj_table('posts');
    $postmeta = nj_table('postmeta');

    $where = [
        "p.post_type = 'nj_source'",
        "p.post_status = 'private'",
    ];
    $params = [];

    if ($query !== '') {
        $where[] = "(
            p.post_title LIKE :search_title
            OR p.post_excerpt LIKE :search_org
            OR p.post_content LIKE :search_notes
            OR EXISTS (
                SELECT 1
                FROM {$postmeta} search_pm
                WHERE search_pm.post_id = p.ID
                  AND search_pm.meta_key IN (
                      '_nj_source_email',
                      '_nj_source_phone',
                      '_nj_source_whatsapp',
                      '_nj_source_city',
                      '_nj_source_topics',
                      '_nj_source_role'
                  )
                  AND search_pm.meta_value LIKE :search_meta
            )
        )";

        $needle = '%' . $query . '%';
        $params = [
            'search_title' => $needle,
            'search_org' => $needle,
            'search_notes' => $needle,
            'search_meta' => $needle,
        ];
    }

    $whereSql = implode(' AND ', $where);

    $statement = $pdo->prepare(<<<SQL
SELECT
    p.ID AS id,
    p.post_title AS name,
    p.post_content AS notes,
    p.post_date AS created_at,
    p.post_modified AS modified_at,
    COALESCE((SELECT pm.meta_value FROM {$postmeta} pm WHERE pm.post_id=p.ID AND pm.meta_key='_nj_source_organization' ORDER BY pm.meta_id DESC LIMIT 1), '') AS organization,
    COALESCE((SELECT pm.meta_value FROM {$postmeta} pm WHERE pm.post_id=p.ID AND pm.meta_key='_nj_source_role' ORDER BY pm.meta_id DESC LIMIT 1), '') AS source_role,
    COALESCE((SELECT pm.meta_value FROM {$postmeta} pm WHERE pm.post_id=p.ID AND pm.meta_key='_nj_source_phone' ORDER BY pm.meta_id DESC LIMIT 1), '') AS phone,
    COALESCE((SELECT pm.meta_value FROM {$postmeta} pm WHERE pm.post_id=p.ID AND pm.meta_key='_nj_source_whatsapp' ORDER BY pm.meta_id DESC LIMIT 1), '') AS whatsapp,
    COALESCE((SELECT pm.meta_value FROM {$postmeta} pm WHERE pm.post_id=p.ID AND pm.meta_key='_nj_source_email' ORDER BY pm.meta_id DESC LIMIT 1), '') AS email,
    COALESCE((SELECT pm.meta_value FROM {$postmeta} pm WHERE pm.post_id=p.ID AND pm.meta_key='_nj_source_city' ORDER BY pm.meta_id DESC LIMIT 1), '') AS city,
    COALESCE((SELECT pm.meta_value FROM {$postmeta} pm WHERE pm.post_id=p.ID AND pm.meta_key='_nj_source_topics' ORDER BY pm.meta_id DESC LIMIT 1), '') AS topics,
    COALESCE((SELECT pm.meta_value FROM {$postmeta} pm WHERE pm.post_id=p.ID AND pm.meta_key='_nj_source_url' ORDER BY pm.meta_id DESC LIMIT 1), '') AS source_url
FROM {$posts} p
WHERE {$whereSql}
ORDER BY p.post_title ASC, p.ID ASC
LIMIT 300
SQL);
    $statement->execute($params);

    $items = [];
    foreach ($statement->fetchAll() as $row) {
        $topics = array_values(array_filter(array_map(
            'trim',
            preg_split('/[,;\n]+/u', (string) $row['topics']) ?: []
        )));

        $items[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'organization' => (string) $row['organization'],
            'role' => (string) $row['source_role'],
            'phone' => (string) $row['phone'],
            'whatsapp' => (string) $row['whatsapp'],
            'email' => (string) $row['email'],
            'city' => (string) $row['city'],
            'topics' => $topics,
            'url' => (string) $row['source_url'],
            'notes' => (string) $row['notes'],
            'createdAt' => nj_content_iso8601((string) $row['created_at']),
            'modifiedAt' => nj_content_iso8601((string) $row['modified_at']),
        ];
    }

    return $items;
}

nj_admin_run(['GET', 'POST'], static function (string $method): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'edit_posts');

    $pdo = nj_db();

    if ($method === 'GET') {
        $query = trim((string) ($_GET['q'] ?? ''));
        if (function_exists('mb_substr')) {
            $query = mb_substr($query, 0, 120, 'UTF-8');
        } else {
            $query = substr($query, 0, 120);
        }

        return [
            'items' => nj_sources_items($pdo, $query),
            'query' => $query,
        ];
    }

    nj_admin_require_csrf();
    $body = nj_admin_request_body();
    $action = trim((string) ($body['action'] ?? 'save'));
    $sourceId = max(0, (int) ($body['sourceId'] ?? 0));
    $posts = nj_table('posts');

    if (!in_array($action, ['save', 'trash'], true)) {
        throw new NjApiHttpException(422, 'invalid_source_action');
    }

    if ($action === 'trash') {
        if ($sourceId <= 0) {
            throw new NjApiHttpException(422, 'invalid_source_id');
        }

        $delete = $pdo->prepare(
            "UPDATE {$posts}
             SET post_status='trash',
                 post_modified=NOW(),
                 post_modified_gmt=UTC_TIMESTAMP()
             WHERE ID=:id
               AND post_type='nj_source'
             LIMIT 1"
        );
        $delete->execute(['id' => $sourceId]);

        if ($delete->rowCount() < 1) {
            throw new NjApiHttpException(404, 'source_not_found');
        }

        return [
            'items' => nj_sources_items($pdo),
        ];
    }

    $name = trim((string) ($body['name'] ?? ''));
    $organization = trim((string) ($body['organization'] ?? ''));
    $role = trim((string) ($body['role'] ?? ''));
    $phone = trim((string) ($body['phone'] ?? ''));
    $whatsapp = trim((string) ($body['whatsapp'] ?? ''));
    $email = trim((string) ($body['email'] ?? ''));
    $city = trim((string) ($body['city'] ?? ''));
    $topicsInput = is_array($body['topics'] ?? null) ? $body['topics'] : [];
    $url = trim((string) ($body['url'] ?? ''));
    $notes = trim((string) ($body['notes'] ?? ''));

    if ($name === '' || (function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name)) > 250) {
        throw new NjApiHttpException(422, 'invalid_source_name');
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new NjApiHttpException(422, 'invalid_source_email');
    }

    if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
        throw new NjApiHttpException(422, 'invalid_source_url');
    }

    $topics = [];
    foreach ($topicsInput as $topic) {
        $topic = trim((string) $topic);
        if ($topic !== '' && !in_array($topic, $topics, true)) {
            $topics[] = substr($topic, 0, 120);
        }
        if (count($topics) >= 30) {
            break;
        }
    }

    try {
        $pdo->beginTransaction();

        if ($sourceId > 0) {
            $update = $pdo->prepare(
                "UPDATE {$posts}
                 SET post_title=:name,
                     post_excerpt=:organization,
                     post_content=:notes,
                     post_modified=NOW(),
                     post_modified_gmt=UTC_TIMESTAMP()
                 WHERE ID=:id
                   AND post_type='nj_source'
                   AND post_status='private'
                 LIMIT 1"
            );
            $update->execute([
                'name' => $name,
                'organization' => $organization,
                'notes' => $notes,
                'id' => $sourceId,
            ]);

            if ($update->rowCount() < 1) {
                $exists = $pdo->prepare(
                    "SELECT ID FROM {$posts}
                     WHERE ID=:id AND post_type='nj_source' AND post_status='private'
                     LIMIT 1"
                );
                $exists->execute(['id' => $sourceId]);
                if (!$exists->fetchColumn()) {
                    throw new NjApiHttpException(404, 'source_not_found');
                }
            }
        } else {
            $insert = $pdo->prepare(<<<SQL
INSERT INTO {$posts} (
    post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt,
    post_status, comment_status, ping_status, post_password, post_name, to_ping,
    pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent,
    guid, menu_order, post_type, post_mime_type, comment_count
) VALUES (
    :author_id, NOW(), UTC_TIMESTAMP(), :notes, :name, :organization, 'private',
    'closed', 'closed', '', '', '', '', NOW(), UTC_TIMESTAMP(), '', 0, '', 0,
    'nj_source', '', 0
)
SQL);
            $insert->execute([
                'author_id' => (int) $user['id'],
                'notes' => $notes,
                'name' => $name,
                'organization' => $organization,
            ]);
            $sourceId = (int) $pdo->lastInsertId();
        }

        nj_admin_upsert_postmeta($pdo, $sourceId, NJ_SOURCE_META_ORGANIZATION, substr($organization, 0, 300));
        nj_admin_upsert_postmeta($pdo, $sourceId, NJ_SOURCE_META_ROLE, substr($role, 0, 300));
        nj_admin_upsert_postmeta($pdo, $sourceId, NJ_SOURCE_META_PHONE, substr($phone, 0, 100));
        nj_admin_upsert_postmeta($pdo, $sourceId, NJ_SOURCE_META_WHATSAPP, substr($whatsapp, 0, 100));
        nj_admin_upsert_postmeta($pdo, $sourceId, NJ_SOURCE_META_EMAIL, substr($email, 0, 300));
        nj_admin_upsert_postmeta($pdo, $sourceId, NJ_SOURCE_META_CITY, substr($city, 0, 200));
        nj_admin_upsert_postmeta($pdo, $sourceId, NJ_SOURCE_META_TOPICS, implode(', ', $topics));
        nj_admin_upsert_postmeta($pdo, $sourceId, NJ_SOURCE_META_URL, substr($url, 0, 1000));

        $pdo->commit();
    } catch (NjApiHttpException $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    return [
        'items' => nj_sources_items($pdo),
    ];
});
