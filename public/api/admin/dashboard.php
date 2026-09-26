<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

function nj_dashboard_local_datetime(string $value): ?DateTimeImmutable
{
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    try {
        return new DateTimeImmutable($value, new DateTimeZone('America/Sao_Paulo'));
    } catch (Throwable) {
        return null;
    }
}

nj_admin_run(['GET'], static function (): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'edit_posts');

    $pdo = nj_db();

    $posts = nj_table('posts');
    $postmeta = nj_table('postmeta');
    $comments = nj_table('comments');
    $users = nj_table('users');
    $taxonomy = nj_table('term_taxonomy');

    $canEditOthers = in_array('edit_others_posts', $user['capabilities'], true);
    $authorSql = $canEditOthers ? '' : 'AND p.post_author = :current_user_id';
    $authorParams = $canEditOthers ? [] : ['current_user_id' => (int) $user['id']];

    $postSummary = [];
    $statusStatement = $pdo->prepare(
        "SELECT p.post_status, COUNT(*) AS total
         FROM {$posts} p
         WHERE p.post_type = 'post'
         {$authorSql}
         GROUP BY p.post_status"
    );
    $statusStatement->execute($authorParams);

    foreach ($statusStatement->fetchAll() as $row) {
        $postSummary[(string) $row['post_status']] = (int) $row['total'];
    }

    $categoryCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM {$taxonomy} WHERE taxonomy = 'category'"
    )->fetchColumn();

    $userCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM {$users} WHERE user_status = 0"
    )->fetchColumn();

    $mediaCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM {$posts} WHERE post_type = 'attachment'"
    )->fetchColumn();

    $commentCounts = [
        'approved' => 0,
        'pending' => 0,
        'spam' => 0,
    ];

    try {
        $commentRows = $pdo->query(
            "SELECT comment_approved, COUNT(*) AS total
             FROM {$comments}
             GROUP BY comment_approved"
        )->fetchAll();

        foreach ($commentRows as $row) {
            $key = (string) $row['comment_approved'];

            if ($key === '1') {
                $commentCounts['approved'] = (int) $row['total'];
            } elseif ($key === '0') {
                $commentCounts['pending'] = (int) $row['total'];
            } elseif ($key === 'spam') {
                $commentCounts['spam'] = (int) $row['total'];
            }
        }
    } catch (Throwable) {
        // O restante do painel continua disponível sem a contagem de comentários.
    }

    $recentStatement = $pdo->prepare(<<<SQL
SELECT
    p.ID AS id,
    p.post_title AS title,
    p.post_name AS slug,
    p.post_status AS status,
    p.post_date AS published_at,
    p.post_modified AS modified_at,
    COALESCE(u.display_name, u.user_login, '') AS author_name
FROM {$posts} p
LEFT JOIN {$users} u ON u.ID = p.post_author
WHERE
    p.post_type = 'post'
    {$authorSql}
ORDER BY p.post_modified DESC, p.ID DESC
LIMIT 8
SQL);
    $recentStatement->execute($authorParams);

    $recent = [];
    foreach ($recentStatement->fetchAll() as $row) {
        $recent[] = [
            'id' => (int) $row['id'],
            'title' => trim((string) $row['title']) !== ''
                ? (string) $row['title']
                : '(sem título)',
            'slug' => (string) $row['slug'],
            'status' => (string) $row['status'],
            'publishedAt' => nj_content_iso8601((string) $row['published_at']),
            'modifiedAt' => nj_content_iso8601((string) $row['modified_at']),
            'author' => (string) $row['author_name'],
            'publicUrl' => trim((string) $row['slug']) !== ''
                ? '/noticia/' . rawurlencode((string) $row['slug'])
                : null,
        ];
    }

    $workflowSql = <<<SQL
