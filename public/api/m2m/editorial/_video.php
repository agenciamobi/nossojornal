<?php
declare(strict_types=1);

/** Safe YouTube player IDs. Never embed arbitrary user-controlled iframe URLs. */
function nj_video_youtube_id(string $raw): string
{
    if ($raw === '' || strlen($raw) > 320 || preg_match('/[\x00-\x20\x7f]/', $raw)) {
        throw new NjApiHttpException(422, 'video_url_invalid');
    }
    $parts = parse_url($raw);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
        || isset($parts['fragment'])
    ) {
        throw new NjApiHttpException(422, 'video_url_invalid');
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = (string) ($parts['path'] ?? '');
    $query = (string) ($parts['query'] ?? '');
    $id = '';
    if (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'], true)
        && $path === '/watch') {
        parse_str($query, $params);
        if (count($params) === 1 && is_string($params['v'] ?? null)) $id = $params['v'];
    } elseif (in_array($host, ['youtube.com', 'www.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'], true)
        && preg_match('#^/(?:embed|shorts)/([A-Za-z0-9_-]{11})$#', $path, $match) && $query === '') {
        $id = $match[1];
    } elseif ($host === 'youtu.be' && preg_match('#^/([A-Za-z0-9_-]{11})$#', $path, $match) && $query === '') {
        $id = $match[1];
    }
    if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
        throw new NjApiHttpException(422, 'video_provider_not_supported');
    }
    return $id;
}

function nj_video_source_page(string $raw, string $videoId): string
{
    if ($raw === '' || strlen($raw) > 1200) throw new NjApiHttpException(422, 'video_source_invalid');
    $parts = parse_url($raw);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
        || isset($parts['fragment'])
    ) throw new NjApiHttpException(422, 'video_source_invalid');
    $host = strtolower((string) ($parts['host'] ?? ''));
    if (in_array($host, ['esawebb.org', 'www.esawebb.org'], true)
        && (string) ($parts['path'] ?? '') === '/videos/potm2609a/'
        && $videoId === 'RQUMlfUnPhc') {
        return 'https://esawebb.org/videos/potm2609a/';
    }
    // Non-ESA uploads use their video provider page as the declared source.
    // Arbitrary ESA pages cannot be paired with unrelated videos.
    if (in_array($host, ['youtube.com', 'www.youtube.com'], true)
        && (string) ($parts['path'] ?? '') === '/watch') {
        parse_str((string) ($parts['query'] ?? ''), $parameters);
        if (($parameters['v'] ?? '') === $videoId && count($parameters) === 1) {
            return 'https://www.youtube.com/watch?v=' . $videoId;
        }
    }
    throw new NjApiHttpException(422, 'video_source_mismatch');
}
