<?php
declare(strict_types=1);

require_once __DIR__ . '/../v1/_bootstrap.php';
require_once __DIR__ . '/../v1/_content.php';

const NJ_ADMIN_SESSION_NAME = 'nj_admin_session';
const NJ_PAUTAS_OWNER_LOGIN = 'agenciamobi';

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
            'editPages' => in_array('edit_pages', $access['capabilities'], true),
            'publishPages' => in_array('publish_pages', $access['capabilities'], true),
            'manageCategories' => in_array('manage_categories', $access['capabilities'], true),
            'uploadFiles' => in_array('upload_files', $access['capabilities'], true),
            'listUsers' => in_array('list_users', $access['capabilities'], true),
            'editUsers' => in_array('edit_users', $access['capabilities'], true),
            'manageOptions' => in_array('manage_options', $access['capabilities'], true),
            'moderateComments' => in_array('moderate_comments', $access['capabilities'], true),
            'managePautas' => (string) $row['user_login'] === NJ_PAUTAS_OWNER_LOGIN,
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
    AND (user_login = :identity_login OR user_email = :identity_email)
LIMIT 1
SQL);
    $statement->execute([
        'identity_login' => $identity,
        'identity_email' => $identity,
    ]);
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

    if (!in_array('edit_posts', $user['capabilities'], true)) {
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


function nj_admin_require_pautas_owner(array $user): void
{
    if ((string) ($user['login'] ?? '') !== NJ_PAUTAS_OWNER_LOGIN) {
        throw new NjApiHttpException(403, 'pautas_access_denied');
    }
}


function nj_admin_slugify(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    if (class_exists('Transliterator')) {
        $transliterator = Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
        if ($transliterator !== null) {
            $value = $transliterator->transliterate($value);
        }
    }

    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii) && $ascii !== '') {
            $value = $ascii;
        }
    }

    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');

    return substr($value, 0, 180);
}

function nj_admin_unique_post_slug(
    PDO $pdo,
    int $postId,
    string $requestedSlug,
    string $fallbackTitle
): string {
    $posts = nj_table('posts');
    $base = nj_admin_slugify($requestedSlug !== '' ? $requestedSlug : $fallbackTitle);

    if ($base === '') {
        $base = 'noticia-' . $postId;
    }

    $candidate = $base;
    $suffix = 2;

    $statement = $pdo->prepare(<<<SQL
SELECT ID
FROM {$posts}
WHERE
    post_type = 'post'
    AND post_name = :slug
    AND ID <> :id
LIMIT 1
SQL);

    while (true) {
        $statement->execute([
            'slug' => $candidate,
            'id' => $postId,
        ]);

        if (!$statement->fetchColumn()) {
            return $candidate;
        }

        $candidate = substr($base, 0, 170) . '-' . $suffix;
        $suffix++;

        if ($suffix > 500) {
            throw new RuntimeException('unique_slug_exhausted');
        }
    }
}

function nj_admin_upsert_postmeta(
    PDO $pdo,
    int $postId,
    string $key,
    string $value
): void {
    $postmeta = nj_table('postmeta');

    $find = $pdo->prepare(<<<SQL
SELECT meta_id
FROM {$postmeta}
WHERE
    post_id = :post_id
    AND meta_key = :meta_key
ORDER BY meta_id DESC
LIMIT 1
SQL);
    $find->execute([
        'post_id' => $postId,
        'meta_key' => $key,
    ]);
    $metaId = (int) ($find->fetchColumn() ?: 0);

    if ($value === '') {
        if ($metaId > 0) {
            $delete = $pdo->prepare(
                "DELETE FROM {$postmeta} WHERE post_id = :post_id AND meta_key = :meta_key"
            );
            $delete->execute([
                'post_id' => $postId,
                'meta_key' => $key,
            ]);
        }

        return;
    }

    if ($metaId > 0) {
        $update = $pdo->prepare(<<<SQL
UPDATE {$postmeta}
SET meta_value = :meta_value
WHERE meta_id = :meta_id
LIMIT 1
SQL);
        $update->execute([
            'meta_value' => $value,
            'meta_id' => $metaId,
        ]);

        return;
    }

    $insert = $pdo->prepare(<<<SQL
INSERT INTO {$postmeta} (post_id, meta_key, meta_value)
VALUES (:post_id, :meta_key, :meta_value)
SQL);
    $insert->execute([
        'post_id' => $postId,
        'meta_key' => $key,
        'meta_value' => $value,
    ]);
}

