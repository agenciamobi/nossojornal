<?php
declare(strict_types=1);

require_once __DIR__ . '/../v1/_bootstrap.php';
require_once __DIR__ . '/../v1/_content.php';

const NJ_ADMIN_SESSION_NAME = 'nj_admin_session';

function nj_admin_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_THROW_ON_ERROR
    );
    exit;
}

function nj_admin_run(array $methods, callable $handler): never
{
    $requestId = nj_request_id();

    try {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        if (!in_array($method, $methods, true)) {
            header('Allow: ' . implode(', ', $methods));
            throw new NjApiHttpException(405, 'method_not_allowed');
        }

        $data = $handler($method);

        nj_admin_json([
            'ok' => true,
            'data' => $data,
            'meta' => [
                'requestId' => $requestId,
                'generatedAt' => gmdate('c'),
            ],
        ]);
    } catch (NjApiHttpException $error) {
        nj_admin_json([
            'ok' => false,
            'error' => ['code' => $error->errorCode],
            'meta' => [
                'requestId' => $requestId,
                'generatedAt' => gmdate('c'),
            ],
        ], $error->status);
    } catch (Throwable $error) {
        error_log('[nossojornal-admin] request=' . $requestId . ' error=' . get_class($error));

        nj_admin_json([
            'ok' => false,
            'error' => ['code' => 'internal_error'],
            'meta' => [
                'requestId' => $requestId,
                'generatedAt' => gmdate('c'),
            ],
        ], 500);
    }
}

function nj_admin_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name(NJ_ADMIN_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');

    session_start();
}

function nj_admin_request_body(): array
{
    $raw = file_get_contents('php://input');

    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    try {
        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new NjApiHttpException(400, 'invalid_json');
    }

    if (!is_array($decoded)) {
        throw new NjApiHttpException(400, 'invalid_json');
    }

    return $decoded;
}

function nj_admin_unserialize_array(string $value): array
{
    if ($value === '') {
        return [];
    }

    $decoded = @unserialize($value, ['allowed_classes' => false]);

    return is_array($decoded) ? $decoded : [];
}

function nj_admin_role_definitions(PDO $pdo): array
{
    $options = nj_table('options');
    $prefix = (string) nj_db_config()['table_prefix'];

    $statement = $pdo->prepare(
        "SELECT option_value FROM {$options} WHERE option_name = :option_name LIMIT 1"
    );
    $statement->execute(['option_name' => $prefix . 'user_roles']);
    $value = $statement->fetchColumn();

    return is_string($value) ? nj_admin_unserialize_array($value) : [];
}

function nj_admin_user_roles_and_capabilities(PDO $pdo, int $userId): array
{
    $usermeta = nj_table('usermeta');
    $prefix = (string) nj_db_config()['table_prefix'];

    $statement = $pdo->prepare(<<<SQL
SELECT meta_value
FROM {$usermeta}
WHERE
    user_id = :user_id
    AND meta_key = :capability_key
ORDER BY umeta_id DESC
LIMIT 1
SQL);
    $statement->execute([
        'user_id' => $userId,
        'capability_key' => $prefix . 'capabilities',
    ]);

    $direct = nj_admin_unserialize_array((string) ($statement->fetchColumn() ?: ''));
    $roles = [];
    $capabilities = [];

    foreach ($direct as $name => $enabled) {
        if ($enabled !== true && $enabled !== 1) {
            continue;
        }

        $roles[] = (string) $name;
        $capabilities[(string) $name] = true;
    }

    $definitions = nj_admin_role_definitions($pdo);

    foreach ($roles as $role) {
        $roleCapabilities = $definitions[$role]['capabilities'] ?? [];

        if (!is_array($roleCapabilities)) {
            continue;
        }

        foreach ($roleCapabilities as $capability => $enabled) {
            if ($enabled === true || $enabled === 1) {
                $capabilities[(string) $capability] = true;
            }
        }
    }

    return [
        'roles' => array_values(array_unique($roles)),
        'capabilities' => array_keys($capabilities),
    ];
}

function nj_admin_user_payload(PDO $pdo, array $row): array
{
    $access = nj_admin_user_roles_and_capabilities($pdo, (int) $row['ID']);

    return [
        'id' => (int) $row['ID'],
        'login' => (string) $row['user_login'],
        'email' => (string) $row['user_email'],
        'displayName' => trim((string) $row['display_name']) !== ''
            ? (string) $row['display_name']
            : (string) $row['user_login'],
        'registeredAt' => nj_content_iso8601((string) $row['user_registered']),
        'roles' => $access['roles'],
        'capabilities' => $access['capabilities'],
        'permissions' => [
            'editPosts' => in_array('edit_posts', $access['capabilities'], true),
            'publishPosts' => in_array('publish_posts', $access['capabilities'], true),
            'manageCategories' => in_array('manage_categories', $access['capabilities'], true),
            'listUsers' => in_array('list_users', $access['capabilities'], true),
            'editUsers' => in_array('edit_users', $access['capabilities'], true),
            'manageOptions' => in_array('manage_options', $access['capabilities'], true),
        ],
    ];
}

