<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

const NJ_PAUTA_TYPE = 'nj_pauta';
const NJ_PAUTA_META_STAGE = '_nj_pauta_stage';
const NJ_PAUTA_META_PRIORITY = '_nj_pauta_priority';
const NJ_PAUTA_META_TOPIC = '_nj_pauta_topic';
const NJ_PAUTA_META_SOURCE_NAME = '_nj_pauta_source_name';
const NJ_PAUTA_META_SOURCE_URL = '_nj_pauta_source_url';
const NJ_PAUTA_META_FEED_URL = '_nj_pauta_feed_url';
const NJ_PAUTA_META_EXTERNAL_ID = '_nj_pauta_external_id';
const NJ_PAUTA_META_SOURCE_PUBLISHED_AT = '_nj_pauta_source_published_at';
const NJ_PAUTA_META_CAPTURED_AT = '_nj_pauta_captured_at';
const NJ_PAUTA_META_SOURCE_HASH = '_nj_pauta_source_hash';
const NJ_PAUTA_META_DEADLINE = '_nj_pauta_deadline';
const NJ_PAUTA_META_ASSIGNEE = '_nj_pauta_assignee';
const NJ_PAUTA_META_DRAFT_ID = '_nj_pauta_draft_post_id';
const NJ_PAUTA_FEED_STATE_OPTION = 'nj_pautas_feed_state';
const NJ_PAUTA_FEED_CATALOG_OPTION = 'nj_pautas_feed_catalog';
const NJ_PAUTA_LAST_REVIEW_OPTION = 'nj_pautas_last_review_at';