function nj_admin_recount_categories(PDO $pdo, array $termTaxonomyIds): void
{
    $termTaxonomyIds = array_values(array_unique(array_filter(array_map(
        static fn (mixed $value): int => (int) $value,
        $termTaxonomyIds
    ))));

    if ($termTaxonomyIds === []) {
        return;
    }

    $posts = nj_table('posts');
    $relationships = nj_table('term_relationships');
    $taxonomy = nj_table('term_taxonomy');

    $statement = $pdo->prepare(<<<SQL
UPDATE {$taxonomy} tt
SET tt.count = (
    SELECT COUNT(*)
    FROM {$relationships} tr
    INNER JOIN {$posts} p ON p.ID = tr.object_id
    WHERE
        tr.term_taxonomy_id = tt.term_taxonomy_id
        AND p.post_type = 'post'
        AND p.post_status = 'publish'
)
WHERE tt.term_taxonomy_id = :taxonomy_id
SQL);

    foreach ($termTaxonomyIds as $taxonomyId) {
        $statement->execute(['taxonomy_id' => $taxonomyId]);
    }
}

function nj_admin_validate_category_parent(
    PDO $pdo,
    int $categoryId,
    ?int $parentId
): void {
    if ($parentId === null || $parentId === 0) {
        return;
    }

    if ($parentId === $categoryId) {
        throw new NjApiHttpException(422, 'invalid_category_parent');
    }

    $taxonomy = nj_table('term_taxonomy');

    $statement = $pdo->prepare(<<<SQL
SELECT term_id, parent
FROM {$taxonomy}
WHERE
    taxonomy = 'category'
    AND term_id = :term_id
LIMIT 1
SQL);

    $visited = [];
    $cursor = $parentId;

    while ($cursor > 0) {
        if (isset($visited[$cursor])) {
            throw new NjApiHttpException(422, 'invalid_category_parent');
        }

        if ($cursor === $categoryId) {
            throw new NjApiHttpException(422, 'invalid_category_parent');
        }

        $visited[$cursor] = true;
        $statement->execute(['term_id' => $cursor]);
        $row = $statement->fetch();

        if (!$row) {
            throw new NjApiHttpException(422, 'category_parent_not_found');
        }

        $cursor = (int) $row['parent'];
    }
}


function nj_admin_page_public_url(string $slug): ?string
{
    return match ($slug) {
        'quem-somos' => '/sobre',
        'contato' => '/contato',
        default => null,
    };
}

function nj_admin_unique_page_slug(
    PDO $pdo,
    int $pageId,
    string $requestedSlug,
    string $fallbackTitle
): string {
    $posts = nj_table('posts');
    $base = nj_admin_slugify($requestedSlug !== '' ? $requestedSlug : $fallbackTitle);

    if ($base === '') {
        $base = 'pagina-' . $pageId;
    }

    $candidate = $base;
    $suffix = 2;

    $statement = $pdo->prepare(<<<SQL
SELECT ID
FROM {$posts}
WHERE
    post_type = 'page'
    AND post_name = :slug
    AND ID <> :id
LIMIT 1
SQL);

    while (true) {
        $statement->execute([
            'slug' => $candidate,
            'id' => $pageId,
        ]);

        if (!$statement->fetchColumn()) {
            return $candidate;
        }

        $candidate = substr($base, 0, 170) . '-' . $suffix;
        $suffix++;

        if ($suffix > 500) {
            throw new RuntimeException('unique_page_slug_exhausted');
        }
    }
}


