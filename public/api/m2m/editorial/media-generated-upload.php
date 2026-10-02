<?php
declare(strict_types=1);

/**
 * Bounded AI-image intake over the existing authenticated HMAC editorial bridge.
 * Binary chunks are staged privately outside DOCUMENT_ROOT. No arbitrary paths,
 * provider URLs or server-side shell commands are accepted.
 */
require __DIR__ . '/_draft.php';

const NJ_AI_CHUNK_BYTES = 65536;
const NJ_AI_MAX_BYTES = 8 * 1024 * 1024;
const NJ_AI_STAGE_TTL = 86400;

function nj_ai_stage_root(): string
{
    $path = rtrim(sys_get_temp_dir(), '/') . '/nj-editorial-generated-v1';
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
        throw new NjApiHttpException(503, 'generated_staging_unavailable');
    }
    $real = realpath($path);
    $doc = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($real === false || is_link($path) || ($doc !== false && str_starts_with($real . '/', $doc . '/'))) {
        throw new NjApiHttpException(503, 'generated_staging_unsafe');
    }
    @chmod($path, 0700);

    // Opportunistic bounded garbage collection. An abandoned or failed upload
    // must not accumulate binary parts forever. Never follow symlinks or remove
    // a staging directory while another request holds its lock.
    $candidates = array_slice(glob($real . '/*', GLOB_ONLYDIR) ?: [], 0, 16);
    foreach ($candidates as $candidate) {
        if (is_link($candidate) || preg_match('/^[0-9a-f]{64}$/', basename($candidate)) !== 1) continue;
        $manifest = $candidate . '/manifest.json';
        if (!is_file($manifest) || is_link($manifest) || filemtime($manifest) >= time() - NJ_AI_STAGE_TTL) continue;
        $handle = fopen($candidate . '/lock', 'c');
        if ($handle === false) continue;
        if (flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_file($manifest) && !is_link($manifest) && filemtime($manifest) < time() - NJ_AI_STAGE_TTL) {
                foreach (glob($candidate . '/*.part') ?: [] as $part) {
                    if (preg_match('/^[0-9]{3}\\.part$/', basename($part)) === 1
                        && is_file($part) && !is_link($part)) @unlink($part);
                }
                @unlink($manifest);
            }
            flock($handle, LOCK_UN);
        }
        fclose($handle);
        if (!file_exists($manifest)) {
            @unlink($candidate . '/lock');
            @rmdir($candidate);
        }
    }
    return $real;
}

function nj_ai_upload_context(array $body): array
{
    $postId = filter_var($body['post_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $uploadId = $body['upload_id'] ?? '';
    $total = $body['total_bytes'] ?? null;
    $hash = $body['sha256'] ?? '';
    if (!is_int($postId) || !is_string($uploadId)
        || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uploadId) !== 1
        || !is_int($total) || $total < 1 || $total > NJ_AI_MAX_BYTES
        || !is_string($hash) || preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
        throw new NjApiHttpException(422, 'generated_upload_context_invalid');
    }
    return [$postId, strtolower($uploadId), $total, $hash, (int) ceil($total / NJ_AI_CHUNK_BYTES)];
}

function nj_ai_stage_open(int $postId, string $uploadId, int $total, string $hash): array
{
    $root = nj_ai_stage_root();
    $dir = $root . '/' . hash('sha256', $postId . ':' . $uploadId);
    if (!is_dir($dir) && !mkdir($dir, 0700) && !is_dir($dir)) {
        throw new NjApiHttpException(503, 'generated_staging_unavailable');
    }
    if (is_link($dir)) throw new NjApiHttpException(409, 'generated_staging_unsafe');
    $lock = fopen($dir . '/lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) fclose($lock);
        throw new NjApiHttpException(503, 'generated_staging_locked');
    }
    $manifestPath = $dir . '/manifest.json';
    try {
        if (!is_file($manifestPath)) {
            $file = fopen($manifestPath, 'x');
            if ($file === false) throw new NjApiHttpException(503, 'generated_manifest_unavailable');
            $manifest = json_encode([
                'post_id' => $postId, 'upload_id' => $uploadId,
                'total_bytes' => $total, 'sha256' => $hash, 'created_at' => time(),
            ], JSON_THROW_ON_ERROR);
            if (fwrite($file, $manifest) !== strlen($manifest)) {
                fclose($file);
                throw new NjApiHttpException(503, 'generated_manifest_unavailable');
            }
            fclose($file);
            @chmod($manifestPath, 0600);
        }
        if (is_link($manifestPath) || filesize($manifestPath) > 1024) {
            throw new NjApiHttpException(409, 'generated_manifest_invalid');
        }
        $info = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($info) || ($info['post_id'] ?? null) !== $postId
            || ($info['upload_id'] ?? null) !== $uploadId
            || ($info['total_bytes'] ?? null) !== $total
            || ($info['sha256'] ?? null) !== $hash) {
            throw new NjApiHttpException(409, 'generated_upload_conflict');
        }
        if (time() - (int) ($info['created_at'] ?? 0) > NJ_AI_STAGE_TTL) {
            throw new NjApiHttpException(410, 'generated_upload_expired');
        }
        return [$dir, $lock];
    } catch (Throwable $error) {
        flock($lock, LOCK_UN);
        fclose($lock);
        throw $error;
    }
}

