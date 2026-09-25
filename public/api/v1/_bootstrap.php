<?php
declare(strict_types=1);

const NJ_API_VERSION = 'v1';
const NJ_API_SOURCE = 'legacy_wordpress';

final class NjApiHttpException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message = ''
    ) {
        parent::__construct($message ?: $errorCode);
    }
}

function nj_request_id(): string
{
    try {
        return bin2hex(random_bytes(8));
    } catch (Throwable) {
        return str_replace('.', '', uniqid('nj', true));
    }
}

function nj_json(array $payload, int $status = 200, string $cacheControl = 'no-store'): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: ' . $cacheControl);
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    header('Vary: Accept-Encoding');

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_THROW_ON_ERROR
    );
    exit;
}

function nj_run(callable $handler, string $cacheControl = 'no-store'): never
{
    $requestId = nj_request_id();

    try {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            header('Allow: GET');
            throw new NjApiHttpException(405, 'method_not_allowed');
        }

        $data = $handler();

        nj_json([
            'ok' => true,
            'data' => $data,
            'meta' => [
                'apiVersion' => NJ_API_VERSION,
                'source' => NJ_API_SOURCE,
                'requestId' => $requestId,
                'generatedAt' => gmdate('c'),
            ],
        ], 200, $cacheControl);
    } catch (NjApiHttpException $error) {
        nj_json([
            'ok' => false,
            'error' => [
                'code' => $error->errorCode,
            ],
            'meta' => [
                'apiVersion' => NJ_API_VERSION,
                'requestId' => $requestId,
                'generatedAt' => gmdate('c'),
            ],
        ], $error->status);
    } catch (Throwable $error) {
        error_log('[nossojornal-api] request=' . $requestId . ' error=' . get_class($error));

        nj_json([
            'ok' => false,
            'error' => [
                'code' => 'internal_error',
            ],
            'meta' => [
                'apiVersion' => NJ_API_VERSION,
                'requestId' => $requestId,
                'generatedAt' => gmdate('c'),
            ],
        ], 500);
    }
}

function nj_env(string $key): ?string
{
    $value = getenv($key);
    if ($value === false) {
        return null;
    }

    $value = trim((string) $value);
    return $value !== '' ? $value : null;
}

function nj_db_config(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    $home = dirname(__DIR__, 3);
    $externalConfigFile = $home . '/.mobi/nossojornal-api.php';

    $resolved = [
        'db_host' => 'localhost',
        'db_port' => 3306,
        'db_name' => 'nossojornal_wp262',
        'db_user' => '',
        'db_password' => '',
        'table_prefix' => 'njsite_',
    ];

    if (is_file($externalConfigFile)) {
        $external = require $externalConfigFile;
        if (!is_array($external)) {
            throw new NjApiHttpException(503, 'database_config_invalid');
        }
        $resolved = array_merge($resolved, $external);
    }

    $envMap = [
        'NJ_DB_HOST' => 'db_host',
        'NJ_DB_NAME' => 'db_name',
        'NJ_DB_USER' => 'db_user',
        'NJ_DB_PASSWORD' => 'db_password',
        'NJ_DB_PREFIX' => 'table_prefix',
    ];

    foreach ($envMap as $env => $key) {
        $value = nj_env($env);
        if ($value !== null) {
            $resolved[$key] = $value;
        }
    }

    if (($port = nj_env('NJ_DB_PORT')) !== null) {
        $resolved['db_port'] = (int) $port;
    }

    foreach (['db_host', 'db_name', 'db_user', 'table_prefix'] as $required) {
        if (!isset($resolved[$required]) || trim((string) $resolved[$required]) === '') {
            throw new NjApiHttpException(503, 'database_not_configured');
        }
    }

    if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $resolved['table_prefix'])) {
        throw new NjApiHttpException(503, 'database_prefix_invalid');
    }

    $port = (int) ($resolved['db_port'] ?? 3306);
    if ($port < 1 || $port > 65535) {
        throw new NjApiHttpException(503, 'database_port_invalid');
    }
    $resolved['db_port'] = $port;

    $config = $resolved;
    return $config;
}

function nj_table(string $suffix): string
{
    if (!preg_match('/^[a-z0-9_]+$/', $suffix)) {
        throw new LogicException('invalid_table_suffix');
    }

    $prefix = (string) nj_db_config()['table_prefix'];
    return '`' . $prefix . $suffix . '`';
}

function nj_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = nj_db_config();
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['db_host'],
        $config['db_port'],
        $config['db_name']
    );

    try {
        $pdo = new PDO(
            $dsn,
            (string) $config['db_user'],
            (string) ($config['db_password'] ?? ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]
        );
    } catch (PDOException) {
        throw new NjApiHttpException(503, 'database_unavailable');
    }

    return $pdo;
}

function nj_build_category_tree(array $items): array
{
    $byParent = [];

    foreach ($items as $item) {
        $key = $item['parentId'] ?? 0;
        $byParent[$key][] = $item;
    }

    $build = static function (int $parentId) use (&$build, $byParent): array {
        $children = [];

        foreach ($byParent[$parentId] ?? [] as $item) {
            $item['children'] = $build((int) $item['id']);
            $children[] = $item;
        }

        return $children;
    };

    return $build(0);
}
