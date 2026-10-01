<?php
declare(strict_types=1);

/**
 * Authenticated, bounded, server-to-server import of approved editorial images.
 * The user/model never selects a filesystem path or receives a provider credential.
 * Only an existing DRAFT may be mutated; this endpoint never publishes content.
 */
require __DIR__ . '/_draft.php';

const NJ_MEDIA_REMOTE_LIMIT = 8 * 1024 * 1024;
const NJ_MEDIA_APPROVED_HOSTS = ['cdn.esawebb.org', 'cdn2.esawebb.org'];

function nj_remote_image_url(string $raw): string
{
    if ($raw === '' || strlen($raw) > 1200 || preg_match('/[\x00-\x20\x7f]/', $raw)) {
        throw new NjApiHttpException(422, 'media_url_invalid');
    }
    $parts = parse_url($raw);
    if (!is_array($parts)
        || ($parts['scheme'] ?? '') !== 'https'
        || !in_array(strtolower((string) ($parts['host'] ?? '')), NJ_MEDIA_APPROVED_HOSTS, true)
        || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])
        || preg_match('#^/archives/images/(?:screen|large|publication)/[a-zA-Z0-9_-]+\.(?:jpe?g|png|webp)$#', (string) ($parts['path'] ?? '')) !== 1
    ) {
        throw new NjApiHttpException(422, 'media_source_not_allowed');
    }
    return $raw;
}

function nj_remote_source_page(string $raw): string
{
    $parts = parse_url($raw);
    if (!is_array($parts)
        || ($parts['scheme'] ?? '') !== 'https'
        || !in_array(strtolower((string) ($parts['host'] ?? '')), ['esawebb.org', 'www.esawebb.org'], true)
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
        || isset($parts['query']) || isset($parts['fragment'])
        || preg_match('#^/images/[a-zA-Z0-9_-]+/?$#', (string) ($parts['path'] ?? '')) !== 1
    ) {
        throw new NjApiHttpException(422, 'media_source_page_invalid');
    }
    return $raw;
}

function nj_remote_download_image(string $url): array
{
    if (!function_exists('curl_init') || !function_exists('gethostbynamel')) {
        throw new NjApiHttpException(503, 'media_download_runtime_unavailable');
    }

    $hostname = (string) parse_url($url, PHP_URL_HOST);
    // Pin the DNS result to the TLS host: no redirects, proxies or client-selected IPs.
    $addresses = gethostbynamel($hostname);
    if (!is_array($addresses) || $addresses === []) {
        throw new NjApiHttpException(502, 'media_source_dns_failed');
    }
    foreach ($addresses as $address) {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new NjApiHttpException(422, 'media_source_dns_unsafe');
        }
    }
    $address = $addresses[0];
    $temp = tempnam(sys_get_temp_dir(), 'nj-media-');
    if ($temp === false) {
        throw new NjApiHttpException(503, 'media_temporary_storage_unavailable');
    }
    $out = fopen($temp, 'wb');
    if ($out === false) {
        @unlink($temp);
        throw new NjApiHttpException(503, 'media_temporary_storage_unavailable');
    }

    $bytes = 0;
    $overLimit = false;
    $ch = curl_init($url);
    if ($ch === false) {
        fclose($out);
        @unlink($temp);
        throw new NjApiHttpException(503, 'media_download_runtime_unavailable');
    }
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROXY => '',
        CURLOPT_RESOLVE => [$hostname . ':443:' . $address],
        CURLOPT_USERAGENT => 'NossoJornal-EditorialMedia/1.0',
        CURLOPT_HTTPHEADER => ['Accept: image/jpeg, image/png, image/webp'],
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($out, &$bytes, &$overLimit): int {
            $length = strlen($chunk);
            if ($bytes + $length > NJ_MEDIA_REMOTE_LIMIT) {
                $overLimit = true;
                return 0;
            }
            $written = fwrite($out, $chunk);
            if (!is_int($written) || $written !== $length) {
                return 0;
            }
            $bytes += $length;
            return $length;
        },
    ]);
    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    fclose($out);

    if ($overLimit || $bytes === 0 || $ok === false || $status !== 200) {
        @unlink($temp);
        throw new NjApiHttpException($overLimit ? 413 : 502, $overLimit ? 'media_remote_too_large' : 'media_remote_download_failed');
    }

    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($temp);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $dimensions = @getimagesize($temp);
    if (!isset($extensions[$mime]) || !is_array($dimensions)
        || (int) ($dimensions[0] ?? 0) < 1 || (int) ($dimensions[1] ?? 0) < 1
        || (int) $dimensions[0] > 10000 || (int) $dimensions[1] > 10000
    ) {
        @unlink($temp);
        throw new NjApiHttpException(422, 'media_remote_invalid_image');
    }
    return [
        'temp' => $temp,
        'bytes' => $bytes,
        'hash' => hash_file('sha256', $temp),
        'mime' => $mime,
        'extension' => $extensions[$mime],
        'width' => (int) $dimensions[0],
        'height' => (int) $dimensions[1],
    ];
}