function nj_admin_find_user(string $identity): ?array
{
    $pdo = nj_db();
    $users = nj_table('users');

    $statement = $pdo->prepare(<<<SQL
SELECT
    ID,
    user_login,
    user_pass,
    user_email,
    user_registered,
    user_status,
    display_name
FROM {$users}
WHERE
    user_status = 0
    AND (user_login = :identity OR user_email = :identity)
LIMIT 1
SQL);
    $statement->execute(['identity' => $identity]);
    $row = $statement->fetch();

    return $row ?: null;
}

function nj_admin_wp_portable_hash(string $password, string $storedHash): bool
{
    $itoa64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    if (strlen($storedHash) < 34) {
        return false;
    }

    $countLog2 = strpos($itoa64, $storedHash[3]);
    if ($countLog2 === false || $countLog2 < 7 || $countLog2 > 30) {
        return false;
    }

    $count = 1 << $countLog2;
    $salt = substr($storedHash, 4, 8);

    if (strlen($salt) !== 8) {
        return false;
    }

    $hash = md5($salt . $password, true);

    do {
        $hash = md5($hash . $password, true);
    } while (--$count);

    $encoded = '';
    $countBytes = 16;
    $index = 0;

    do {
        $value = ord($hash[$index++]);
        $encoded .= $itoa64[$value & 0x3f];

        if ($index < $countBytes) {
            $value |= ord($hash[$index]) << 8;
        }

        $encoded .= $itoa64[($value >> 6) & 0x3f];

        if ($index++ >= $countBytes) {
            break;
        }

        if ($index < $countBytes) {
            $value |= ord($hash[$index]) << 16;
        }

        $encoded .= $itoa64[($value >> 12) & 0x3f];

        if ($index++ >= $countBytes) {
            break;
        }

        $encoded .= $itoa64[($value >> 18) & 0x3f];
    } while ($index < $countBytes);

    return hash_equals(substr($storedHash, 0, 12 + 22), substr($storedHash, 0, 12) . $encoded);
}

function nj_admin_verify_wp_password(string $password, string $storedHash): bool
{
    if ($password === '' || $storedHash === '') {
        return false;
    }

    if (str_starts_with($storedHash, '$wp$2')) {
        $prepared = base64_encode(hash_hmac('sha384', $password, 'wp-sha384', true));

        return password_verify($prepared, substr($storedHash, 3));
    }

    if (
        str_starts_with($storedHash, '$2y$')
        || str_starts_with($storedHash, '$2a$')
        || str_starts_with($storedHash, '$2b$')
    ) {
        return password_verify($password, $storedHash);
    }

    if (str_starts_with($storedHash, '$P$') || str_starts_with($storedHash, '$H$')) {
        return nj_admin_wp_portable_hash($password, $storedHash);
    }

    return false;
}

function nj_admin_login(string $identity, string $password): array
{
    $identity = trim($identity);

    if ($identity === '' || $password === '') {
        throw new NjApiHttpException(422, 'credentials_required');
    }

    $row = nj_admin_find_user($identity);

    if ($row === null || !nj_admin_verify_wp_password($password, (string) $row['user_pass'])) {
        usleep(250000);
        throw new NjApiHttpException(401, 'invalid_credentials');
    }

    $pdo = nj_db();
    $user = nj_admin_user_payload($pdo, $row);

    if (!in_array('read', $user['capabilities'], true)) {
        throw new NjApiHttpException(403, 'admin_access_denied');
    }

    nj_admin_start_session();
    session_regenerate_id(true);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['login_at'] = time();
    $_SESSION['user_agent_hash'] = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $_SESSION['csrf'] = bin2hex(random_bytes(24));

    return [
        'user' => $user,
        'csrfToken' => $_SESSION['csrf'],
    ];
}

function nj_admin_current_user(bool $required = true): ?array
{
    nj_admin_start_session();

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $expectedAgent = (string) ($_SESSION['user_agent_hash'] ?? '');
    $actualAgent = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

    if ($userId <= 0 || $expectedAgent === '' || !hash_equals($expectedAgent, $actualAgent)) {
        if ($required) {
            throw new NjApiHttpException(401, 'authentication_required');
        }

        return null;
    }

    $pdo = nj_db();
    $users = nj_table('users');

    $statement = $pdo->prepare(<<<SQL
SELECT
    ID,
    user_login,
    user_email,
    user_registered,
    user_status,
    display_name
FROM {$users}
WHERE ID = :id AND user_status = 0
LIMIT 1
SQL);
    $statement->execute(['id' => $userId]);
    $row = $statement->fetch();

    if (!$row) {
        $_SESSION = [];

        if ($required) {
            throw new NjApiHttpException(401, 'authentication_required');
        }

        return null;
    }

    return nj_admin_user_payload($pdo, $row);
}

function nj_admin_csrf_token(): string
{
    nj_admin_start_session();

    if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }

    return $_SESSION['csrf'];
}

function nj_admin_require_csrf(): void
{
    nj_admin_start_session();

    $expected = (string) ($_SESSION['csrf'] ?? '');
    $provided = trim((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));

    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        throw new NjApiHttpException(403, 'invalid_csrf_token');
    }
}

function nj_admin_require_capability(array $user, string $capability): void
{
    if (!in_array($capability, $user['capabilities'], true)) {
        throw new NjApiHttpException(403, 'insufficient_permissions');
    }
}
