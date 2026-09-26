<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

const NJ_PAUTA_TYPE = 'nj_pauta';
const NJ_PAUTA_META_STAGE = '_nj_pauta_stage';
const NJ_PAUTA_META_PRIORITY = '_nj_pauta_priority';
const NJ_PAUTA_META_TOPIC = '_nj_pauta_topic';
const NJ_PAUTA_META_SOURCE_NAME = '_nj_pauta_source_name';
const NJ_PAUTA_META_SOURCE_URL = '_nj_pauta_source_url';
const NJ_PAUTA_META_DEADLINE = '_nj_pauta_deadline';
const NJ_PAUTA_META_ASSIGNEE = '_nj_pauta_assignee';
const NJ_PAUTA_META_DRAFT_ID = '_nj_pauta_draft_post_id';

function nj_pautas_sources(): array
{
    return [
        ['name' => 'Jornal Tradição', 'category' => 'Pelotas', 'feedUrl' => 'https://www.jornaltradicao.com.br/pelotas/feed/', 'kind' => 'jornalística', 'priority' => 90],
        ['name' => 'Google News: Pelotas', 'category' => 'Pelotas', 'feedUrl' => 'https://news.google.com/rss/search?q=Pelotas&hl=pt-BR&gl=BR&ceid=BR:pt-419', 'kind' => 'agregador', 'priority' => 70],
        ['name' => 'Tecnoblog', 'category' => 'Tecnologia BR', 'feedUrl' => 'https://tecnoblog.net/feed/', 'kind' => 'jornalística', 'priority' => 85],
        ['name' => 'TechCrunch', 'category' => 'Tecnologia', 'feedUrl' => 'https://techcrunch.com/feed/', 'kind' => 'jornalística', 'priority' => 80],
        ['name' => 'The Verge', 'category' => 'Tecnologia', 'feedUrl' => 'https://www.theverge.com/rss/index.xml', 'kind' => 'jornalística', 'priority' => 80],
        ['name' => 'Ars Technica', 'category' => 'Tecnologia/Ciência', 'feedUrl' => 'https://feeds.arstechnica.com/arstechnica/index', 'kind' => 'jornalística', 'priority' => 85],
        ['name' => 'Hacker News', 'category' => 'Radar Tech', 'feedUrl' => 'https://news.ycombinator.com/rss', 'kind' => 'radar', 'priority' => 55],
        ['name' => 'OpenAI News', 'category' => 'IA', 'feedUrl' => 'https://openai.com/news/rss.xml', 'kind' => 'fonte primária', 'priority' => 100],
        ['name' => 'Google AI', 'category' => 'IA', 'feedUrl' => 'https://blog.google/technology/ai/rss/', 'kind' => 'fonte primária', 'priority' => 100],
        ['name' => 'Google DeepMind', 'category' => 'IA', 'feedUrl' => 'https://deepmind.google/blog/rss.xml', 'kind' => 'fonte primária', 'priority' => 100],
        ['name' => 'Hugging Face', 'category' => 'IA/Open Source', 'feedUrl' => 'https://huggingface.co/blog/feed.xml', 'kind' => 'fonte primária', 'priority' => 90],
        ['name' => 'Mistral AI', 'category' => 'IA', 'feedUrl' => 'https://mistral.ai/news/rss', 'kind' => 'fonte primária', 'priority' => 90],
        ['name' => 'NASA Science', 'category' => 'Universo', 'feedUrl' => 'https://science.nasa.gov/feed/', 'kind' => 'fonte primária', 'priority' => 100],
        ['name' => 'ESA Space Science', 'category' => 'Universo', 'feedUrl' => 'https://www.esa.int/rssfeed/Our_Activities/Space_Science', 'kind' => 'fonte primária', 'priority' => 95],
        ['name' => 'Space.com', 'category' => 'Universo', 'feedUrl' => 'https://www.space.com/feeds/all', 'kind' => 'jornalística', 'priority' => 80],
        ['name' => 'ScienceDaily: Science', 'category' => 'Ciência', 'feedUrl' => 'https://www.sciencedaily.com/rss/top/science.xml', 'kind' => 'jornalística', 'priority' => 80],
        ['name' => 'ScienceDaily: Technology', 'category' => 'Tecnologia Científica', 'feedUrl' => 'https://www.sciencedaily.com/rss/top/technology.xml', 'kind' => 'jornalística', 'priority' => 80],
        ['name' => 'ScienceDaily: Strange & Offbeat', 'category' => 'Curiosidades', 'feedUrl' => 'https://www.sciencedaily.com/rss/strange_offbeat.xml', 'kind' => 'jornalística', 'priority' => 75],
        ['name' => 'arXiv cs.AI', 'category' => 'Pesquisa IA', 'feedUrl' => 'https://rss.arxiv.org/rss/cs.AI', 'kind' => 'radar', 'priority' => 65],
    ];
}