function nj_admin_log_post_activity(
    PDO $pdo,
    int $postId,
    int $userId,
    string $action,
    array $payload = []
): void {
    $posts = nj_table('posts');

    $statement = $pdo->prepare(<<<SQL
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
    :content,
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
    :post_parent,
    '',
    0,
    'nj_activity',
    '',
    0
)
SQL);
    $statement->execute([
        'author_id' => $userId,
        'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        'title' => substr($action, 0, 200),
        'post_parent' => $postId,
    ]);
}

function nj_admin_post_snapshot(PDO $pdo, int $postId): array
{
    $posts = nj_table('posts');
    $postmeta = nj_table('postmeta');
    $relationships = nj_table('term_relationships');
    $taxonomy = nj_table('term_taxonomy');

    $statement = $pdo->prepare(<<<SQL
SELECT
    ID,
    post_title,
    post_name,
    post_excerpt,
    post_content,
    post_status,
    post_date,
    post_modified
FROM {$posts}
WHERE
    ID = :id
    AND post_type = 'post'
LIMIT 1
SQL);
    $statement->execute(['id' => $postId]);
    $post = $statement->fetch();

    if (!$post) {
        throw new NjApiHttpException(404, 'post_not_found');
    }

    $categoryStatement = $pdo->prepare(<<<SQL
SELECT tt.term_id
FROM {$relationships} tr
INNER JOIN {$taxonomy} tt
    ON tt.term_taxonomy_id = tr.term_taxonomy_id
    AND tt.taxonomy = 'category'
WHERE tr.object_id = :post_id
ORDER BY tt.term_id ASC
SQL);
    $categoryStatement->execute(['post_id' => $postId]);
    $categoryIds = array_map('intval', $categoryStatement->fetchAll(PDO::FETCH_COLUMN));

    $metaStatement = $pdo->prepare(<<<SQL
SELECT meta_key, meta_value
FROM {$postmeta}
WHERE
    post_id = :post_id
    AND meta_key IN (
        '_yoast_wpseo_title',
        '_yoast_wpseo_metadesc',
        '_yoast_wpseo_primary_category'
    )
ORDER BY meta_id DESC
SQL);
    $metaStatement->execute(['post_id' => $postId]);

    $meta = [];
    foreach ($metaStatement->fetchAll() as $row) {
        $key = (string) $row['meta_key'];
        if (!array_key_exists($key, $meta)) {
            $meta[$key] = (string) $row['meta_value'];
        }
    }

    return [
        'title' => (string) $post['post_title'],
        'slug' => (string) $post['post_name'],
        'excerpt' => (string) $post['post_excerpt'],
        'content' => (string) $post['post_content'],
        'status' => (string) $post['post_status'],
        'publishedAt' => nj_content_iso8601((string) $post['post_date']),
        'modifiedAt' => nj_content_iso8601((string) $post['post_modified']),
        'categoryIds' => $categoryIds,
        'seo' => [
            'title' => (string) ($meta['_yoast_wpseo_title'] ?? ''),
            'description' => (string) ($meta['_yoast_wpseo_metadesc'] ?? ''),
            'primaryCategoryId' => (int) ($meta['_yoast_wpseo_primary_category'] ?? 0),
        ],
    ];
}

function nj_admin_create_revision(
    PDO $pdo,
    int $postId,
    int $userId,
    string $kind = 'save'
): int {
    $posts = nj_table('posts');
    $snapshot = nj_admin_post_snapshot($pdo, $postId);

    $statement = $pdo->prepare(<<<SQL
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
    :content,
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
    :post_parent,
    '',
    0,
    'nj_revision',
    '',
    0
)
SQL);
    $statement->execute([
        'author_id' => $userId,
        'content' => json_encode([
            'kind' => $kind,
            'snapshot' => $snapshot,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        'title' => 'Revisão de ' . $snapshot['title'],
        'post_parent' => $postId,
    ]);

    return (int) $pdo->lastInsertId();
}

function nj_admin_require_post_editor(PDO $pdo, array $user, int $postId): array
{
    $posts = nj_table('posts');

    $statement = $pdo->prepare(<<<SQL
SELECT ID, post_author, post_status, post_title, post_name
FROM {$posts}
WHERE ID = :id AND post_type = 'post'
LIMIT 1
SQL);
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
