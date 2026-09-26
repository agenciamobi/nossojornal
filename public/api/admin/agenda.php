<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

const NJ_AGENDA_EVENT_TYPE = 'nj_agenda_event';
const NJ_AGENDA_META_START = '_nj_event_start';
const NJ_AGENDA_META_END = '_nj_event_end';
const NJ_AGENDA_META_LOCATION = '_nj_event_location';
const NJ_AGENDA_META_KIND = '_nj_event_kind';

function nj_agenda_datetime(mixed $value, string $errorCode): string
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

function nj_agenda_items(PDO $pdo, array $user): array
{
    $posts = nj_table('posts');
    $postmeta = nj_table('postmeta');
    $users = nj_table('users');

    $canEditOthers = in_array('edit_others_posts', $user['capabilities'], true);
    $authorFilter = $canEditOthers ? '' : 'AND p.post_author = :current_user_id';

    $sql = <<<SQL
SELECT
    p.ID AS id,
    p.post_author AS author_id,
    p.post_title AS title,
    p.post_name AS slug,
    p.post_status AS status,
    p.post_date AS published_at,
    p.post_modified AS modified_at,
    COALESCE(u.display_name, u.user_login, '') AS author_name,
    COALESCE((
        SELECT pm.meta_value
        FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_editorial_deadline'
        ORDER BY pm.meta_id DESC
        LIMIT 1
    ), '') AS deadline,
    COALESCE((
        SELECT pm.meta_value
        FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_editorial_priority'
        ORDER BY pm.meta_id DESC
        LIMIT 1
    ), 'normal') AS priority,
    COALESCE((
        SELECT pm.meta_value
        FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_editorial_stage'
        ORDER BY pm.meta_id DESC
        LIMIT 1
    ), '') AS stage,
    COALESCE((
        SELECT CAST(pm.meta_value AS UNSIGNED)
        FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_editorial_assignee'
        ORDER BY pm.meta_id DESC
        LIMIT 1
    ), 0) AS assignee_id
FROM {$posts} p
LEFT JOIN {$users} u ON u.ID = p.post_author
WHERE
    p.post_type = 'post'
    AND p.post_status <> 'trash'
    AND (
        p.post_status = 'future'
        OR EXISTS (
            SELECT 1
            FROM {$postmeta} deadline_pm
            WHERE
                deadline_pm.post_id = p.ID
                AND deadline_pm.meta_key = '_nj_editorial_deadline'
                AND deadline_pm.meta_value <> ''
        )
    )
    {$authorFilter}
ORDER BY p.post_date ASC, p.ID ASC
LIMIT 250
SQL;

    $statement = $pdo->prepare($sql);
    $params = [];
    if (!$canEditOthers) {
        $params['current_user_id'] = (int) $user['id'];
    }
    $statement->execute($params);

    $assigneeIds = [];
    $postRows = $statement->fetchAll();

    foreach ($postRows as $row) {
        $assigneeId = (int) $row['assignee_id'];
        if ($assigneeId > 0) {
            $assigneeIds[$assigneeId] = true;
        }
    }

    $assignees = [];
    if ($assigneeIds !== []) {
        $placeholders = implode(',', array_fill(0, count($assigneeIds), '?'));
        $assigneeStatement = $pdo->prepare(
            "SELECT ID, user_login, display_name
             FROM {$users}
             WHERE ID IN ({$placeholders})"
        );
        $assigneeStatement->execute(array_keys($assigneeIds));

        foreach ($assigneeStatement->fetchAll() as $row) {
            $assignees[(int) $row['ID']] = trim((string) $row['display_name']) !== ''
                ? (string) $row['display_name']
                : (string) $row['user_login'];
        }
    }

    $items = [];

    foreach ($postRows as $row) {
        $deadline = trim((string) $row['deadline']);
        $status = (string) $row['status'];
        $stage = trim((string) $row['stage']);

        if ($stage === '') {
            $stage = match ($status) {
                'publish' => 'published',
                'future' => 'scheduled',
                default => 'writing',
            };
        }

        if ($deadline !== '') {
            $items[] = [
                'id' => 'deadline-' . (int) $row['id'],
                'kind' => 'deadline',
                'postId' => (int) $row['id'],
                'title' => (string) $row['title'],
                'start' => $deadline,
                'end' => null,
                'location' => null,
                'priority' => (string) $row['priority'],
                'stage' => $stage,
                'status' => $status,
                'assignee' => $assignees[(int) $row['assignee_id']] ?? (string) $row['author_name'],
                'adminUrl' => '/sistema/noticias/' . (int) $row['id'],
                'publicUrl' => trim((string) $row['slug']) !== ''
                    ? '/noticia/' . rawurlencode((string) $row['slug'])
                    : null,
            ];
        }

        if ($status === 'future') {
            $publishedAt = nj_content_iso8601((string) $row['published_at']);
            $items[] = [
                'id' => 'publication-' . (int) $row['id'],
                'kind' => 'publication',
                'postId' => (int) $row['id'],
                'title' => (string) $row['title'],
                'start' => $publishedAt,
                'end' => null,
                'location' => null,
                'priority' => (string) $row['priority'],
                'stage' => 'scheduled',
                'status' => $status,
                'assignee' => $assignees[(int) $row['assignee_id']] ?? (string) $row['author_name'],
                'adminUrl' => '/sistema/noticias/' . (int) $row['id'],
                'publicUrl' => null,
            ];
        }
    }

    $eventRows = $pdo->query(<<<SQL
SELECT
    p.ID AS id,
    p.post_title AS title,
    p.post_content AS note,
    p.post_author AS author_id,
    COALESCE(u.display_name, u.user_login, '') AS author_name,
    COALESCE((
        SELECT pm.meta_value
        FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_event_start'
        ORDER BY pm.meta_id DESC
        LIMIT 1
    ), '') AS event_start,
    COALESCE((
        SELECT pm.meta_value
        FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_event_end'
        ORDER BY pm.meta_id DESC
        LIMIT 1
    ), '') AS event_end,
    COALESCE((
        SELECT pm.meta_value
        FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_event_location'
        ORDER BY pm.meta_id DESC
        LIMIT 1
    ), '') AS event_location,
    COALESCE((
        SELECT pm.meta_value
        FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_event_kind'
        ORDER BY pm.meta_id DESC
        LIMIT 1
    ), 'coverage') AS event_kind
FROM {$posts} p
LEFT JOIN {$users} u ON u.ID = p.post_author
WHERE
    p.post_type = 'nj_agenda_event'
    AND p.post_status = 'private'
ORDER BY event_start ASC, p.ID ASC
LIMIT 250
SQL)->fetchAll();

    foreach ($eventRows as $row) {
        $start = trim((string) $row['event_start']);
        if ($start === '') {
            continue;
        }

        $items[] = [
            'id' => 'event-' . (int) $row['id'],
            'kind' => 'event',
            'eventId' => (int) $row['id'],
            'eventKind' => (string) $row['event_kind'],
            'title' => (string) $row['title'],
            'note' => (string) $row['note'],
            'start' => $start,
            'end' => trim((string) $row['event_end']) !== '' ? (string) $row['event_end'] : null,
            'location' => trim((string) $row['event_location']) !== '' ? (string) $row['event_location'] : null,
            'priority' => 'normal',
            'stage' => 'event',
            'status' => 'private',
            'assignee' => (string) $row['author_name'],
            'adminUrl' => null,
            'publicUrl' => null,
        ];
    }

    usort($items, static function (array $a, array $b): int {
        return strcmp((string) $a['start'], (string) $b['start']);
    });

    return $items;
}