function nj_pautas_feed_state(PDO $pdo): array
{
    $options = nj_table('options');
    $statement = $pdo->prepare(
        "SELECT option_value
         FROM {$options}
         WHERE option_name = :name
         LIMIT 1"
    );
    $statement->execute(['name' => NJ_PAUTA_FEED_STATE_OPTION]);
    $raw = $statement->fetchColumn();

    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function nj_pautas_save_feed_state(PDO $pdo, array $state): void
{
    $options = nj_table('options');
    $encoded = json_encode(
        $state,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if (!is_string($encoded)) {
        throw new RuntimeException('feed_state_encode_failed');
    }

    $statement = $pdo->prepare(<<<SQL
INSERT INTO {$options} (option_name, option_value, autoload)
VALUES (:name, :value, 'no')
ON DUPLICATE KEY UPDATE
    option_value = VALUES(option_value),
    autoload = 'no'
SQL);
    $statement->execute([
        'name' => NJ_PAUTA_FEED_STATE_OPTION,
        'value' => $encoded,
    ]);
}

function nj_pautas_last_review_at(PDO $pdo): string
{
    $options = nj_table('options');
    $statement = $pdo->prepare(
        "SELECT option_value
         FROM {$options}
         WHERE option_name = :name
         LIMIT 1"
    );
    $statement->execute(['name' => NJ_PAUTA_LAST_REVIEW_OPTION]);
    $raw = $statement->fetchColumn();

    if (!is_string($raw) || trim($raw) === '') {
        return '';
    }

    try {
        return (new DateTimeImmutable($raw))->format(DATE_ATOM);
    } catch (Throwable) {
        return '';
    }
}

function nj_pautas_mark_reviewed(PDO $pdo): string
{
    $reviewedAt = gmdate('c');
    $options = nj_table('options');

    $statement = $pdo->prepare(<<<SQL
INSERT INTO {$options} (option_name, option_value, autoload)
VALUES (:name, :value, 'no')
ON DUPLICATE KEY UPDATE
    option_value = VALUES(option_value),
    autoload = 'no'
SQL);
    $statement->execute([
        'name' => NJ_PAUTA_LAST_REVIEW_OPTION,
        'value' => $reviewedAt,
    ]);

    return $reviewedAt;
}

function nj_pautas_feed_state_key(string $feedUrl): string
{
    return hash('sha256', trim($feedUrl));
}

function nj_pautas_feed_state_public(array $source, array $state): array
{
    $entry = $state[nj_pautas_feed_state_key((string) $source['feedUrl'])] ?? [];
    $lastAttemptAt = trim((string) ($entry['lastAttemptAt'] ?? ''));
    $lastSuccessAt = trim((string) ($entry['lastSuccessAt'] ?? ''));
    $consecutiveFailures = max(0, (int) ($entry['consecutiveFailures'] ?? 0));
    $status = (string) ($entry['status'] ?? 'never');

    if (!in_array($status, ['never', 'healthy', 'error'], true)) {
        $status = 'never';
    }

    return [
        'status' => $status,
        'lastAttemptAt' => $lastAttemptAt,
        'lastSuccessAt' => $lastSuccessAt,
        'lastHttpStatus' => max(0, (int) ($entry['lastHttpStatus'] ?? 0)),
        'lastDurationMs' => max(0, (int) ($entry['lastDurationMs'] ?? 0)),
        'lastCaptured' => max(0, (int) ($entry['lastCaptured'] ?? 0)),
        'totalCaptured' => max(0, (int) ($entry['totalCaptured'] ?? 0)),
        'consecutiveFailures' => $consecutiveFailures,
    ];
}

function nj_pautas_feed_url_shape(string $url): ?array
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 1000) {
        return null;
    }

    $parts = parse_url($url);
    if (!is_array($parts)) {
        return null;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    $port = isset($parts['port']) ? (int) $parts['port'] : 443;

    if (
        $scheme !== 'https'
        || $host === ''
        || $port !== 443
        || isset($parts['user'])
        || isset($parts['pass'])
    ) {
        return null;
    }

    if (
        $host === 'localhost'
        || str_ends_with($host, '.localhost')
        || preg_match('/[^a-z0-9.:-]/', $host) === 1
    ) {
        return null;
    }

    return [
        'url' => $url,
        'host' => $host,
    ];
}

function nj_pautas_public_ipv4(string $ip): bool
{
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

function nj_pautas_validate_feed_url(string $url): array
{
    $shape = nj_pautas_feed_url_shape($url);
    if (!is_array($shape)) {
        throw new NjApiHttpException(422, 'invalid_feed_url');
    }

    $host = (string) $shape['host'];
    $resolvedIp = '';

    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        if (!nj_pautas_public_ipv4($host)) {
            throw new NjApiHttpException(422, 'feed_host_not_public');
        }
        $resolvedIp = $host;
    } else {
        $ips = gethostbynamel($host);
        if (!is_array($ips) || $ips === []) {
            throw new NjApiHttpException(422, 'feed_host_unresolved');
        }

        foreach ($ips as $ip) {
            if (!nj_pautas_public_ipv4((string) $ip)) {
                throw new NjApiHttpException(422, 'feed_host_not_public');
            }
        }

        $resolvedIp = (string) $ips[0];
    }

    return [
        'url' => (string) $shape['url'],
        'host' => $host,
        'ip' => $resolvedIp,
    ];
}

function nj_pautas_default_sources(): array
{
    $sources = [
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

    return array_map(
        static function (array $source): array {
            $source['id'] = 'default-' . substr(hash('sha256', (string) $source['feedUrl']), 0, 16);
            $source['enabled'] = true;
            return $source;
        },
        $sources
    );
}

function nj_pautas_catalog_source(array $source): ?array
{
    $id = trim((string) ($source['id'] ?? ''));
    $name = trim((string) ($source['name'] ?? ''));
    $category = trim((string) ($source['category'] ?? ''));
    $feedUrl = trim((string) ($source['feedUrl'] ?? ''));
    $kind = trim((string) ($source['kind'] ?? ''));
    $allowedKinds = ['fonte primária', 'jornalística', 'agregador', 'radar'];
    if (!in_array($kind, $allowedKinds, true)) {
        $kind = 'jornalística';
    }
    $priority = max(0, min(100, (int) ($source['priority'] ?? 70)));
    $enabled = ($source['enabled'] ?? true) === true;

    if (
        $id === ''
        || preg_match('/^[a-z0-9-]{8,80}$/', $id) !== 1
        || $name === ''
        || strlen($name) > 250
        || strlen($category) > 160
        || strlen($kind) > 120
        || !is_array(nj_pautas_feed_url_shape($feedUrl))
    ) {
        return null;
    }

    return [
        'id' => $id,
        'name' => $name,
        'category' => $category,
        'feedUrl' => $feedUrl,
        'kind' => $kind !== '' ? $kind : 'jornalística',
        'priority' => $priority,
        'enabled' => $enabled,
    ];
}

function nj_pautas_sources(PDO $pdo): array
{
    $options = nj_table('options');
    $statement = $pdo->prepare(
        "SELECT option_value
         FROM {$options}
         WHERE option_name = :name
         LIMIT 1"
    );
    $statement->execute(['name' => NJ_PAUTA_FEED_CATALOG_OPTION]);
    $raw = $statement->fetchColumn();

    if (!is_string($raw) || trim($raw) === '') {
        return nj_pautas_default_sources();
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return nj_pautas_default_sources();
    }

    $sources = [];
    foreach ($decoded as $source) {
        if (!is_array($source)) {
            continue;
        }

        $normalized = nj_pautas_catalog_source($source);
        if (is_array($normalized)) {
            $sources[] = $normalized;
        }

        if (count($sources) >= 100) {
            break;
        }
    }

    return $sources;
}

function nj_pautas_save_sources(PDO $pdo, array $sources): void
{
    if (count($sources) > 100) {
        throw new NjApiHttpException(422, 'too_many_feeds');
    }

    $normalized = [];
    foreach ($sources as $source) {
        if (!is_array($source)) {
            continue;
        }

        $item = nj_pautas_catalog_source($source);
        if (!is_array($item)) {
            throw new NjApiHttpException(422, 'invalid_feed_catalog');
        }

        $normalized[] = $item;
    }

    $encoded = json_encode(
        $normalized,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if (!is_string($encoded)) {
        throw new RuntimeException('feed_catalog_encode_failed');
    }

    $options = nj_table('options');
    $statement = $pdo->prepare(<<<SQL
INSERT INTO {$options} (option_name, option_value, autoload)
VALUES (:name, :value, 'no')
ON DUPLICATE KEY UPDATE
    option_value = VALUES(option_value),
    autoload = 'no'
SQL);
    $statement->execute([
        'name' => NJ_PAUTA_FEED_CATALOG_OPTION,
        'value' => $encoded,
    ]);
}

function nj_pautas_sources_with_health(PDO $pdo): array
{
    $state = nj_pautas_feed_state($pdo);

    return array_map(
        static function (array $source) use ($state): array {
            $source['health'] = nj_pautas_feed_state_public($source, $state);
            return $source;
        },
        nj_pautas_sources($pdo)
    );
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

function nj_pautas_fetch_feed(string $url): array
{
    $target = nj_pautas_validate_feed_url($url);
    $url = (string) $target['url'];

    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new NjApiHttpException(502, 'feed_fetch_failed');
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'NossoJornalEditorial/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/rss+xml, application/atom+xml, application/xml, text/xml'],
        ]);

        if (defined('CURLOPT_RESOLVE')) {
            curl_setopt(
                $curl,
                CURLOPT_RESOLVE,
                [(string) $target['host'] . ':443:' . (string) $target['ip']]
            );
        }

        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }

        $startedAt = microtime(true);
        $body = curl_exec($curl);
        $durationMs = max(0, (int) round((microtime(true) - $startedAt) * 1000));
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if (!is_string($body) || $body === '' || $status < 200 || $status >= 400) {
            error_log('[nossojornal-pautas] feed=' . $url . ' status=' . $status . ' error=' . $error);
            throw new NjApiHttpException(502, 'feed_fetch_failed');
        }

        if (strlen($body) > 2 * 1024 * 1024) {
            throw new NjApiHttpException(422, 'feed_too_large');
        }

        return [
            'body' => $body,
            'httpStatus' => $status,
            'durationMs' => $durationMs,
        ];
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'follow_location' => 0,
            'max_redirects' => 0,
            'user_agent' => 'NossoJornalEditorial/1.0',
            'header' => "Accept: application/rss+xml, application/atom+xml, application/xml, text/xml\r\n",
        ],
    ]);

    $startedAt = microtime(true);
    $body = @file_get_contents($url, false, $context);
    $durationMs = max(0, (int) round((microtime(true) - $startedAt) * 1000));

    if (!is_string($body) || $body === '') {
        throw new NjApiHttpException(502, 'feed_fetch_failed');
    }

    if (strlen($body) > 2 * 1024 * 1024) {
        throw new NjApiHttpException(422, 'feed_too_large');
    }

    $status = 200;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#i', (string) $header, $match) === 1) {
            $status = (int) $match[1];
        }
    }

    if ($status < 200 || $status >= 400) {
        throw new NjApiHttpException(502, 'feed_fetch_failed');
    }

    return [
        'body' => $body,
        'httpStatus' => $status,
        'durationMs' => $durationMs,
    ];
}

