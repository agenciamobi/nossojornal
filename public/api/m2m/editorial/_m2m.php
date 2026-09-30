<?php
declare(strict_types=1);

require_once __DIR__ . '/../../admin/_admin.php';

const NJ_EDITORIAL_M2M_VERSION = 'm2m-editorial@2026-09-30-r1';
const NJ_EDITORIAL_M2M_DIRECTION_CONTEXT = 'nosso_jornal_editorial:core_to_provider:v1';
const NJ_EDITORIAL_M2M_MAX_BODY_BYTES = 200704;
const NJ_EDITORIAL_M2M_TIMESTAMP_WINDOW_SECONDS = 300;
const NJ_EDITORIAL_M2M_IDEMPOTENCY_STALE_SECONDS = 300;

function nj_m2m_json(array $payload, int $status = 200, ?string $requestId = null): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    if ($requestId !== null && $requestId !== '') {
        header('X-Request-ID: ' . $requestId);
    }

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_THROW_ON_ERROR
    );
    exit;
}

function nj_m2m_error_payload(string $code): array
{
    return ['error' => ['code' => $code]];
}

function nj_m2m_header(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    $value = $_SERVER[$serverKey] ?? '';

    return is_string($value) ? trim($value) : '';
}

function nj_m2m_raw_body(): string
{
    $declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($declared > NJ_EDITORIAL_M2M_MAX_BODY_BYTES) {
        throw new NjApiHttpException(413, 'request_too_large');
    }

    $raw = file_get_contents('php://input');
    if (!is_string($raw)) {
        throw new NjApiHttpException(400, 'invalid_request_body');
    }

    if (strlen($raw) > NJ_EDITORIAL_M2M_MAX_BODY_BYTES) {
        throw new NjApiHttpException(413, 'request_too_large');
    }

    return $raw;
}

function nj_m2m_request_path_query(): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if ($uri === '') {
        throw new NjApiHttpException(400, 'request_path_unavailable');
    }

    $path = parse_url($uri, PHP_URL_PATH);
    if (!is_string($path) || !str_starts_with($path, '/api/m2m/editorial/')) {
        throw new NjApiHttpException(400, 'request_path_invalid');
    }

    $query = (string) ($_SERVER['QUERY_STRING'] ?? '');

    return $path . ($query !== '' ? '?' . $query : '');
}

function nj_m2m_shared_secret(): string
{
    $secret = nj_env('NJ_EDITORIAL_HMAC_SECRET');
    if ($secret === null) {
        $config = nj_db_config();
        $candidate = $config['editorial_hmac_secret'] ?? '';
        $secret = is_string($candidate) ? trim($candidate) : '';
    }

    if (!is_string($secret) || strlen($secret) < 16) {
        throw new NjApiHttpException(503, 'editorial_m2m_not_configured');
    }

    return $secret;
}

function nj_m2m_cleanup_security_rows(PDO $pdo): void
{
    $replay = nj_app_table('editorial_m2m_replay');
    $idempotency = nj_app_table('editorial_m2m_idempotency');

    try {
        $pdo->exec("DELETE FROM {$replay} WHERE expires_at < UTC_TIMESTAMP()");
        $pdo->exec(
            "DELETE FROM {$idempotency}
             WHERE updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)"
        );
    } catch (Throwable) {
        // Cleanup is best-effort and must never weaken request verification.
    }
}

function nj_m2m_register_request(PDO $pdo, string $requestId, string $operation): void
{
    $table = nj_app_table('editorial_m2m_replay');
    nj_m2m_cleanup_security_rows($pdo);

    try {
        $statement = $pdo->prepare(
            "INSERT INTO {$table} (request_id, operation, received_at, expires_at)
             VALUES (:request_id, :operation, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE))"
        );
        $statement->execute([
            'request_id' => $requestId,
            'operation' => $operation,
        ]);
    } catch (PDOException $error) {
        if ((string) $error->getCode() === '23000') {
            throw new NjApiHttpException(409, 'request_replay');
        }
        throw $error;
    }
}

