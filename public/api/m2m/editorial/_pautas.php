<?php
declare(strict_types=1);

require_once __DIR__ . '/_m2m.php';

const NJ_M2M_META_STATE = '_nj_mobi_state';
const NJ_M2M_META_CLAIM_HASH = '_nj_mobi_claim_hash';
const NJ_M2M_META_CLAIMED_AT = '_nj_mobi_claimed_at';
const NJ_M2M_META_CLAIM_EXPIRES_AT = '_nj_mobi_claim_expires_at';
const NJ_M2M_META_PROCESSED_AT = '_nj_mobi_processed_at';
const NJ_M2M_META_LAST_REQUEST_ID = '_nj_mobi_last_request_id';

const NJ_M2M_PAUTA_STAGE = '_nj_pauta_stage';
const NJ_M2M_PAUTA_PRIORITY = '_nj_pauta_priority';
const NJ_M2M_PAUTA_TOPIC = '_nj_pauta_topic';
const NJ_M2M_PAUTA_SOURCE_NAME = '_nj_pauta_source_name';
const NJ_M2M_PAUTA_SOURCE_URL = '_nj_pauta_source_url';
const NJ_M2M_PAUTA_FEED_URL = '_nj_pauta_feed_url';
const NJ_M2M_PAUTA_EXTERNAL_ID = '_nj_pauta_external_id';
const NJ_M2M_PAUTA_SOURCE_PUBLISHED_AT = '_nj_pauta_source_published_at';
const NJ_M2M_PAUTA_CAPTURED_AT = '_nj_pauta_captured_at';
const NJ_M2M_PAUTA_SOURCE_HASH = '_nj_pauta_source_hash';
const NJ_M2M_PAUTA_DEADLINE = '_nj_pauta_deadline';
const NJ_M2M_PAUTA_ASSIGNEE = '_nj_pauta_assignee';
const NJ_M2M_PAUTA_DRAFT_ID = '_nj_pauta_draft_post_id';

function nj_m2m_pauta_id(mixed $value): int
{
    $raw = trim((string) $value);
    if (!preg_match('/^[1-9][0-9]{0,18}$/', $raw)) {
        throw new NjApiHttpException(422, 'pauta_id_invalid');
    }

    return (int) $raw;
}

function nj_m2m_meta_expr(string $postmeta, string $key, string $alias, bool $numeric = false): string
{
    $escaped = str_replace("'", "''", $key);
    $value = $numeric ? 'CAST(pm.meta_value AS UNSIGNED)' : 'pm.meta_value';
    $fallback = $numeric ? '0' : "''";

    return "COALESCE((
        SELECT {$value}
        FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '{$escaped}'
        ORDER BY pm.meta_id DESC
        LIMIT 1
    ), {$fallback}) AS {$alias}";
}