function nj_pautas_datetime(mixed $value): string
{
    $raw = trim((string) $value);

    if ($raw === '') {
        return '';
    }

    try {
        $timezone = new DateTimeZone('America/Sao_Paulo');
        return (new DateTimeImmutable($raw, $timezone))->format('Y-m-d\TH:i');
    } catch (Throwable) {
        throw new NjApiHttpException(422, 'invalid_pauta_deadline');
    }
}

function nj_pautas_assignees(PDO $pdo): array
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
        ];
    }

    return $result;
}

function nj_pautas_items(PDO $pdo): array
{
    $posts = nj_table('posts');
    $postmeta = nj_table('postmeta');
    $users = nj_table('users');

    $rows = $pdo->query(<<<SQL
SELECT
    p.ID AS id,
    p.post_title AS title,
    p.post_content AS notes,
    p.post_author AS author_id,
    p.post_date AS created_at,
    p.post_modified AS modified_at,
    COALESCE(u.display_name, u.user_login, '') AS author_name,
    COALESCE((
        SELECT pm.meta_value FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_pauta_stage'
        ORDER BY pm.meta_id DESC LIMIT 1
    ), 'inbox') AS stage,
    COALESCE((
        SELECT pm.meta_value FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_pauta_priority'
        ORDER BY pm.meta_id DESC LIMIT 1
    ), 'normal') AS priority,
    COALESCE((
        SELECT pm.meta_value FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_pauta_topic'
        ORDER BY pm.meta_id DESC LIMIT 1
    ), '') AS topic,
    COALESCE((
        SELECT pm.meta_value FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_pauta_source_name'
        ORDER BY pm.meta_id DESC LIMIT 1
    ), '') AS source_name,
    COALESCE((
        SELECT pm.meta_value FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_pauta_source_url'
        ORDER BY pm.meta_id DESC LIMIT 1
    ), '') AS source_url,
    COALESCE((
        SELECT pm.meta_value FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_pauta_deadline'
        ORDER BY pm.meta_id DESC LIMIT 1
    ), '') AS deadline,
    COALESCE((
        SELECT CAST(pm.meta_value AS UNSIGNED) FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_pauta_assignee'
        ORDER BY pm.meta_id DESC LIMIT 1
    ), 0) AS assignee_id,
    COALESCE((
        SELECT CAST(pm.meta_value AS UNSIGNED) FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_pauta_draft_post_id'
        ORDER BY pm.meta_id DESC LIMIT 1
    ), 0) AS draft_post_id
FROM {$posts} p
LEFT JOIN {$users} u ON u.ID = p.post_author
WHERE
    p.post_type = 'nj_pauta'
    AND p.post_status = 'private'
ORDER BY
    FIELD(priority, 'urgent', 'high', 'normal', 'low'),
    p.post_modified DESC
LIMIT 300
SQL)->fetchAll();

    $assigneeIds = [];
    foreach ($rows as $row) {
        if ((int) $row['assignee_id'] > 0) {
            $assigneeIds[(int) $row['assignee_id']] = true;
        }
    }

    $assignees = [];
    if ($assigneeIds !== []) {
        $placeholders = implode(',', array_fill(0, count($assigneeIds), '?'));
        $statement = $pdo->prepare(
            "SELECT ID, user_login, display_name
             FROM {$users}
             WHERE ID IN ({$placeholders})"
        );
        $statement->execute(array_keys($assigneeIds));

        foreach ($statement->fetchAll() as $row) {
            $assignees[(int) $row['ID']] = trim((string) $row['display_name']) !== ''
                ? (string) $row['display_name']
                : (string) $row['user_login'];
        }
    }

    $items = [];
    foreach ($rows as $row) {
        $items[] = [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'notes' => (string) $row['notes'],
            'stage' => (string) $row['stage'],
            'priority' => (string) $row['priority'],
            'topic' => (string) $row['topic'],
            'sourceName' => (string) $row['source_name'],
            'sourceUrl' => (string) $row['source_url'],
            'deadline' => (string) $row['deadline'],
            'assigneeId' => (int) $row['assignee_id'],
            'assignee' => $assignees[(int) $row['assignee_id']] ?? (string) $row['author_name'],
            'createdAt' => nj_content_iso8601((string) $row['created_at']),
            'modifiedAt' => nj_content_iso8601((string) $row['modified_at']),
            'draftPostId' => (int) $row['draft_post_id'],
            'draftAdminUrl' => (int) $row['draft_post_id'] > 0
                ? '/sistema/noticias/' . (int) $row['draft_post_id']
                : null,
        ];
    }

    return $items;
}