function nj_m2m_idempotency_existing(
    PDO $pdo,
    string $operation,
    string $keyHash
): ?array {
    $table = nj_app_table('editorial_m2m_idempotency');
    $statement = $pdo->prepare(
        "SELECT operation, idempotency_key_hash, request_hash, state,
                response_status, response_json, request_id, updated_at
         FROM {$table}
         WHERE operation = :operation
           AND idempotency_key_hash = :key_hash
         LIMIT 1"
    );
    $statement->execute([
        'operation' => $operation,
        'key_hash' => $keyHash,
    ]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

function nj_m2m_prepare_idempotency(
    PDO $pdo,
    string $operation,
    string $idempotencyKey,
    string $requestHash,
    string $requestId
): ?array {
    $table = nj_app_table('editorial_m2m_idempotency');
    $keyHash = hash('sha256', $idempotencyKey);
    $existing = nj_m2m_idempotency_existing($pdo, $operation, $keyHash);

    if (is_array($existing)) {
        if (!hash_equals((string) $existing['request_hash'], $requestHash)) {
            throw new NjApiHttpException(409, 'idempotency_conflict');
        }

        if ((string) $existing['state'] === 'succeeded') {
            $decoded = json_decode((string) ($existing['response_json'] ?? ''), true);
            if (!is_array($decoded)) {
                throw new NjApiHttpException(500, 'idempotency_record_invalid');
            }

            return [
                'cached' => true,
                'keyHash' => $keyHash,
                'status' => max(200, (int) ($existing['response_status'] ?? 200)),
                'body' => $decoded,
            ];
        }

        $updatedAt = strtotime((string) ($existing['updated_at'] ?? ''));
        $stale = $updatedAt !== false
            && $updatedAt < (time() - NJ_EDITORIAL_M2M_IDEMPOTENCY_STALE_SECONDS);

        if (!$stale) {
            throw new NjApiHttpException(409, 'idempotency_in_progress');
        }

        $takeover = $pdo->prepare(
            "UPDATE {$table}
             SET request_id = :request_id,
                 state = 'processing',
                 response_status = NULL,
                 response_json = NULL,
                 updated_at = UTC_TIMESTAMP()
             WHERE operation = :operation
               AND idempotency_key_hash = :key_hash
               AND request_hash = :request_hash"
        );
        $takeover->execute([
            'request_id' => $requestId,
            'operation' => $operation,
            'key_hash' => $keyHash,
            'request_hash' => $requestHash,
        ]);

        return ['cached' => false, 'keyHash' => $keyHash];
    }

    try {
        $insert = $pdo->prepare(
            "INSERT INTO {$table} (
                operation, idempotency_key_hash, request_hash, state, request_id,
                created_at, updated_at
             ) VALUES (
                :operation, :key_hash, :request_hash, 'processing', :request_id,
                UTC_TIMESTAMP(), UTC_TIMESTAMP()
             )"
        );
        $insert->execute([
            'operation' => $operation,
            'key_hash' => $keyHash,
            'request_hash' => $requestHash,
            'request_id' => $requestId,
        ]);
    } catch (PDOException $error) {
        if ((string) $error->getCode() !== '23000') {
            throw $error;
        }

        $existing = nj_m2m_idempotency_existing($pdo, $operation, $keyHash);
        if (!is_array($existing) || !hash_equals((string) $existing['request_hash'], $requestHash)) {
            throw new NjApiHttpException(409, 'idempotency_conflict');
        }
        throw new NjApiHttpException(409, 'idempotency_in_progress');
    }

    return ['cached' => false, 'keyHash' => $keyHash];
}

function nj_m2m_complete_idempotency(
    PDO $pdo,
    string $operation,
    string $keyHash,
    string $requestId,
    int $status,
    array $body
): void {
    $table = nj_app_table('editorial_m2m_idempotency');
    $encoded = json_encode(
        $body,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if (!is_string($encoded)) {
        throw new RuntimeException('idempotency_response_encode_failed');
    }

    $statement = $pdo->prepare(
        "UPDATE {$table}
         SET state = 'succeeded',
             response_status = :response_status,
             response_json = :response_json,
             updated_at = UTC_TIMESTAMP()
         WHERE operation = :operation
           AND idempotency_key_hash = :key_hash
           AND request_id = :request_id
         LIMIT 1"
    );
    $statement->execute([
        'response_status' => $status,
        'response_json' => $encoded,
        'operation' => $operation,
        'key_hash' => $keyHash,
        'request_id' => $requestId,
    ]);

    if ($statement->rowCount() < 1) {
        throw new RuntimeException('idempotency_completion_missing');
    }
}

function nj_m2m_release_idempotency(
    PDO $pdo,
    string $operation,
    ?string $keyHash,
    string $requestId
): void {
    if ($keyHash === null || $keyHash === '') {
        return;
    }

    $table = nj_app_table('editorial_m2m_idempotency');
    try {
        $statement = $pdo->prepare(
            "DELETE FROM {$table}
             WHERE operation = :operation
               AND idempotency_key_hash = :key_hash
               AND request_id = :request_id
               AND state = 'processing'
             LIMIT 1"
        );
        $statement->execute([
            'operation' => $operation,
            'key_hash' => $keyHash,
            'request_id' => $requestId,
        ]);
    } catch (Throwable) {
        // A failed cleanup may only delay a retry until the stale timeout.
    }
}

function nj_m2m_verify_request(string $expectedMethod, string $operation): array
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== $expectedMethod) {
        header('Allow: ' . $expectedMethod);
        throw new NjApiHttpException(405, 'method_not_allowed');
    }

    $rawBody = nj_m2m_raw_body();
    if ($method === 'GET' && $rawBody !== '') {
        throw new NjApiHttpException(400, 'get_body_not_allowed');
    }

    $requestId = nj_m2m_header('X-Request-ID');
    if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $requestId)) {
        throw new NjApiHttpException(400, 'request_id_invalid');
    }

    $timestampRaw = nj_m2m_header('X-MOBI-Editorial-Timestamp');
    if (!preg_match('/^[0-9]{9,12}$/', $timestampRaw)) {
        throw new NjApiHttpException(401, 'timestamp_invalid');
    }
    $timestamp = (int) $timestampRaw;
    if (abs(time() - $timestamp) > NJ_EDITORIAL_M2M_TIMESTAMP_WINDOW_SECONDS) {
        throw new NjApiHttpException(401, 'timestamp_outside_window');
    }

    $signatureHeader = nj_m2m_header('X-MOBI-Editorial-Signature');
    if (!preg_match('/^v1=([0-9a-fA-F]{64})$/', $signatureHeader, $signatureMatch)) {
        throw new NjApiHttpException(401, 'signature_invalid');
    }

    $idempotencyKey = nj_m2m_header('Idempotency-Key');
    if ($method === 'POST') {
        if (!preg_match('/^[A-Za-z0-9._:-]{8,160}$/', $idempotencyKey)) {
            throw new NjApiHttpException(400, 'idempotency_key_invalid');
        }
    } elseif ($idempotencyKey !== '') {
        throw new NjApiHttpException(400, 'idempotency_key_not_allowed');
    }

    $pathQuery = nj_m2m_request_path_query();
    $bodyHash = hash('sha256', $rawBody);
    $secret = nj_m2m_shared_secret();
    $directionalSecret = hash_hmac(
        'sha256',
        NJ_EDITORIAL_M2M_DIRECTION_CONTEXT,
        $secret
    );
    $manifest = implode('.', [
        (string) $timestamp,
        $requestId,
        $method,
        $pathQuery,
        $bodyHash,
    ]);
    $expectedSignature = hash_hmac('sha256', $manifest, $directionalSecret);

    if (!hash_equals($expectedSignature, strtolower((string) $signatureMatch[1]))) {
        throw new NjApiHttpException(401, 'signature_invalid');
    }

    $pdo = nj_db();
    nj_m2m_register_request($pdo, $requestId, $operation);

    $idempotency = null;
    if ($method === 'POST') {
        $requestHash = hash('sha256', $operation . "\n" . $bodyHash);
        $idempotency = nj_m2m_prepare_idempotency(
            $pdo,
            $operation,
            $idempotencyKey,
            $requestHash,
            $requestId
        );
    }

    return [
        'method' => $method,
        'operation' => $operation,
        'requestId' => $requestId,
        'rawBody' => $rawBody,
        'bodyHash' => $bodyHash,
        'pathQuery' => $pathQuery,
        'idempotencyKey' => $idempotencyKey,
        'idempotency' => $idempotency,
        'pdo' => $pdo,
    ];
}

