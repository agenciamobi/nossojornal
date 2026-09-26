<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_bootstrap.php';

const NJ_SYSTEM_SESSION_NAME = 'nj_system';
const NJ_SYSTEM_SESSION_USER_KEY = 'user_id';
const NJ_SYSTEM_CSRF_KEY = 'csrf_token';

function nj_system_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Robots-Tag: noindex, nofollow');

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_THROW_ON_ERROR
    );
    exit;
}

function nj_system_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;

    session_name(NJ_SYSTEM_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/sistema',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    if (!isset($_SESSION[NJ_SYSTEM_CSRF_KEY]) || !is_string($_SESSION[NJ_SYSTEM_CSRF_KEY])) {
        try {
            $_SESSION[NJ_SYSTEM_CSRF_KEY] = bin2hex(random_bytes(24));
        } catch (Throwable) {
            $_SESSION[NJ_SYSTEM_CSRF_KEY] = hash('sha256', uniqid('nj-csrf', true));
        }
    }
}

function nj_system_request_body(): array
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
}

function nj_system_verify_wordpress_password(string $password, string $hash): bool
{
    if (strlen($password) > 4096 || $hash === '') {
        return false;
    }

    if (str_starts_with($hash, '$wp')) {
        $passwordToVerify = base64_encode(
            hash_hmac('sha384', $password, 'wp-sha384', true)
        );

        return password_verify($passwordToVerify, substr($hash, 3));
    }

    if (
        str_starts_with($hash, '$2y$')
        || str_starts_with($hash, '$2a$')
        || str_starts_with($hash, '$2b$')
    ) {
        return password_verify($password, $hash);
    }

    if (strlen($hash) <= 32) {
        return hash_equals($hash, md5($password));
    }

    return false;
}

function nj_system_user_caps(PDO $pdo, int $userId): array
{
    $usermeta = nj_table('usermeta');
    $prefix = (string) nj_db_config()['table_prefix'];
    $capabilityKey = $prefix . 'capabilities';

    $statement = $pdo->prepare(<<<SQL
SELECT meta_value
FROM {$usermeta}
WHERE
    user_id = :user_id
    AND meta_key = :meta_key
ORDER BY umeta_id DESC
LIMIT 1
SQL);
    $statement->execute([
        'user_id' => $userId,
        'meta_key' => $capabilityKey,
    ]);

    $serialized = $statement->fetchColumn();
    if (!is_string($serialized) || $serialized === '') {
        return [];
    }

    $decoded = @unserialize($serialized, ['allowed_classes' => false]);
    if (!is_array($decoded)) {
        return [];
    }

    $caps = [];
    foreach ($decoded as $capability => $enabled) {
        if (is_string($capability) && $enabled === true) {
            $caps[] = $capability;
        }
    }

    return $caps;
}

function nj_system_permissions(array $roles): array
{
    $roleSet = array_fill_keys($roles, true);

    $isAdmin = isset($roleSet['administrator']);
    $isEditor = isset($roleSet['editor']);
    $isAuthor = isset($roleSet['author']);
    $isContributor = isset($roleSet['contributor']);

    return [
        'dashboard' => true,
        'posts' => $isAdmin || $isEditor || $isAuthor || $isContributor,
        'categories' => $isAdmin || $isEditor,
        'media' => $isAdmin || $isEditor || $isAuthor,
        'users' => $isAdmin,
        'settings' => $isAdmin,
        'write' => false,
    ];
}

function nj_system_user_by_id(PDO $pdo, int $userId): ?array
{
    $users = nj_table('users');

    $statement = $pdo->prepare(<<<SQL
SELECT
    ID AS id,
    user_login AS login,
    user_nicename AS nicename,
    user_email AS email,
    user_url AS url,
    user_registered AS registered_at,
    user_status AS status,
    display_name AS display_name
FROM {$users}
WHERE ID = :id
LIMIT 1
SQL);
    $statement->execute(['id' => $userId]);
    $row = $statement->fetch();

    if (!$row) {
        return null;
    }

    $roles = nj_system_user_caps($pdo, (int) $row['id']);

    return [
        'id' => (int) $row['id'],
        'login' => (string) $row['login'],
        'nicename' => (string) $row['nicename'],
        'email' => (string) $row['email'],
        'url' => (string) $row['url'],
        'registeredAt' => (string) $row['registered_at'],
        'status' => (int) $row['status'],
        'displayName' => (string) $row['display_name'],
        'roles' => $roles,
        'permissions' => nj_system_permissions($roles),
    ];
}

function nj_system_current_user(): ?array
{
    nj_system_start_session();

    $userId = (int) ($_SESSION[NJ_SYSTEM_SESSION_USER_KEY] ?? 0);
    if ($userId < 1) {
        return null;
    }

    $user = nj_system_user_by_id(nj_db(), $userId);

    if ($user === null) {
        unset($_SESSION[NJ_SYSTEM_SESSION_USER_KEY]);
        return null;
    }

    return $user;
}

function nj_system_require_user(?string $permission = null): array
{
    $user = nj_system_current_user();

    if ($user === null) {
        nj_system_json([
            'ok' => false,
            'error' => ['code' => 'authentication_required'],
        ], 401);
    }

    if (
        $permission !== null
        && empty($user['permissions'][$permission])
    ) {
        nj_system_json([
            'ok' => false,
            'error' => ['code' => 'forbidden'],
        ], 403);
    }

    return $user;
}

function nj_system_verify_csrf(): void
{
    nj_system_start_session();

    $expected = (string) ($_SESSION[NJ_SYSTEM_CSRF_KEY] ?? '');
    $provided = trim((string) ($_SERVER['HTTP_X_NJ_CSRF'] ?? ''));

    if (
        $expected === ''
        || $provided === ''
        || !hash_equals($expected, $provided)
    ) {
        nj_system_json([
            'ok' => false,
            'error' => ['code' => 'csrf_invalid'],
        ], 403);
    }
}

function nj_system_public_session_payload(?array $user): array
{
    nj_system_start_session();

    return [
        'authenticated' => $user !== null,
        'user' => $user,
        'csrfToken' => (string) ($_SESSION[NJ_SYSTEM_CSRF_KEY] ?? ''),
    ];
}