function nj_m2m_pauta_select_sql(): string
{
    $posts = nj_table('posts');
    $postmeta = nj_table('postmeta');

    $fields = [
        'p.ID AS id',
        'p.post_title AS title',
        'p.post_content AS notes',
        'p.post_date AS created_at',
        'p.post_modified AS modified_at',
        nj_m2m_meta_expr($postmeta, NJ_M2M_PAUTA_STAGE, 'stage'),
        nj_m2m_meta_expr($postmeta, NJ_M2M_PAUTA_PRIORITY, 'priority'),
        nj_m2m_meta_expr($postmeta, NJ_M2M_PAUTA_TOPIC, 'topic'),
        nj_m2m_meta_expr($postmeta, NJ_M2M_PAUTA_SOURCE_NAME, 'source_name'),
        nj_m2m_meta_expr($postmeta, NJ_M2M_PAUTA_SOURCE_URL, 'source_url'),
        nj_m2m_meta_expr($postmeta, NJ_M2M_PAUTA_FEED_URL, 'feed_url'),
        nj_m2m_meta_expr($postmeta, NJ_M2M_PAUTA_EXTERNAL_ID, 'external_id'),
        nj_m2m_meta_expr($postmeta, NJ_M2M_PAUTA_SOURCE_PUBLISHED_AT, 'source_published_at'),
        nj_m2m_meta_expr($postmeta, NJ_M2M_PAUTA_CAPTURED_AT, 'captured_at'),
        nj_m2m_meta_expr($postmeta, NJ_M2M_PAUTA_SOURCE_HASH, 'source_hash'),
        nj_m2m_meta_expr($postmeta, NJ_M2M_PAUTA_DEADLINE, 'deadline'),
        nj_m2m_meta_expr($postmeta, NJ_M2M_PAUTA_ASSIGNEE, 'assignee_id', true),
        nj_m2m_meta_expr($postmeta, NJ_M2M_PAUTA_DRAFT_ID, 'draft_post_id', true),
        nj_m2m_meta_expr($postmeta, NJ_M2M_META_STATE, 'mobi_state'),
        nj_m2m_meta_expr($postmeta, NJ_M2M_META_CLAIM_HASH, 'claim_hash'),
        nj_m2m_meta_expr($postmeta, NJ_M2M_META_CLAIMED_AT, 'claimed_at'),
        nj_m2m_meta_expr($postmeta, NJ_M2M_META_CLAIM_EXPIRES_AT, 'claim_expires_at'),
        nj_m2m_meta_expr($postmeta, NJ_M2M_META_PROCESSED_AT, 'processed_at'),
    ];

    return 'SELECT ' . implode(",\n", $fields) . " FROM {$posts} p";
}

function nj_m2m_load_pauta(PDO $pdo, int $pautaId, bool $forUpdate = false): array
{
    $sql = nj_m2m_pauta_select_sql()
        . " WHERE p.ID = :id AND p.post_type = 'nj_pauta' AND p.post_status = 'private'"
        . ' LIMIT 1'
        . ($forUpdate ? ' FOR UPDATE' : '');

    $statement = $pdo->prepare($sql);
    $statement->execute(['id' => $pautaId]);
    $row = $statement->fetch();

    if (!is_array($row)) {
        throw new NjApiHttpException(404, 'pauta_not_found');
    }

    return $row;
}

function nj_m2m_claim_active(array $row): bool
{
    $claimHash = trim((string) ($row['claim_hash'] ?? ''));
    $expiresAt = trim((string) ($row['claim_expires_at'] ?? ''));

    if ($claimHash === '' || $expiresAt === '') {
        return false;
    }

    $expires = strtotime($expiresAt);

    return $expires !== false && $expires > time();
}

function nj_m2m_state_machine(array $row): array
{
    $storedState = trim((string) ($row['mobi_state'] ?? ''));
    $draftId = (int) ($row['draft_post_id'] ?? 0);
    $sourceHash = trim((string) ($row['source_hash'] ?? ''));
    $claimActive = nj_m2m_claim_active($row);

    if (in_array($storedState, ['resolved', 'completed'], true)) {
        return [
            'state' => $storedState,
            'can_claim' => false,
            'can_resolve' => false,
            'can_upsert_draft' => false,
            'terminal' => true,
        ];
    }

    if ($claimActive) {
        return [
            'state' => $draftId > 0 ? 'drafted' : 'claimed',
            'can_claim' => false,
            'can_resolve' => true,
            'can_upsert_draft' => preg_match('/^[0-9a-f]{64}$/i', $sourceHash) === 1,
            'terminal' => false,
        ];
    }

    if ($draftId > 0) {
        return [
            'state' => 'drafted',
            'can_claim' => false,
            'can_resolve' => false,
            'can_upsert_draft' => false,
            'terminal' => true,
        ];
    }

    return [
        'state' => 'pending',
        'can_claim' => true,
        'can_resolve' => false,
        'can_upsert_draft' => false,
        'terminal' => false,
    ];
}