function nj_ai_stage_cleanup(string $dir): void
{
    // Only files under the generated-upload staging directory, never public uploads.
    foreach (glob($dir . '/*.part') ?: [] as $file) {
        if (is_file($file) && !is_link($file)) @unlink($file);
    }
    @unlink($dir . '/manifest.json');
    @unlink($dir . '/lock');
    @rmdir($dir);
}

function nj_ai_require_draft(PDO $pdo, int $postId): void
{
    $posts = nj_table('posts');
    $statement = $pdo->prepare("SELECT ID FROM {$posts} WHERE ID = :id
        AND post_type = 'post' AND post_status = 'draft' LIMIT 1");
    $statement->execute(['id' => $postId]);
    if (!$statement->fetchColumn()) throw new NjApiHttpException(409, 'generated_media_draft_required');
}

nj_m2m_run('POST', 'editorial.media.generated.upload', static function (array $context): array {
    $body = nj_m2m_body($context);
    $phase = $body['phase'] ?? '';
    $common = ['phase','upload_id','post_id','total_bytes','sha256'];
    $chunkFields = ['chunk_index','chunk_sha256','chunk_base64'];
    $finalFields = ['title','alt','caption','credit','license','seo_title',
        'seo_description','model','prompt_summary','set_featured'];
    $allowed = $phase === 'chunk' ? [...$common,...$chunkFields]
        : ($phase === 'finalize' ? [...$common,...$finalFields] : []);
    if ($allowed === [] || array_diff(array_keys($body), $allowed) !== []) {
        throw new NjApiHttpException(422, 'generated_upload_phase_invalid');
    }
    [$postId, $uploadId, $total, $hash, $count] = nj_ai_upload_context($body);
    $pdo = $context['pdo'];
    nj_ai_require_draft($pdo, $postId);
    [$dir, $lock] = nj_ai_stage_open($postId, $uploadId, $total, $hash);
    $finish = false;

    try {
        if ($phase === 'chunk') {
            $index = $body['chunk_index'] ?? null;
            $chunkHash = $body['chunk_sha256'] ?? '';
            $encoded = $body['chunk_base64'] ?? '';
            if (!is_int($index) || $index < 0 || $index >= $count
                || !is_string($chunkHash) || preg_match('/^[0-9a-f]{64}$/', $chunkHash) !== 1
                || !is_string($encoded) || strlen($encoded) > 90000
                || $encoded === '' || preg_match('/^[A-Za-z0-9+\/]+={0,2}$/D', $encoded) !== 1) {
                throw new NjApiHttpException(422, 'generated_chunk_invalid');
            }
            $bytes = base64_decode($encoded, true);
            $expected = min(NJ_AI_CHUNK_BYTES, $total - $index * NJ_AI_CHUNK_BYTES);
            if ($bytes === false || strlen($bytes) !== $expected
                || !hash_equals($chunkHash, hash('sha256', $bytes))) {
                throw new NjApiHttpException(422, 'generated_chunk_checksum_invalid');
            }
            $part = $dir . '/' . sprintf('%03d.part', $index);
            $reused = false;
            if (file_exists($part)) {
                if (is_link($part) || filesize($part) !== $expected
                    || !hash_equals($chunkHash, (string) hash_file('sha256', $part))) {
                    throw new NjApiHttpException(409, 'generated_chunk_conflict');
                }
                $reused = true;
            } else {
                $out = fopen($part, 'x');
                if ($out === false) throw new NjApiHttpException(503, 'generated_chunk_storage_failed');
                if (fwrite($out, $bytes) !== $expected) {
                    fclose($out);
                    @unlink($part);
                    throw new NjApiHttpException(503, 'generated_chunk_storage_failed');
                }
                fclose($out);
                @chmod($part, 0600);
            }
            return ['phase' => 'chunk', 'post_id' => (string) $postId,
                'upload_id' => $uploadId, 'chunk_index' => $index,
                'total_chunks' => $count, 'reused' => $reused];
        }

        $title = trim((string) ($body['title'] ?? ''));
        $alt = trim((string) ($body['alt'] ?? ''));
        $caption = trim((string) ($body['caption'] ?? ''));
        $credit = trim((string) ($body['credit'] ?? ''));
        $license = trim((string) ($body['license'] ?? 'AI-generated illustration'));
        $seoTitle = trim((string) ($body['seo_title'] ?? ''));
        $seoDescription = trim((string) ($body['seo_description'] ?? ''));
        $model = trim((string) ($body['model'] ?? ''));
        $prompt = trim((string) ($body['prompt_summary'] ?? ''));
        $length = static fn (string $value): int => function_exists('mb_strlen')
            ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($title === '' || $length($title) > 160 || $alt === '' || $length($alt) > 500
            || $credit === '' || $length($credit) > 1000 || $model === '' || $length($model) > 100
            || $length($caption) > 1000 || $length($license) > 200
            || $length($seoTitle) > 180 || $length($seoDescription) > 400
            || $length($prompt) > 1000 || !is_bool($body['set_featured'] ?? true)) {
            throw new NjApiHttpException(422, 'generated_media_metadata_invalid');
        }

        $assembled = tempnam(sys_get_temp_dir(), 'nj-ai-');
        if ($assembled === false) throw new NjApiHttpException(503, 'generated_staging_unavailable');
        $out = fopen($assembled, 'wb');
        if ($out === false) throw new NjApiHttpException(503, 'generated_staging_unavailable');
        $copied = 0;
        try {
            for ($i = 0; $i < $count; $i++) {
                $part = $dir . '/' . sprintf('%03d.part', $i);
                $expected = min(NJ_AI_CHUNK_BYTES, $total - $i * NJ_AI_CHUNK_BYTES);
                if (!is_file($part) || is_link($part) || filesize($part) !== $expected) {
                    throw new NjApiHttpException(409, 'generated_upload_incomplete');
                }
                $input = fopen($part, 'rb');
                if ($input === false) throw new NjApiHttpException(503, 'generated_chunk_read_failed');
                $added = stream_copy_to_stream($input, $out);
                fclose($input);
                if ($added !== $expected) throw new NjApiHttpException(503, 'generated_chunk_read_failed');
                $copied += $added;
            }
        } finally {
            fclose($out);
        }
        if ($copied !== $total || !hash_equals($hash, (string) hash_file('sha256', $assembled))) {
            @unlink($assembled);
            throw new NjApiHttpException(422, 'generated_upload_checksum_invalid');
        }
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($assembled);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
        $size = @getimagesize($assembled);
        if ($ext === null || !is_array($size)
            || (int) $size[0] < 1 || (int) $size[1] < 1
            || (int) $size[0] > 10000 || (int) $size[1] > 10000
            || (int) $size[0] * (int) $size[1] > 40000000) {
            @unlink($assembled);
            throw new NjApiHttpException(422, 'generated_image_invalid');
        }

        $root = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        if ($root === '') throw new NjApiHttpException(503, 'media_storage_unavailable');
        $uploads = $root . '/wp-content/uploads';
        $posts = nj_table('posts');
        $meta = nj_table('postmeta');
        $created = null;
        $reused = false;
        try {
            $pdo->beginTransaction();
            $lockPost = $pdo->prepare("SELECT ID,post_author FROM {$posts}
                WHERE ID = :id AND post_type = 'post' AND post_status = 'draft' LIMIT 1 FOR UPDATE");
            $lockPost->execute(['id' => $postId]);
            $draft = $lockPost->fetch();
            if (!is_array($draft)) throw new NjApiHttpException(409, 'generated_media_draft_changed');
            $existing = $pdo->prepare("SELECT p.ID AS id,p.guid
                FROM {$posts} p JOIN {$meta} m ON m.post_id = p.ID
                WHERE p.post_parent = :post AND p.post_type = 'attachment'
                AND m.meta_key = '_nj_ai_media_file_sha256' AND m.meta_value = :hash
                LIMIT 1");
            $existing->execute(['post' => $postId, 'hash' => $hash]);
            $attachment = $existing->fetch();
            $attachmentId = is_array($attachment) ? (int) $attachment['id'] : 0;
            $reused = $attachmentId > 0;
            if ($attachmentId === 0) {
                $now = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
                $month = $now->format('Y/m');
                $targetDir = $uploads . '/' . $month;
                if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                    throw new NjApiHttpException(503, 'media_storage_unavailable');
                }
                if (!is_writable($targetDir)) throw new NjApiHttpException(503, 'media_storage_unavailable');
                $base = substr(nj_admin_slugify($title), 0, 68);
                $base = ($base === '' ? 'ilustracao-ia' : $base) . '-' . substr($hash, 0, 12);
                $name = $base . '.' . $ext;
                $path = $targetDir . '/' . $name;
                for ($n = 2; file_exists($path) && $n <= 50; $n++) {
                    $name = $base . '-' . $n . '.' . $ext;
                    $path = $targetDir . '/' . $name;
                }
                if (file_exists($path)) throw new NjApiHttpException(409, 'generated_filename_unavailable');
                $input = fopen($assembled, 'rb');
                $target = $input === false ? false : fopen($path, 'x');
                if ($target !== false) $created = $path;
                if ($input === false || $target === false) {
                    if (is_resource($input)) fclose($input);
                    if (is_resource($target)) fclose($target);
                    throw new NjApiHttpException(503, 'generated_media_write_failed');
                }
                $written = stream_copy_to_stream($input, $target);
                fclose($input);
                fclose($target);
                if ($written !== $total || hash_file('sha256', $path) !== $hash) {
                    throw new NjApiHttpException(503, 'generated_media_readback_failed');
                }
                @chmod($path, 0644);
                $relative = $month . '/' . $name;
                $guid = 'https://nossojornal.com.br/wp-content/uploads/' . $relative;
                $insert = $pdo->prepare("INSERT INTO {$posts} (
                    post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,
                    post_status,comment_status,ping_status,post_password,post_name,to_ping,
                    pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,
                    guid,menu_order,post_type,post_mime_type,comment_count
                ) VALUES (
                    :author,NOW(),UTC_TIMESTAMP(),'',:title,:caption,'inherit','closed','closed',
                    '',:slug,'','',NOW(),UTC_TIMESTAMP(),'',:parent,:guid,0,'attachment',:mime,0
                )");
                $insert->execute(['author' => (int) $draft['post_author'], 'title' => $title,
                    'caption' => $caption, 'slug' => nj_admin_slugify($base), 'parent' => $postId,
                    'guid' => $guid, 'mime' => $mime]);
                $attachmentId = (int) $pdo->lastInsertId();
                if ($attachmentId < 1) throw new RuntimeException('generated_attachment_missing');
                nj_admin_upsert_postmeta($pdo, $attachmentId, '_wp_attached_file', $relative);
                nj_admin_upsert_postmeta($pdo, $attachmentId, '_wp_attachment_image_alt', $alt);
                nj_admin_upsert_postmeta($pdo, $attachmentId, '_wp_attachment_metadata', serialize([
                    'width' => (int) $size[0], 'height' => (int) $size[1], 'file' => $relative,
                    'sizes' => [], 'image_meta' => [],
                ]));
                nj_admin_upsert_postmeta($pdo, $attachmentId, '_nj_ai_media_file_sha256', $hash);
                nj_admin_upsert_postmeta($pdo, $attachmentId, '_nj_ai_generated_model', $model);
                nj_admin_upsert_postmeta($pdo, $attachmentId, '_nj_ai_prompt_summary', $prompt);
                nj_admin_upsert_postmeta($pdo, $attachmentId, '_nj_media_credit', $credit);
                nj_admin_upsert_postmeta($pdo, $attachmentId, '_nj_media_license', $license);
                nj_admin_upsert_postmeta($pdo, $attachmentId, '_nj_media_seo_title', $seoTitle);
                nj_admin_upsert_postmeta($pdo, $attachmentId, '_nj_media_seo_description', $seoDescription);
            } else {
                $file = $pdo->prepare("SELECT meta_value FROM {$meta} WHERE post_id = :id
                    AND meta_key = '_wp_attached_file' ORDER BY meta_id DESC LIMIT 1");
                $file->execute(['id' => $attachmentId]);
                $relative = (string) $file->fetchColumn();
                if (!preg_match('#^[0-9]{4}/[0-9]{2}/[a-z0-9._-]+$#i', $relative)
                    || !is_file($uploads . '/' . $relative) || is_link($uploads . '/' . $relative)
                    || hash_file('sha256', $uploads . '/' . $relative) !== $hash) {
                    throw new NjApiHttpException(409, 'generated_existing_media_invalid');
                }
                $guid = (string) $attachment['guid'];
            }
            if (($body['set_featured'] ?? true) === true) {
                nj_admin_upsert_postmeta($pdo, $postId, '_thumbnail_id', (string) $attachmentId);
                nj_admin_upsert_postmeta($pdo, $postId, '_nj_image_credit', $credit);
                nj_admin_upsert_postmeta($pdo, $postId, '_nj_image_caption', $caption);
            }
            nj_admin_log_post_activity($pdo, $postId, (int) $draft['post_author'], 'editorial_generated_media_uploaded', [
                'attachmentId' => $attachmentId, 'generated' => true,
                'featured' => ($body['set_featured'] ?? true) === true,
                'requestId' => (string) $context['requestId'],
            ]);
            $verify = $pdo->prepare("SELECT ID,post_type FROM {$posts} WHERE ID = :id LIMIT 1");
            $verify->execute(['id' => $attachmentId]);
            $row = $verify->fetch();
            if (!is_array($row) || $row['post_type'] !== 'attachment') {
                throw new RuntimeException('generated_attachment_readback_failed');
            }
            if (($body['set_featured'] ?? true) === true) {
                $check = $pdo->prepare("SELECT meta_value FROM {$meta}
                    WHERE post_id = :post AND meta_key = '_thumbnail_id' ORDER BY meta_id DESC LIMIT 1");
                $check->execute(['post' => $postId]);
                if ((int) $check->fetchColumn() !== $attachmentId) {
                    throw new RuntimeException('generated_featured_readback_failed');
                }
            }
            $pdo->commit();
            $finish = true;
            return ['phase' => 'finalize', 'post_id' => (string) $postId,
                'attachment_id' => (string) $attachmentId, 'media_url' => $guid,
                'sha256' => $hash, 'featured' => ($body['set_featured'] ?? true) === true,
                'reused' => $reused, 'generated' => true];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($created !== null) @unlink($created);
            throw $error;
        } finally {
            @unlink($assembled);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
        if ($finish) nj_ai_stage_cleanup($dir);
    }
});