SELECT
    p.ID AS id,
    p.post_title AS title,
    p.post_name AS slug,
    p.post_status AS status,
    p.post_author AS author_id,
    COALESCE(u.display_name, u.user_login, '') AS author_name,
    COALESCE((
        SELECT pm.meta_value
        FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_editorial_stage'
        ORDER BY pm.meta_id DESC
        LIMIT 1
    ), '') AS editorial_stage,
    COALESCE((
        SELECT pm.meta_value
        FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_editorial_priority'
        ORDER BY pm.meta_id DESC
        LIMIT 1
    ), 'normal') AS editorial_priority,
    COALESCE((
        SELECT pm.meta_value
        FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_editorial_deadline'
        ORDER BY pm.meta_id DESC
        LIMIT 1
    ), '') AS editorial_deadline,
    COALESCE((
        SELECT CAST(pm.meta_value AS UNSIGNED)
        FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_editorial_assignee'
        ORDER BY pm.meta_id DESC
        LIMIT 1
    ), 0) AS assignee_id,
    p.post_date AS published_at
FROM {$posts} p
LEFT JOIN {$users} u ON u.ID = p.post_author
WHERE
    p.post_type = 'post'
    AND p.post_status <> 'trash'
    {$authorSql}
ORDER BY p.post_modified DESC, p.ID DESC
LIMIT 250
SQL;

    $workflowStatement = $pdo->prepare($workflowSql);
    $workflowStatement->execute($authorParams);
    $workflowRows = $workflowStatement->fetchAll();

    $assigneeIds = [];
    foreach ($workflowRows as $row) {
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

    $workflowCounts = [
        'idea' => 0,
        'reporting' => 0,
        'writing' => 0,
        'review' => 0,
        'ready' => 0,
        'scheduled' => 0,
        'published' => 0,
    ];

    $timezone = new DateTimeZone('America/Sao_Paulo');
    $now = new DateTimeImmutable('now', $timezone);
    $overdue = [];
    $nextDeadlines = [];
    $reviewQueue = [];
    $readyQueue = [];
    $scheduled = [];

    foreach ($workflowRows as $row) {
        $status = (string) $row['status'];
        $stage = trim((string) $row['editorial_stage']);

        if (!array_key_exists($stage, $workflowCounts)) {
            $stage = match ($status) {
                'publish' => 'published',
                'future' => 'scheduled',
                default => 'writing',
            };
        }

        $workflowCounts[$stage]++;

        $item = [
            'id' => (int) $row['id'],
            'title' => trim((string) $row['title']) !== ''
                ? (string) $row['title']
                : '(sem título)',
            'status' => $status,
            'stage' => $stage,
            'priority' => (string) $row['editorial_priority'],
            'deadline' => (string) $row['editorial_deadline'],
            'assignee' => $assignees[(int) $row['assignee_id']] ?? (string) $row['author_name'],
            'adminUrl' => '/sistema/noticias/' . (int) $row['id'],
        ];

        if ($stage === 'review' && count($reviewQueue) < 6) {
            $reviewQueue[] = $item;
        }

        if ($stage === 'ready' && count($readyQueue) < 6) {
            $readyQueue[] = $item;
        }

        $deadline = nj_dashboard_local_datetime((string) $row['editorial_deadline']);
        if ($deadline !== null && !in_array($status, ['publish', 'future'], true)) {
            if ($deadline < $now) {
                $item['deadlineAt'] = $deadline->format(DATE_ATOM);
                $overdue[] = $item;
            } elseif ($deadline <= $now->modify('+7 days')) {
                $item['deadlineAt'] = $deadline->format(DATE_ATOM);
                $nextDeadlines[] = $item;
            }
        }

        if ($status === 'future') {
            $publication = new DateTimeImmutable((string) $row['published_at'], $timezone);
            if ($publication >= $now && count($scheduled) < 8) {
                $item['scheduledAt'] = $publication->format(DATE_ATOM);
                $scheduled[] = $item;
            }
        }
    }

    usort($overdue, static fn (array $a, array $b): int =>
        strcmp((string) $a['deadlineAt'], (string) $b['deadlineAt'])
    );
    $overdue = array_slice($overdue, 0, 8);

    usort($nextDeadlines, static fn (array $a, array $b): int =>
        strcmp((string) $a['deadlineAt'], (string) $b['deadlineAt'])
    );
    $nextDeadlines = array_slice($nextDeadlines, 0, 8);

    usort($scheduled, static fn (array $a, array $b): int =>
        strcmp((string) $a['scheduledAt'], (string) $b['scheduledAt'])
    );

    $urgentPautas = [];
    if ((string) $user['login'] === NJ_PAUTAS_OWNER_LOGIN) {
        $pautaStatement = $pdo->query(<<<SQL
SELECT
    p.ID AS id,
    p.post_title AS title,
    p.post_modified AS modified_at,
    COALESCE((
        SELECT pm.meta_value
        FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_pauta_priority'
        ORDER BY pm.meta_id DESC
        LIMIT 1
    ), 'normal') AS priority,
    COALESCE((
        SELECT pm.meta_value
        FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_pauta_stage'
        ORDER BY pm.meta_id DESC
        LIMIT 1
    ), 'inbox') AS stage,
    COALESCE((
        SELECT pm.meta_value
        FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_pauta_deadline'
        ORDER BY pm.meta_id DESC
        LIMIT 1
    ), '') AS deadline
FROM {$posts} p
WHERE
    p.post_type = 'nj_pauta'
    AND p.post_status = 'private'
    AND EXISTS (
        SELECT 1
        FROM {$postmeta} priority_pm
        WHERE
            priority_pm.post_id = p.ID
            AND priority_pm.meta_key = '_nj_pauta_priority'
            AND priority_pm.meta_value IN ('urgent', 'high')
    )
ORDER BY
    FIELD(priority, 'urgent', 'high'),
    p.post_modified DESC
LIMIT 8
SQL);

        foreach ($pautaStatement->fetchAll() as $row) {
            $urgentPautas[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'priority' => (string) $row['priority'],
                'stage' => (string) $row['stage'],
                'deadline' => (string) $row['deadline'],
                'adminUrl' => '/sistema/pautas',
            ];
        }
    }

    $agenda = [];
    $eventStatement = $pdo->query(<<<SQL
SELECT
    p.ID AS id,
    p.post_title AS title,
    p.post_content AS note,
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
WHERE
    p.post_type = 'nj_agenda_event'
    AND p.post_status = 'private'
ORDER BY p.ID DESC
LIMIT 100
SQL);

    foreach ($eventStatement->fetchAll() as $row) {
        $start = nj_dashboard_local_datetime((string) $row['event_start']);
        if ($start === null || $start < $now || $start > $now->modify('+14 days')) {
            continue;
        }

        $agenda[] = [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'kind' => (string) $row['event_kind'],
            'start' => $start->format(DATE_ATOM),
            'location' => (string) $row['event_location'],
            'note' => (string) $row['note'],
            'adminUrl' => '/sistema/agenda',
        ];
    }

    usort($agenda, static fn (array $a, array $b): int =>
        strcmp((string) $a['start'], (string) $b['start'])
    );
    $agenda = array_slice($agenda, 0, 8);

    return [
        'user' => $user,
        'summary' => [
            'posts' => [
                'published' => $postSummary['publish'] ?? 0,
                'draft' => $postSummary['draft'] ?? 0,
                'pending' => $postSummary['pending'] ?? 0,
                'future' => $postSummary['future'] ?? 0,
                'private' => $postSummary['private'] ?? 0,
                'trash' => $postSummary['trash'] ?? 0,
                'total' => array_sum($postSummary),
            ],
            'categories' => $categoryCount,
            'users' => $userCount,
            'media' => $mediaCount,
            'comments' => $commentCounts,
            'workflow' => $workflowCounts,
            'overdue' => count($overdue),
            'urgentPautas' => count($urgentPautas),
        ],
        'recentPosts' => $recent,
        'editorial' => [
            'overdue' => $overdue,
            'nextDeadlines' => $nextDeadlines,
            'review' => $reviewQueue,
            'ready' => $readyQueue,
            'scheduled' => $scheduled,
            'urgentPautas' => $urgentPautas,
            'agenda' => $agenda,
        ],
    ];
});