function nj_m2m_pauta_projection(array $row): array
{
    $draftId = (int) ($row['draft_post_id'] ?? 0);

    return [
        'id' => (string) (int) $row['id'],
        'title' => substr((string) $row['title'], 0, 500),
        'notes' => substr((string) $row['notes'], 0, 6000),
        'stage' => trim((string) $row['stage']) !== '' ? (string) $row['stage'] : 'inbox',
        'priority' => trim((string) $row['priority']) !== '' ? (string) $row['priority'] : 'normal',
        'topic' => substr((string) $row['topic'], 0, 180),
        'source' => [
            'name' => substr((string) $row['source_name'], 0, 250),
            'url' => substr((string) $row['source_url'], 0, 1200),
            'feed_url' => substr((string) $row['feed_url'], 0, 1200),
            'published_at' => substr((string) $row['source_published_at'], 0, 64),
        ],
        'external_id' => substr((string) $row['external_id'], 0, 512),
        'source_hash' => preg_match('/^[0-9a-f]{64}$/i', (string) $row['source_hash'])
            ? strtolower((string) $row['source_hash'])
            : '',
        'timestamps' => [
            'captured_at' => substr((string) $row['captured_at'], 0, 64),
            'created_at' => nj_content_iso8601((string) $row['created_at']),
            'modified_at' => nj_content_iso8601((string) $row['modified_at']),
        ],
        'draft' => [
            'exists' => $draftId > 0,
            'id' => $draftId > 0 ? (string) $draftId : null,
        ],
        'state_machine' => nj_m2m_state_machine($row),
    ];
}

function nj_m2m_cursor_encode(string $date, int $id): string
{
    return rtrim(strtr(base64_encode($date . '|' . $id), '+/', '-_'), '=');
}