nj_admin_run(['GET', 'POST'], static function (string $method): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_pautas_owner($user);

    $pdo = nj_db();

    if ($method === 'GET') {
        return [
            'owner' => ['login' => NJ_PAUTAS_OWNER_LOGIN, 'userId' => $user['id']],
            'pipeline' => ['Entrada', 'Selecionada', 'Apuração', 'Pronta', 'Em redação'],
            'sources' => nj_pautas_sources(),
            'items' => nj_pautas_items($pdo),
            'assignees' => nj_pautas_assignees($pdo),
        ];
    }

    nj_admin_require_csrf();
    $body = nj_admin_request_body();
    $action = trim((string) ($body['action'] ?? 'save'));
    $posts = nj_table('posts');

    if (!in_array($action, ['save', 'trash', 'to_draft'], true)) {
        throw new NjApiHttpException(422, 'invalid_pauta_action');
    }

    $pautaId = max(0, (int) ($body['pautaId'] ?? 0));

    if ($action === 'trash') {
        if ($pautaId <= 0) {
            throw new NjApiHttpException(422, 'invalid_pauta_id');
        }

        $delete = $pdo->prepare(
            "UPDATE {$posts}
             SET post_status = 'trash',
                 post_modified = NOW(),
                 post_modified_gmt = UTC_TIMESTAMP()
             WHERE ID = :id
               AND post_type = 'nj_pauta'
             LIMIT 1"
        );
        $delete->execute(['id' => $pautaId]);

        if ($delete->rowCount() < 1) {
            throw new NjApiHttpException(404, 'pauta_not_found');
        }

        return [
            'items' => nj_pautas_items($pdo),
            'draft' => null,
        ];
    }

    if ($action === 'to_draft') {
        if ($pautaId <= 0) {
            throw new NjApiHttpException(422, 'invalid_pauta_id');
        }

        $postmeta = nj_table('postmeta');
        $pautaStatement = $pdo->prepare(<<<SQL
SELECT
    p.ID,
    p.post_title,
    p.post_content,
    COALESCE((SELECT pm.meta_value FROM {$postmeta} pm WHERE pm.post_id=p.ID AND pm.meta_key='_nj_pauta_priority' ORDER BY pm.meta_id DESC LIMIT 1), 'normal') AS priority,
    COALESCE((SELECT pm.meta_value FROM {$postmeta} pm WHERE pm.post_id=p.ID AND pm.meta_key='_nj_pauta_deadline' ORDER BY pm.meta_id DESC LIMIT 1), '') AS deadline,
    COALESCE((SELECT CAST(pm.meta_value AS UNSIGNED) FROM {$postmeta} pm WHERE pm.post_id=p.ID AND pm.meta_key='_nj_pauta_assignee' ORDER BY pm.meta_id DESC LIMIT 1), 0) AS assignee_id,
    COALESCE((SELECT pm.meta_value FROM {$postmeta} pm WHERE pm.post_id=p.ID AND pm.meta_key='_nj_pauta_source_name' ORDER BY pm.meta_id DESC LIMIT 1), '') AS source_name,
    COALESCE((SELECT pm.meta_value FROM {$postmeta} pm WHERE pm.post_id=p.ID AND pm.meta_key='_nj_pauta_source_url' ORDER BY pm.meta_id DESC LIMIT 1), '') AS source_url,
    COALESCE((SELECT CAST(pm.meta_value AS UNSIGNED) FROM {$postmeta} pm WHERE pm.post_id=p.ID AND pm.meta_key='_nj_pauta_draft_post_id' ORDER BY pm.meta_id DESC LIMIT 1), 0) AS draft_post_id
FROM {$posts} p
WHERE p.ID=:id AND p.post_type='nj_pauta' AND p.post_status='private'
LIMIT 1
SQL);
        $pautaStatement->execute(['id' => $pautaId]);
        $pauta = $pautaStatement->fetch();

        if (!$pauta) {
            throw new NjApiHttpException(404, 'pauta_not_found');
        }

        if ((int) $pauta['draft_post_id'] > 0) {
            return [
                'items' => nj_pautas_items($pdo),
                'draft' => [
                    'id' => (int) $pauta['draft_post_id'],
                    'adminUrl' => '/sistema/noticias/' . (int) $pauta['draft_post_id'],
                ],
            ];
        }

        $authorId = (int) $pauta['assignee_id'] > 0
            ? (int) $pauta['assignee_id']
            : (int) $user['id'];

        try {
            $pdo->beginTransaction();

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
                'title' => (string) $pauta['post_title'],
            ]);
            $draftId = (int) $pdo->lastInsertId();

            if ($draftId <= 0) {
                throw new RuntimeException('draft_insert_missing_id');
            }

            nj_admin_upsert_postmeta($pdo, $draftId, '_nj_editorial_stage', 'writing');
            nj_admin_upsert_postmeta($pdo, $draftId, '_nj_editorial_priority', (string) $pauta['priority']);
            nj_admin_upsert_postmeta($pdo, $draftId, '_nj_editorial_deadline', (string) $pauta['deadline']);
            nj_admin_upsert_postmeta($pdo, $draftId, '_nj_editorial_assignee', $authorId > 0 ? (string) $authorId : '');
            nj_admin_upsert_postmeta($pdo, $draftId, '_nj_reporting_notes', (string) $pauta['post_content']);

            $source = [];
            if ((string) $pauta['source_name'] !== '' || (string) $pauta['source_url'] !== '') {
                $source[] = [
                    'name' => '',
                    'organization' => (string) $pauta['source_name'],
                    'contact' => '',
                    'url' => (string) $pauta['source_url'],
                    'note' => 'Origem da pauta',
                ];
            }

            nj_admin_upsert_postmeta(
                $pdo,
                $draftId,
                '_nj_reporting_sources',
                json_encode($source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]'
            );

            nj_admin_upsert_postmeta($pdo, $pautaId, NJ_PAUTA_META_STAGE, 'writing');
            nj_admin_upsert_postmeta($pdo, $pautaId, NJ_PAUTA_META_DRAFT_ID, (string) $draftId);

            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        return [
            'items' => nj_pautas_items($pdo),
            'draft' => [
                'id' => $draftId,
                'adminUrl' => '/sistema/noticias/' . $draftId,
            ],
        ];
    }

    $title = trim((string) ($body['title'] ?? ''));
    $notes = trim((string) ($body['notes'] ?? ''));
    $stage = trim((string) ($body['stage'] ?? 'inbox'));
    $priority = trim((string) ($body['priority'] ?? 'normal'));
    $topic = trim((string) ($body['topic'] ?? ''));
    $sourceName = trim((string) ($body['sourceName'] ?? ''));
    $sourceUrl = trim((string) ($body['sourceUrl'] ?? ''));
    $deadline = nj_pautas_datetime($body['deadline'] ?? '');
    $assigneeId = max(0, (int) ($body['assigneeId'] ?? 0));

    if ($title === '' || strlen($title) > 500) {
        throw new NjApiHttpException(422, 'invalid_pauta_title');
    }

    if (!in_array($stage, ['inbox', 'selected', 'research', 'ready', 'writing'], true)) {
        throw new NjApiHttpException(422, 'invalid_pauta_stage');
    }

    if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
        throw new NjApiHttpException(422, 'invalid_pauta_priority');
    }

    if ($sourceUrl !== '' && !filter_var($sourceUrl, FILTER_VALIDATE_URL)) {
        throw new NjApiHttpException(422, 'invalid_pauta_source_url');
    }

    $validAssigneeIds = array_column(nj_pautas_assignees($pdo), 'id');
    if ($assigneeId > 0 && !in_array($assigneeId, $validAssigneeIds, true)) {
        throw new NjApiHttpException(422, 'invalid_pauta_assignee');
    }

    try {
        $pdo->beginTransaction();

        if ($pautaId > 0) {
            $update = $pdo->prepare(
                "UPDATE {$posts}
                 SET post_title=:title,
                     post_content=:notes,
                     post_modified=NOW(),
                     post_modified_gmt=UTC_TIMESTAMP()
                 WHERE ID=:id AND post_type='nj_pauta' AND post_status='private'
                 LIMIT 1"
            );
            $update->execute([
                'title' => $title,
                'notes' => $notes,
                'id' => $pautaId,
            ]);

            if ($update->rowCount() < 1) {
                $exists = $pdo->prepare(
                    "SELECT ID FROM {$posts}
                     WHERE ID=:id AND post_type='nj_pauta' AND post_status='private'
                     LIMIT 1"
                );
                $exists->execute(['id' => $pautaId]);
                if (!$exists->fetchColumn()) {
                    throw new NjApiHttpException(404, 'pauta_not_found');
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
    :author_id, NOW(), UTC_TIMESTAMP(), :notes, :title, '', 'private', 'closed',
    'closed', '', '', '', '', NOW(), UTC_TIMESTAMP(), '', 0, '', 0,
    'nj_pauta', '', 0
)
SQL);
            $insert->execute([
                'author_id' => (int) $user['id'],
                'notes' => $notes,
                'title' => $title,
            ]);
            $pautaId = (int) $pdo->lastInsertId();
        }

        nj_admin_upsert_postmeta($pdo, $pautaId, NJ_PAUTA_META_STAGE, $stage);
        nj_admin_upsert_postmeta($pdo, $pautaId, NJ_PAUTA_META_PRIORITY, $priority);
        nj_admin_upsert_postmeta($pdo, $pautaId, NJ_PAUTA_META_TOPIC, substr($topic, 0, 250));
        nj_admin_upsert_postmeta($pdo, $pautaId, NJ_PAUTA_META_SOURCE_NAME, substr($sourceName, 0, 250));
        nj_admin_upsert_postmeta($pdo, $pautaId, NJ_PAUTA_META_SOURCE_URL, substr($sourceUrl, 0, 1000));
        nj_admin_upsert_postmeta($pdo, $pautaId, NJ_PAUTA_META_DEADLINE, $deadline);
        nj_admin_upsert_postmeta($pdo, $pautaId, NJ_PAUTA_META_ASSIGNEE, $assigneeId > 0 ? (string) $assigneeId : '');

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
        'items' => nj_pautas_items($pdo),
        'draft' => null,
    ];
});