function nj_m2m_body(array $context): array
{
    $raw = (string) ($context['rawBody'] ?? '');
    if ($raw === '') {
        return [];
    }

    try {
        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new NjApiHttpException(400, 'invalid_json');
    }

    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new NjApiHttpException(400, 'invalid_json');
    }

    return $decoded;
}

function nj_m2m_run(string $method, string $operation, callable $handler): never
{
    $context = null;
    $keyHash = null;

    try {
        $context = nj_m2m_verify_request($method, $operation);
        $cached = $context['idempotency'] ?? null;

        if (is_array($cached) && ($cached['cached'] ?? false) === true) {
            nj_m2m_json(
                is_array($cached['body'] ?? null) ? $cached['body'] : [],
                (int) ($cached['status'] ?? 200),
                (string) $context['requestId']
            );
        }

        if (is_array($cached)) {
            $keyHash = (string) ($cached['keyHash'] ?? '');
        }

        $result = $handler($context);
        if (!is_array($result)) {
            throw new RuntimeException('m2m_handler_invalid_result');
        }

        $status = isset($result['_status']) ? (int) $result['_status'] : 200;
        unset($result['_status']);

        if ($method === 'POST' && $keyHash !== '') {
            nj_m2m_complete_idempotency(
                $context['pdo'],
                $operation,
                $keyHash,
                (string) $context['requestId'],
                $status,
                $result
            );
        }

        nj_m2m_json($result, $status, (string) $context['requestId']);
    } catch (NjApiHttpException $error) {
        if (is_array($context) && isset($context['pdo'], $context['requestId'])) {
            nj_m2m_release_idempotency(
                $context['pdo'],
                $operation,
                $keyHash,
                (string) $context['requestId']
            );
        }

        nj_m2m_json(
            nj_m2m_error_payload($error->errorCode),
            $error->status,
            is_array($context) ? (string) ($context['requestId'] ?? '') : null
        );
    } catch (Throwable $error) {
        $requestId = is_array($context)
            ? (string) ($context['requestId'] ?? '')
            : '';
        if (is_array($context) && isset($context['pdo'])) {
            nj_m2m_release_idempotency(
                $context['pdo'],
                $operation,
                $keyHash,
                $requestId
            );
        }

        error_log(
            '[nossojornal-m2m-editorial] request='
            . ($requestId !== '' ? $requestId : 'unknown')
            . ' operation=' . $operation
            . ' error=' . get_class($error)
        );

        nj_m2m_json(
            nj_m2m_error_payload('internal_error'),
            500,
            $requestId !== '' ? $requestId : null
        );
    }
}