function nj_m2m_cursor_decode(string $cursor): ?array
{
    $cursor = trim($cursor);
    if ($cursor === '') {
        return null;
    }

    if (!preg_match('/^[A-Za-z0-9_-]{4,256}$/', $cursor)) {
        throw new NjApiHttpException(422, 'cursor_invalid');
    }

    $padding = strlen($cursor) % 4;
    if ($padding !== 0) {
        $cursor .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
    if (!is_string($decoded)
        || !preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|([1-9]\d*)$/', $decoded, $match)
    ) {
        throw new NjApiHttpException(422, 'cursor_invalid');
    }

    return ['date' => $match[1], 'id' => (int) $match[2]];
}

function nj_m2m_list_pautas(PDO $pdo, int $limit, string $cursor): array
{
    $limit = max(1, min(20, $limit));
    $cursorData = nj_m2m_cursor_decode($cursor);
    $scanLimit = min(100, max($limit * 5, $limit + 1));
    $sql = nj_m2m_pauta_select_sql()
        . " WHERE p.post_type = 'nj_pauta' AND p.post_status = 'private'";
    $params = [];

    if (is_array($cursorData)) {
        $sql .= " AND (p.post_date < :cursor_date OR (p.post_date = :cursor_date AND p.ID < :cursor_id))";
        $params['cursor_date'] = $cursorData['date'];
        $params['cursor_id'] = $cursorData['id'];
    }

    $sql .= ' ORDER BY p.post_date DESC, p.ID DESC LIMIT ' . (int) ($scanLimit + 1);
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll();

    $rawHasMore = count($rows) > $scanLimit;
    if ($rawHasMore) {
        $rows = array_slice($rows, 0, $scanLimit);
    }

    $items = [];
    $lastScanned = null;
    $hasMore = false;
    $rowCount = count($rows);

    foreach ($rows as $index => $row) {
        $lastScanned = $row;
        $projection = nj_m2m_pauta_projection($row);
        $machine = $projection['state_machine'] ?? [];

        if (($machine['can_claim'] ?? false) !== true) {
            continue;
        }

        $items[] = $projection;

        if (count($items) >= $limit) {
            $hasMore = $rawHasMore || $index < ($rowCount - 1);
            break;
        }
    }

    if (count($items) < $limit) {
        $hasMore = $rawHasMore;
    }

    $nextCursor = null;
    if ($hasMore && is_array($lastScanned)) {
        $nextCursor = nj_m2m_cursor_encode(
            (string) $lastScanned['created_at'],
            (int) $lastScanned['id']
        );
    }

    return [
        'items' => $items,
        'next_cursor' => $nextCursor,
    ];
}

function nj_m2m_claim_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function nj_m2m_verify_claim(array $row, string $claim): void
{
    if (!preg_match('/^[A-Za-z0-9._:~+\/-]{8,256}$/', $claim)) {
        throw new NjApiHttpException(422, 'claim_invalid');
    }

    if (!nj_m2m_claim_active($row)) {
        throw new NjApiHttpException(409, 'claim_expired');
    }

    $stored = trim((string) ($row['claim_hash'] ?? ''));
    $provided = hash('sha256', $claim);

    if ($stored === '' || !hash_equals($stored, $provided)) {
        throw new NjApiHttpException(409, 'claim_invalid');
    }
}

function nj_m2m_claim_pauta(PDO $pdo, int $pautaId, string $requestId): array
{
    $pdo->beginTransaction();

    try {
        $row = nj_m2m_load_pauta($pdo, $pautaId, true);
        $state = nj_m2m_state_machine($row);

        if (($state['terminal'] ?? false) === true || (int) ($row['draft_post_id'] ?? 0) > 0) {
            throw new NjApiHttpException(409, 'pauta_not_claimable');
        }

        if (nj_m2m_claim_active($row)) {
            throw new NjApiHttpException(409, 'pauta_already_claimed');
        }

        $claim = nj_m2m_claim_token();
        $claimedAt = gmdate('c');
        $expiresAt = gmdate('c', time() + 1800);

        nj_admin_upsert_postmeta($pdo, $pautaId, NJ_M2M_META_STATE, 'claimed');
        nj_admin_upsert_postmeta($pdo, $pautaId, NJ_M2M_META_CLAIM_HASH, hash('sha256', $claim));
        nj_admin_upsert_postmeta($pdo, $pautaId, NJ_M2M_META_CLAIMED_AT, $claimedAt);
        nj_admin_upsert_postmeta($pdo, $pautaId, NJ_M2M_META_CLAIM_EXPIRES_AT, $expiresAt);
        nj_admin_upsert_postmeta($pdo, $pautaId, NJ_M2M_META_LAST_REQUEST_ID, $requestId);

        $fresh = nj_m2m_load_pauta($pdo, $pautaId, false);
        $pdo->commit();

        return [
            'pauta_id' => (string) $pautaId,
            'claim' => $claim,
            'source_hash' => preg_match('/^[0-9a-f]{64}$/i', (string) $fresh['source_hash'])
                ? strtolower((string) $fresh['source_hash'])
                : null,
            'state_machine' => nj_m2m_state_machine($fresh),
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function nj_m2m_resolve_pauta(PDO $pdo, int $pautaId, string $claim, string $requestId): array
{
    $pdo->beginTransaction();

    try {
        $row = nj_m2m_load_pauta($pdo, $pautaId, true);
        nj_m2m_verify_claim($row, $claim);

        nj_admin_upsert_postmeta($pdo, $pautaId, NJ_M2M_META_STATE, 'completed');
        nj_admin_upsert_postmeta($pdo, $pautaId, NJ_M2M_META_PROCESSED_AT, gmdate('c'));
        nj_admin_upsert_postmeta($pdo, $pautaId, NJ_M2M_META_LAST_REQUEST_ID, $requestId);
        nj_admin_upsert_postmeta($pdo, $pautaId, NJ_M2M_META_CLAIM_HASH, '');
        nj_admin_upsert_postmeta($pdo, $pautaId, NJ_M2M_META_CLAIMED_AT, '');
        nj_admin_upsert_postmeta($pdo, $pautaId, NJ_M2M_META_CLAIM_EXPIRES_AT, '');

        $fresh = nj_m2m_load_pauta($pdo, $pautaId, false);
        $pdo->commit();

        $draftId = (int) ($fresh['draft_post_id'] ?? 0);

        return [
            'pauta_id' => (string) $pautaId,
            'draft_id' => $draftId > 0 ? (string) $draftId : null,
            'state_machine' => nj_m2m_state_machine($fresh),
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