function nj_remote_existing_attachment(PDO $pdo, string $urlHash): ?array
{
    $posts = nj_table('posts');
    $meta = nj_table('postmeta');
    $query = $pdo->prepare(
        "SELECT p.ID AS id, p.guid, p.post_mime_type AS mime
         FROM {$posts} p
         INNER JOIN {$meta} m ON m.post_id = p.ID
         WHERE p.post_type = 'attachment'
           AND p.post_mime_type LIKE 'image/%'
           AND m.meta_key = '_nj_remote_media_source_sha256'
           AND m.meta_value = :source_hash
         ORDER BY p.ID ASC LIMIT 1"
    );
    $query->execute(['source_hash' => $urlHash]);
    $found = $query->fetch();
    return is_array($found) ? $found : null;
}

nj_m2m_run('POST', 'editorial.media.import', static function (array $context): array {
    $body = nj_m2m_body($context);
    $allowed = ['post_id', 'asset_url', 'source_url', 'title', 'alt', 'caption', 'credit', 'set_featured'];
    if (array_diff(array_keys($body), $allowed) !== []) {
        throw new NjApiHttpException(422, 'body_field_not_allowed');
    }

    $postId = filter_var($body['post_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $title = trim((string) ($body['title'] ?? ''));
    $alt = trim((string) ($body['alt'] ?? ''));
    $caption = trim((string) ($body['caption'] ?? ''));
    $credit = trim((string) ($body['credit'] ?? ''));
    if (!is_int($postId) || $postId <= 0
        || $title === '' || mb_strlen($title, 'UTF-8') > 160
        || $alt === '' || mb_strlen($alt, 'UTF-8') > 500
        || mb_strlen($caption, 'UTF-8') > 1000
        || $credit === '' || mb_strlen($credit, 'UTF-8') > 1000
        || !is_bool($body['set_featured'] ?? true)
    ) {
        throw new NjApiHttpException(422, 'media_import_payload_invalid');
    }

    $url = nj_remote_image_url(trim((string) ($body['asset_url'] ?? '')));
    $sourceUrl = nj_remote_source_page(trim((string) ($body['source_url'] ?? '')));
    $assetName = pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME);
    $pageSlug = trim((string) parse_url($sourceUrl, PHP_URL_PATH), '/');
    if ($assetName === '' || $pageSlug !== 'images/' . $assetName) {
        throw new NjApiHttpException(422, 'media_source_mismatch');
    }
    $sourceHash = hash('sha256', $url);
    $featured = $body['set_featured'] ?? true;
    $pdo = $context['pdo'];
    $posts = nj_table('posts');

    $postQuery = $pdo->prepare("SELECT ID, post_author, post_status FROM {$posts} WHERE ID = :id AND post_type = 'post' LIMIT 1");
    $postQuery->execute(['id' => $postId]);
    $post = $postQuery->fetch();
    if (!is_array($post) || $post['post_status'] !== 'draft') {
        throw new NjApiHttpException(409, 'media_import_draft_required');
    }

    $documentRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    if ($documentRoot === '') {
        throw new NjApiHttpException(503, 'media_storage_unavailable');
    }
    $uploadRoot = $documentRoot . '/wp-content/uploads';
    $existing = nj_remote_existing_attachment($pdo, $sourceHash);
    $download = null;
    $createdPath = null;
    $reused = is_array($existing);
    if ($existing !== null) {
        $metaTable = nj_table('postmeta');
        $fileQuery = $pdo->prepare("SELECT meta_value FROM {$metaTable} WHERE post_id = :attachment AND meta_key = '_wp_attached_file' ORDER BY meta_id DESC LIMIT 1");
        $fileQuery->execute(['attachment' => (int) $existing['id']]);
        $relative = (string) $fileQuery->fetchColumn();
        if (!preg_match('#^[0-9]{4}/[0-9]{2}/[a-z0-9._-]+$#i', $relative)
            || !is_file($uploadRoot . '/' . $relative)
            || is_link($uploadRoot . '/' . $relative)
        ) {
            throw new NjApiHttpException(409, 'existing_media_file_missing');
        }
    }
    if ($existing === null) {
        $download = nj_remote_download_image($url);
    }

    try {
        $pdo->beginTransaction();
        // Revalidate the draft under lock after the remote download.
        $postLock = $pdo->prepare("SELECT ID FROM {$posts} WHERE ID = :id AND post_type = 'post' AND post_status = 'draft' LIMIT 1 FOR UPDATE");
        $postLock->execute(['id' => $postId]);
        if (!$postLock->fetch()) {
            throw new NjApiHttpException(409, 'media_import_draft_changed');
        }

        $attachment = nj_remote_existing_attachment($pdo, $sourceHash);
        $attachmentId = is_array($attachment) ? (int) $attachment['id'] : 0;
        $reused = $attachmentId > 0;
        if ($attachmentId === 0) {
            if (!is_array($download)) {
                throw new RuntimeException('media_download_missing');
            }
            $now = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
            $month = $now->format('Y/m');
            $targetDir = $uploadRoot . '/' . $month;
            if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                throw new NjApiHttpException(503, 'media_storage_unavailable');
            }
            if (!is_writable($targetDir)) {
                throw new NjApiHttpException(503, 'media_storage_unavailable');
            }
            $base = substr(nj_admin_slugify($title), 0, 72);
            $base = ($base === '' ? 'imagem' : $base) . '-' . substr($sourceHash, 0, 12);
            $name = $base . '.' . $download['extension'];
            $path = $targetDir . '/' . $name;
            for ($i = 2; file_exists($path) && $i <= 50; $i++) {
                $name = $base . '-' . $i . '.' . $download['extension'];
                $path = $targetDir . '/' . $name;
            }
            if (file_exists($path)) {
                throw new NjApiHttpException(409, 'media_filename_unavailable');
            }

            // Exclusive creation prevents overwriting the historical media archive.
            $src = fopen($download['temp'], 'rb');
            $dest = $src === false ? false : fopen($path, 'x');
            if ($dest !== false) $createdPath = $path;
            if ($src === false || $dest === false) {
                if (is_resource($src)) fclose($src);
                if (is_resource($dest)) fclose($dest);
                throw new NjApiHttpException(503, 'media_storage_unavailable');
            }
            $copied = stream_copy_to_stream($src, $dest);
            fclose($src);
            fclose($dest);
            if ($copied !== $download['bytes'] || hash_file('sha256', $path) !== $download['hash']) {
                throw new NjApiHttpException(503, 'media_storage_readback_failed');
            }
            @chmod($path, 0644);

            $relative = $month . '/' . $name;
            $guid = 'https://nossojornal.com.br/wp-content/uploads/' . $relative;
            $insert = $pdo->prepare(
                "INSERT INTO {$posts} (
                  post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,
                  post_status,comment_status,ping_status,post_password,post_name,to_ping,
                  pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,
                  guid,menu_order,post_type,post_mime_type,comment_count
                ) VALUES (
                  :author,NOW(),UTC_TIMESTAMP(),'',:title,:caption,'inherit','closed','closed',
                  '',:slug,'','',NOW(),UTC_TIMESTAMP(),'',:parent,:guid,0,'attachment',:mime,0
                )"
            );
            $insert->execute([
                'author' => (int) $post['post_author'],
                'title' => $title,
                'caption' => $caption,
                'slug' => nj_admin_slugify($base),
                'parent' => $postId,
                'guid' => $guid,
                'mime' => $download['mime'],
            ]);
            $attachmentId = (int) $pdo->lastInsertId();
            if ($attachmentId <= 0) {
                throw new RuntimeException('media_attachment_insert_missing');
            }
            nj_admin_upsert_postmeta($pdo, $attachmentId, '_wp_attached_file', $relative);
            nj_admin_upsert_postmeta($pdo, $attachmentId, '_wp_attachment_image_alt', $alt);
            nj_admin_upsert_postmeta($pdo, $attachmentId, '_wp_attachment_metadata', serialize([
                'width' => $download['width'],
                'height' => $download['height'],
                'file' => $relative,
                'sizes' => [],
                'image_meta' => [],
            ]));
            nj_admin_upsert_postmeta($pdo, $attachmentId, '_nj_remote_media_source_sha256', $sourceHash);
            nj_admin_upsert_postmeta($pdo, $attachmentId, '_nj_remote_media_source_url', $url);
            nj_admin_upsert_postmeta($pdo, $attachmentId, '_nj_remote_media_source_page', $sourceUrl);
            nj_admin_upsert_postmeta($pdo, $attachmentId, '_nj_media_credit', $credit);
            nj_admin_upsert_postmeta($pdo, $attachmentId, '_nj_remote_media_file_sha256', $download['hash']);
        }

        if ($featured) {
            nj_admin_upsert_postmeta($pdo, $postId, '_thumbnail_id', (string) $attachmentId);
            nj_admin_upsert_postmeta($pdo, $postId, '_nj_image_credit', $credit);
            nj_admin_upsert_postmeta($pdo, $postId, '_nj_image_caption', $caption);
        }
        nj_admin_log_post_activity($pdo, $postId, (int) $post['post_author'], 'editorial_media_imported', [
            'attachmentId' => $attachmentId,
            'sourcePage' => $sourceUrl,
            'reused' => $reused,
            'featured' => $featured,
            'requestId' => (string) $context['requestId'],
        ]);
        $verify = $pdo->prepare("SELECT ID, post_type, guid FROM {$posts} WHERE ID = :id LIMIT 1");
        $verify->execute(['id' => $attachmentId]);
        $row = $verify->fetch();
        if (!is_array($row) || $row['post_type'] !== 'attachment') {
            throw new RuntimeException('media_attachment_readback_failed');
        }
        if ($featured) {
            $meta = nj_table('postmeta');
            $check = $pdo->prepare("SELECT meta_value FROM {$meta} WHERE post_id = :post AND meta_key = '_thumbnail_id' ORDER BY meta_id DESC LIMIT 1");
            $check->execute(['post' => $postId]);
            if ((int) $check->fetchColumn() !== $attachmentId) {
                throw new RuntimeException('media_featured_readback_failed');
            }
        }
        $publicUrl = (string) $row['guid'];
        $pdo->commit();

        return [
            'post_id' => (string) $postId,
            'attachment_id' => (string) $attachmentId,
            'media_url' => $publicUrl,
            'featured' => (bool) $featured,
            'reused' => $reused,
            'source_page' => $sourceUrl,
            'credit' => $credit,
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (is_string($createdPath)) @unlink($createdPath);
        throw $error;
    } finally {
        if (is_array($download) && is_string($download['temp'])) @unlink($download['temp']);
    }
});