nj_admin_run(['GET', 'POST'], static function (string $method): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'edit_posts');

    $pdo = nj_db();

    if ($method === 'GET') {
        return [
            'items' => nj_agenda_items($pdo, $user),
        ];
    }

    nj_admin_require_csrf();
    $body = nj_admin_request_body();
    $action = trim((string) ($body['action'] ?? 'save'));

    if (!in_array($action, ['save', 'delete'], true)) {
        throw new NjApiHttpException(422, 'invalid_agenda_action');
    }

    $eventId = max(0, (int) ($body['eventId'] ?? 0));
    $posts = nj_table('posts');

    if ($action === 'delete') {
        if ($eventId <= 0) {
            throw new NjApiHttpException(422, 'invalid_event_id');
        }

        $delete = $pdo->prepare(
            "UPDATE {$posts}
             SET post_status = 'trash',
                 post_modified = NOW(),
                 post_modified_gmt = UTC_TIMESTAMP()
             WHERE ID = :id
               AND post_type = 'nj_agenda_event'
             LIMIT 1"
        );
        $delete->execute(['id' => $eventId]);

        if ($delete->rowCount() < 1) {
            throw new NjApiHttpException(404, 'event_not_found');
        }

        return [
            'items' => nj_agenda_items($pdo, $user),
        ];
    }

    $title = trim((string) ($body['title'] ?? ''));
    $start = nj_agenda_datetime($body['start'] ?? '', 'invalid_event_start');
    $end = nj_agenda_datetime($body['end'] ?? '', 'invalid_event_end');
    $location = trim((string) ($body['location'] ?? ''));
    $note = trim((string) ($body['note'] ?? ''));
    $kind = trim((string) ($body['eventKind'] ?? 'coverage'));

    if ($title === '' || strlen($title) > 500) {
        throw new NjApiHttpException(422, 'invalid_event_title');
    }

    if ($start === '') {
        throw new NjApiHttpException(422, 'event_start_required');
    }

    if (!in_array($kind, ['coverage', 'interview', 'meeting', 'deadline', 'event'], true)) {
        throw new NjApiHttpException(422, 'invalid_event_kind');
    }

    if ($end !== '' && strcmp($end, $start) < 0) {
        throw new NjApiHttpException(422, 'event_end_before_start');
    }

    try {
        $pdo->beginTransaction();

        if ($eventId > 0) {
            $update = $pdo->prepare(
                "UPDATE {$posts}
                 SET post_title = :title,
                     post_content = :note,
                     post_modified = NOW(),
                     post_modified_gmt = UTC_TIMESTAMP()
                 WHERE ID = :id
                   AND post_type = 'nj_agenda_event'
                   AND post_status <> 'trash'
                 LIMIT 1"
            );
            $update->execute([
                'title' => $title,
                'note' => $note,
                'id' => $eventId,
            ]);

            if ($update->rowCount() < 1) {
                $exists = $pdo->prepare(
                    "SELECT ID FROM {$posts}
                     WHERE ID = :id
                       AND post_type = 'nj_agenda_event'
                       AND post_status <> 'trash'
                     LIMIT 1"
                );
                $exists->execute(['id' => $eventId]);
                if (!$exists->fetchColumn()) {
                    throw new NjApiHttpException(404, 'event_not_found');
                }
            }
        } else {
            $insert = $pdo->prepare(<<<SQL
INSERT INTO {$posts} (
    post_author,
    post_date,
    post_date_gmt,
    post_content,
    post_title,
    post_excerpt,
    post_status,
    comment_status,
    ping_status,
    post_password,
    post_name,
    to_ping,
    pinged,
    post_modified,
    post_modified_gmt,
    post_content_filtered,
    post_parent,
    guid,
    menu_order,
    post_type,
    post_mime_type,
    comment_count
) VALUES (
    :author_id,
    NOW(),
    UTC_TIMESTAMP(),
    :note,
    :title,
    '',
    'private',
    'closed',
    'closed',
    '',
    '',
    '',
    '',
    NOW(),
    UTC_TIMESTAMP(),
    '',
    0,
    '',
    0,
    'nj_agenda_event',
    '',
    0
)
SQL);
            $insert->execute([
                'author_id' => (int) $user['id'],
                'note' => $note,
                'title' => $title,
            ]);
            $eventId = (int) $pdo->lastInsertId();
        }

        nj_admin_upsert_postmeta($pdo, $eventId, NJ_AGENDA_META_START, $start);
        nj_admin_upsert_postmeta($pdo, $eventId, NJ_AGENDA_META_END, $end);
        nj_admin_upsert_postmeta($pdo, $eventId, NJ_AGENDA_META_LOCATION, substr($location, 0, 500));
        nj_admin_upsert_postmeta($pdo, $eventId, NJ_AGENDA_META_KIND, $kind);

        $pdo->commit();
    } catch (NjApiHttpException $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
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

    return [
        'items' => nj_agenda_items($pdo, $user),
    ];
});