function nj_pautas_feed_datetime(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    try {
        return (new DateTimeImmutable($value))->format(DATE_ATOM);
    } catch (Throwable) {
        return '';
    }
}

function nj_pautas_parse_feed(string $xmlBody): array
{
    if (!function_exists('simplexml_load_string')) {
        throw new NjApiHttpException(500, 'feed_parser_unavailable');
    }

    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlBody, SimpleXMLElement::class, LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$xml instanceof SimpleXMLElement) {
        throw new NjApiHttpException(422, 'invalid_feed');
    }

    $items = [];

    if (isset($xml->channel->item)) {
        foreach ($xml->channel->item as $item) {
            $title = trim((string) $item->title);
            $link = trim((string) $item->link);
            $description = trim((string) $item->description);
            $guid = isset($item->guid) ? trim((string) $item->guid) : '';
            $publishedAt = nj_pautas_feed_datetime((string) ($item->pubDate ?? ''));

            if ($link === '' && $guid !== '' && filter_var($guid, FILTER_VALIDATE_URL)) {
                $link = $guid;
            }

            if ($title === '' || !filter_var($link, FILTER_VALIDATE_URL)) {
                continue;
            }

            $items[] = [
                'title' => html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'link' => $link,
                'description' => trim(html_entity_decode(strip_tags($description), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                'externalId' => $guid !== '' ? $guid : $link,
                'publishedAt' => $publishedAt,
            ];

            if (count($items) >= 12) {
                break;
            }
        }
    } else {
        $entries = $xml->xpath('//*[local-name()="entry"]') ?: [];

        foreach ($entries as $entry) {
            $titleNodes = $entry->xpath('./*[local-name()="title"]') ?: [];
            $summaryNodes = $entry->xpath('./*[local-name()="summary" or local-name()="content"]') ?: [];
            $linkNodes = $entry->xpath('./*[local-name()="link"]') ?: [];
            $idNodes = $entry->xpath('./*[local-name()="id"]') ?: [];
            $publishedNodes = $entry->xpath('./*[local-name()="published" or local-name()="updated"]') ?: [];

            $title = trim((string) ($titleNodes[0] ?? ''));
            $summary = trim((string) ($summaryNodes[0] ?? ''));
            $externalId = trim((string) ($idNodes[0] ?? ''));
            $publishedAt = nj_pautas_feed_datetime((string) ($publishedNodes[0] ?? ''));
            $link = '';

            foreach ($linkNodes as $linkNode) {
                $attributes = $linkNode->attributes();
                $candidate = trim((string) ($attributes['href'] ?? ''));
                $rel = trim((string) ($attributes['rel'] ?? 'alternate'));

                if ($candidate !== '' && ($rel === '' || $rel === 'alternate')) {
                    $link = $candidate;
                    break;
                }
            }

            if ($title === '' || !filter_var($link, FILTER_VALIDATE_URL)) {
                continue;
            }

            $items[] = [
                'title' => html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'link' => $link,
                'description' => trim(html_entity_decode(strip_tags($summary), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                'externalId' => $externalId !== '' ? $externalId : $link,
                'publishedAt' => $publishedAt,
            ];

            if (count($items) >= 12) {
                break;
            }
        }
    }

    return $items;
}

function nj_pautas_capture(PDO $pdo, array $user, array $source): array
{
    $posts = nj_table('posts');
    $postmeta = nj_table('postmeta');
    $feedResponse = nj_pautas_fetch_feed((string) $source['feedUrl']);
    $feedItems = nj_pautas_parse_feed((string) $feedResponse['body']);
    $captured = 0;

    $duplicate = $pdo->prepare(<<<SQL
SELECT DISTINCT p.ID
FROM {$posts} p
INNER JOIN {$postmeta} pm
    ON pm.post_id = p.ID
WHERE
    p.post_type = 'nj_pauta'
    AND (
        (pm.meta_key = '_nj_pauta_source_url' AND pm.meta_value = :source_url)
        OR (pm.meta_key = '_nj_pauta_external_id' AND pm.meta_value = :external_id)
        OR (pm.meta_key = '_nj_pauta_source_hash' AND pm.meta_value = :source_hash)
    )
LIMIT 1
SQL);

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

    $numericPriority = (int) ($source['priority'] ?? 70);
    $priority = $numericPriority >= 95
        ? 'urgent'
        : ($numericPriority >= 80 ? 'high' : ($numericPriority >= 60 ? 'normal' : 'low'));

    foreach ($feedItems as $feedItem) {
        $externalId = trim((string) ($feedItem['externalId'] ?? ''));
        $sourceHash = hash('sha256', implode("\n", [
            $externalId,
            (string) $feedItem['link'],
            (string) $feedItem['title'],
            (string) $feedItem['description'],
            (string) ($feedItem['publishedAt'] ?? ''),
        ]));

        $duplicate->execute([
            'source_url' => (string) $feedItem['link'],
            'external_id' => $externalId,
            'source_hash' => $sourceHash,
        ]);

        if ($duplicate->fetchColumn()) {
            continue;
        }

        $insert->execute([
            'author_id' => (int) $user['id'],
            'notes' => substr((string) $feedItem['description'], 0, 6000),
            'title' => substr((string) $feedItem['title'], 0, 500),
        ]);

        $pautaId = (int) $pdo->lastInsertId();

        if ($pautaId <= 0) {
            continue;
        }

        nj_admin_upsert_postmeta($pdo, $pautaId, NJ_PAUTA_META_STAGE, 'inbox');
        nj_admin_upsert_postmeta($pdo, $pautaId, NJ_PAUTA_META_PRIORITY, $priority);
        $capturedAt = gmdate('c');

        nj_admin_upsert_postmeta($pdo, $pautaId, NJ_PAUTA_META_TOPIC, (string) $source['category']);
        nj_admin_upsert_postmeta($pdo, $pautaId, NJ_PAUTA_META_SOURCE_NAME, (string) $source['name']);
        nj_admin_upsert_postmeta($pdo, $pautaId, NJ_PAUTA_META_SOURCE_URL, (string) $feedItem['link']);
        nj_admin_upsert_postmeta($pdo, $pautaId, NJ_PAUTA_META_FEED_URL, (string) $source['feedUrl']);
        nj_admin_upsert_postmeta($pdo, $pautaId, NJ_PAUTA_META_EXTERNAL_ID, $externalId);
        nj_admin_upsert_postmeta($pdo, $pautaId, NJ_PAUTA_META_SOURCE_PUBLISHED_AT, (string) ($feedItem['publishedAt'] ?? ''));
        nj_admin_upsert_postmeta($pdo, $pautaId, NJ_PAUTA_META_CAPTURED_AT, $capturedAt);
        nj_admin_upsert_postmeta($pdo, $pautaId, NJ_PAUTA_META_SOURCE_HASH, $sourceHash);
        nj_admin_upsert_postmeta($pdo, $pautaId, NJ_PAUTA_META_ASSIGNEE, (string) $user['id']);

        $captured++;
    }

    return [
        'captured' => $captured,
        'httpStatus' => max(0, (int) ($feedResponse['httpStatus'] ?? 0)),
        'durationMs' => max(0, (int) ($feedResponse['durationMs'] ?? 0)),
    ];
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
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_pauta_feed_url'
        ORDER BY pm.meta_id DESC LIMIT 1
    ), '') AS feed_url,
    COALESCE((
        SELECT pm.meta_value FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_pauta_source_published_at'
        ORDER BY pm.meta_id DESC LIMIT 1
    ), '') AS source_published_at,
    COALESCE((
        SELECT pm.meta_value FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_pauta_captured_at'
        ORDER BY pm.meta_id DESC LIMIT 1
    ), '') AS captured_at,
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

    $lastReviewAt = nj_pautas_last_review_at($pdo);
    $lastReviewTimestamp = $lastReviewAt !== '' ? strtotime($lastReviewAt) : false;

    $items = [];
    foreach ($rows as $row) {
        $capturedAt = trim((string) $row['captured_at']);
        $effectiveArrival = $capturedAt !== ''
            ? $capturedAt
            : (string) $row['created_at'];
        $arrivalTimestamp = strtotime($effectiveArrival);
        $isNew = $lastReviewTimestamp === false
            ? true
            : ($arrivalTimestamp !== false && $arrivalTimestamp > $lastReviewTimestamp);

        $items[] = [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'notes' => (string) $row['notes'],
            'stage' => (string) $row['stage'],
            'priority' => (string) $row['priority'],
            'topic' => (string) $row['topic'],
            'sourceName' => (string) $row['source_name'],
            'sourceUrl' => (string) $row['source_url'],
            'feedUrl' => (string) $row['feed_url'],
            'sourcePublishedAt' => (string) $row['source_published_at'],
            'capturedAt' => $capturedAt,
            'isNew' => $isNew,
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
            'sources' => nj_pautas_sources_with_health($pdo),
            'items' => nj_pautas_items($pdo),
            'lastReviewAt' => nj_pautas_last_review_at($pdo),
            'assignees' => nj_pautas_assignees($pdo),
        ];
    }

    nj_admin_require_csrf();
    $body = nj_admin_request_body();
    $action = trim((string) ($body['action'] ?? 'save'));
    $posts = nj_table('posts');

    if (!in_array(
        $action,
        ['save', 'trash', 'to_draft', 'capture', 'mark_reviewed', 'feed_save', 'feed_delete'],
        true
    )) {
        throw new NjApiHttpException(422, 'invalid_pauta_action');
    }

    $pautaId = max(0, (int) ($body['pautaId'] ?? 0));

    if ($action === 'feed_save') {
        $input = is_array($body['feed'] ?? null) ? $body['feed'] : [];
        $feedId = trim((string) ($input['id'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        $category = trim((string) ($input['category'] ?? ''));
        $kind = trim((string) ($input['kind'] ?? 'jornalística'));
        if (!in_array($kind, ['fonte primária', 'jornalística', 'agregador', 'radar'], true)) {
            throw new NjApiHttpException(422, 'invalid_feed_kind');
        }
        $priority = max(0, min(100, (int) ($input['priority'] ?? 70)));
        $enabled = ($input['enabled'] ?? true) === true;
        $target = nj_pautas_validate_feed_url((string) ($input['feedUrl'] ?? ''));
        $feedUrl = (string) $target['url'];

        if (
            $name === ''
            || strlen($name) > 250
            || strlen($category) > 160
            || strlen($kind) > 120
        ) {
            throw new NjApiHttpException(422, 'invalid_feed_definition');
        }

        $sources = nj_pautas_sources($pdo);
        $existingIndex = null;

        foreach ($sources as $index => $source) {
            if (
                (string) $source['feedUrl'] === $feedUrl
                && ($feedId === '' || (string) $source['id'] !== $feedId)
            ) {
                throw new NjApiHttpException(409, 'feed_already_exists');
            }

            if ($feedId !== '' && hash_equals((string) $source['id'], $feedId)) {
                $existingIndex = $index;
            }
        }

        if ($feedId !== '' && $existingIndex === null) {
            throw new NjApiHttpException(404, 'feed_not_found');
        }

        if ($feedId === '') {
            $feedId = 'feed-' . bin2hex(random_bytes(8));
        }

        $definition = [
            'id' => $feedId,
            'name' => $name,
            'category' => $category,
            'feedUrl' => $feedUrl,
            'kind' => $kind !== '' ? $kind : 'jornalística',
            'priority' => $priority,
            'enabled' => $enabled,
        ];

        if ($existingIndex === null) {
            $sources[] = $definition;
        } else {
            $oldUrl = (string) $sources[$existingIndex]['feedUrl'];
            $sources[$existingIndex] = $definition;

            if ($oldUrl !== $feedUrl) {
                $state = nj_pautas_feed_state($pdo);
                unset($state[nj_pautas_feed_state_key($oldUrl)]);
                nj_pautas_save_feed_state($pdo, $state);
            }
        }

        nj_pautas_save_sources($pdo, $sources);

        return [
            'sources' => nj_pautas_sources_with_health($pdo),
            'items' => nj_pautas_items($pdo),
            'lastReviewAt' => nj_pautas_last_review_at($pdo),
            'draft' => null,
        ];
    }

    if ($action === 'feed_delete') {
        $feedId = trim((string) ($body['feedId'] ?? ''));
        if ($feedId === '') {
            throw new NjApiHttpException(422, 'feed_id_required');
        }

        $sources = nj_pautas_sources($pdo);
        $remaining = [];
        $deletedUrl = '';

        foreach ($sources as $source) {
            if (hash_equals((string) $source['id'], $feedId)) {
                $deletedUrl = (string) $source['feedUrl'];
                continue;
            }
            $remaining[] = $source;
        }

        if ($deletedUrl === '') {
            throw new NjApiHttpException(404, 'feed_not_found');
        }

        nj_pautas_save_sources($pdo, $remaining);

        $state = nj_pautas_feed_state($pdo);
        unset($state[nj_pautas_feed_state_key($deletedUrl)]);
        nj_pautas_save_feed_state($pdo, $state);

        return [
            'sources' => nj_pautas_sources_with_health($pdo),
            'items' => nj_pautas_items($pdo),
            'lastReviewAt' => nj_pautas_last_review_at($pdo),
            'draft' => null,
        ];
    }

    if ($action === 'mark_reviewed') {
        $lastReviewAt = nj_pautas_mark_reviewed($pdo);

        return [
            'items' => nj_pautas_items($pdo),
            'lastReviewAt' => $lastReviewAt,
            'draft' => null,
        ];
    }

    if ($action === 'capture') {
        $feedUrl = trim((string) ($body['feedUrl'] ?? ''));

        if ($feedUrl === '') {
            throw new NjApiHttpException(422, 'feed_required');
        }

        $selectedSource = null;
        foreach (nj_pautas_sources($pdo) as $source) {
            if (hash_equals((string) $source['feedUrl'], $feedUrl)) {
                $selectedSource = $source;
                break;
            }
        }

        if (!is_array($selectedSource)) {
            throw new NjApiHttpException(422, 'feed_not_allowed');
        }

        if (($selectedSource['enabled'] ?? false) !== true) {
            throw new NjApiHttpException(422, 'feed_disabled');
        }

        $feedUrl = (string) $selectedSource['feedUrl'];
        $state = nj_pautas_feed_state($pdo);
        $stateKey = nj_pautas_feed_state_key($feedUrl);
        $previous = is_array($state[$stateKey] ?? null) ? $state[$stateKey] : [];
        $attemptAt = gmdate('c');

        try {
            $capture = nj_pautas_capture($pdo, $user, $selectedSource);
            $captured = max(0, (int) ($capture['captured'] ?? 0));

            $state[$stateKey] = [
                'status' => 'healthy',
                'lastAttemptAt' => $attemptAt,
                'lastSuccessAt' => $attemptAt,
                'lastHttpStatus' => max(0, (int) ($capture['httpStatus'] ?? 0)),
                'lastDurationMs' => max(0, (int) ($capture['durationMs'] ?? 0)),
                'lastCaptured' => $captured,
                'totalCaptured' => max(0, (int) ($previous['totalCaptured'] ?? 0)) + $captured,
                'consecutiveFailures' => 0,
            ];
            nj_pautas_save_feed_state($pdo, $state);
        } catch (Throwable $error) {
            $state[$stateKey] = [
                'status' => 'error',
                'lastAttemptAt' => $attemptAt,
                'lastSuccessAt' => (string) ($previous['lastSuccessAt'] ?? ''),
                'lastHttpStatus' => 0,
                'lastDurationMs' => 0,
                'lastCaptured' => 0,
                'totalCaptured' => max(0, (int) ($previous['totalCaptured'] ?? 0)),
                'consecutiveFailures' => max(0, (int) ($previous['consecutiveFailures'] ?? 0)) + 1,
            ];
            nj_pautas_save_feed_state($pdo, $state);
            throw $error;
        }

        return [
            'items' => nj_pautas_items($pdo),
            'sources' => nj_pautas_sources_with_health($pdo),
            'lastReviewAt' => nj_pautas_last_review_at($pdo),
            'captured' => $captured,
            'captureFailures' => 0,
            'draft' => null,
        ];
    }

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
    COALESCE((SELECT pm.meta_value FROM {$postmeta} pm WHERE pm.post_id=p.ID AND pm.meta_key='_nj_pauta_feed_url' ORDER BY pm.meta_id DESC LIMIT 1), '') AS feed_url,
    COALESCE((SELECT pm.meta_value FROM {$postmeta} pm WHERE pm.post_id=p.ID AND pm.meta_key='_nj_pauta_external_id' ORDER BY pm.meta_id DESC LIMIT 1), '') AS external_id,
    COALESCE((SELECT pm.meta_value FROM {$postmeta} pm WHERE pm.post_id=p.ID AND pm.meta_key='_nj_pauta_source_published_at' ORDER BY pm.meta_id DESC LIMIT 1), '') AS source_published_at,
    COALESCE((SELECT pm.meta_value FROM {$postmeta} pm WHERE pm.post_id=p.ID AND pm.meta_key='_nj_pauta_captured_at' ORDER BY pm.meta_id DESC LIMIT 1), '') AS captured_at,
    COALESCE((SELECT pm.meta_value FROM {$postmeta} pm WHERE pm.post_id=p.ID AND pm.meta_key='_nj_pauta_source_hash' ORDER BY pm.meta_id DESC LIMIT 1), '') AS source_hash,
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

            $hasExternalOrigin = trim((string) $pauta['source_url']) !== '';
            nj_admin_upsert_postmeta(
                $pdo,
                $draftId,
                NJ_PROVENANCE_META_MODE,
                $hasExternalOrigin ? 'adapted' : 'original'
            );
            nj_admin_upsert_postmeta($pdo, $draftId, NJ_PROVENANCE_META_SOURCE_NAME, (string) $pauta['source_name']);
            nj_admin_upsert_postmeta($pdo, $draftId, NJ_PROVENANCE_META_SOURCE_URL, (string) $pauta['source_url']);
            nj_admin_upsert_postmeta($pdo, $draftId, NJ_PROVENANCE_META_EXTERNAL_ID, (string) $pauta['external_id']);
            nj_admin_upsert_postmeta($pdo, $draftId, NJ_PROVENANCE_META_FEED_URL, (string) $pauta['feed_url']);
            nj_admin_upsert_postmeta($pdo, $draftId, NJ_PROVENANCE_META_CAPTURED_AT, (string) $pauta['captured_at']);
            nj_admin_upsert_postmeta(
                $pdo,
                $draftId,
                NJ_PROVENANCE_META_SOURCE_PUBLISHED_AT,
                (string) $pauta['source_published_at']
            );
            nj_admin_upsert_postmeta($pdo, $draftId, NJ_PROVENANCE_META_SOURCE_HASH, (string) $pauta['source_hash']);
            nj_admin_upsert_postmeta($pdo, $draftId, NJ_PROVENANCE_META_PAUTA_ID, (string) $pautaId);

            if ($hasExternalOrigin) {
                // Attribution is inherited, but canonical remains self by default.
                // An editor must explicitly choose an external canonical later.
                nj_admin_upsert_postmeta($pdo, $draftId, '_nj_original_source_url', (string) $pauta['source_url']);
            }

            nj_admin_log_post_activity(
                $pdo,
                $draftId,
                (int) $user['id'],
                'pauta_converted_to_draft',
                [
                    'pautaId' => $pautaId,
                    'provenanceMode' => $hasExternalOrigin ? 'adapted' : 'original',
                    'sourceName' => (string) $pauta['source_name'],
                    'hasExternalId' => trim((string) $pauta['external_id']) !== '',
                    'hasSourceHash' => trim((string) $pauta['source_hash']) !== '',
                ]
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
