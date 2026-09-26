<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

nj_admin_run(['POST'], static function (): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'upload_files');
    nj_admin_require_csrf();

    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        throw new NjApiHttpException(422, 'file_required');
    }

    $file = $_FILES['file'];
    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($errorCode !== UPLOAD_ERR_OK) {
        throw new NjApiHttpException(422, 'upload_failed');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    $originalName = trim((string) ($file['name'] ?? ''));
    $size = (int) ($file['size'] ?? 0);

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new NjApiHttpException(422, 'invalid_upload');
    }

    if ($size <= 0 || $size > 12 * 1024 * 1024) {
        throw new NjApiHttpException(422, 'file_too_large');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmpName);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    if (!isset($allowed[$mime])) {
        throw new NjApiHttpException(422, 'unsupported_media_type');
    }

    $imageSize = @getimagesize($tmpName);
    if (!is_array($imageSize) || (int) ($imageSize[0] ?? 0) <= 0 || (int) ($imageSize[1] ?? 0) <= 0) {
        throw new NjApiHttpException(422, 'invalid_image');
    }

    $baseName = pathinfo($originalName, PATHINFO_FILENAME);
    $safeBase = nj_admin_slugify($baseName);
    if ($safeBase === '') {
        $safeBase = 'imagem';
    }

    $extension = $allowed[$mime];
    $timezone = new DateTimeZone('America/Sao_Paulo');
    $now = new DateTimeImmutable('now', $timezone);
    $relativeDir = $now->format('Y/m');
    $documentRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');

    if ($documentRoot === '') {
        throw new RuntimeException('document_root_unavailable');
    }

    $uploadRoot = $documentRoot . '/wp-content/uploads';
    $targetDir = $uploadRoot . '/' . $relativeDir;

    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        throw new NjApiHttpException(409, 'media_storage_unavailable');
    }

    if (!is_writable($targetDir)) {
        throw new NjApiHttpException(409, 'media_storage_unavailable');
    }

    $filename = $safeBase . '.' . $extension;
    $targetPath = $targetDir . '/' . $filename;
    $suffix = 2;

    while (file_exists($targetPath)) {
        $filename = substr($safeBase, 0, 150) . '-' . $suffix . '.' . $extension;
        $targetPath = $targetDir . '/' . $filename;
        $suffix++;

        if ($suffix > 500) {
            throw new RuntimeException('media_filename_exhausted');
        }
    }

    if (!move_uploaded_file($tmpName, $targetPath)) {
        throw new NjApiHttpException(409, 'media_storage_unavailable');
    }

    @chmod($targetPath, 0644);

    $relativeFile = $relativeDir . '/' . $filename;
    $publicPath = '/wp-content/uploads/' . $relativeFile;
    $publicUrl = 'https://nossojornal.com.br' . $publicPath;
    $title = trim($baseName) !== '' ? trim($baseName) : 'Imagem';

    $pdo = nj_db();
    $posts = nj_table('posts');

    try {
        $pdo->beginTransaction();

        $insert = $pdo->prepare(<<<SQL
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
    '',
    :title,
    '',
    'inherit',
    'open',
    'closed',
    '',
    :slug,
    '',
    '',
    NOW(),
    UTC_TIMESTAMP(),
    '',
    0,
    :guid,
    0,
    'attachment',
    :mime_type,
    0
)
SQL);
        $insert->execute([
            'author_id' => $user['id'],
            'title' => $title,
            'slug' => nj_admin_slugify($safeBase),
            'guid' => $publicUrl,
            'mime_type' => $mime,
        ]);

        $attachmentId = (int) $pdo->lastInsertId();

        if ($attachmentId <= 0) {
            throw new RuntimeException('attachment_insert_missing_id');
        }

        nj_admin_upsert_postmeta($pdo, $attachmentId, '_wp_attached_file', $relativeFile);
        nj_admin_upsert_postmeta($pdo, $attachmentId, '_wp_attachment_image_alt', '');

        $metadata = serialize([
            'width' => (int) $imageSize[0],
            'height' => (int) $imageSize[1],
            'file' => $relativeFile,
            'sizes' => [],
            'image_meta' => [],
        ]);
        nj_admin_upsert_postmeta($pdo, $attachmentId, '_wp_attachment_metadata', $metadata);

        $readBack = $pdo->prepare(<<<SQL
SELECT
    ID AS id,
    post_title AS title,
    post_mime_type AS mime_type,
    guid
FROM {$posts}
WHERE
    ID = :id
    AND post_type = 'attachment'
LIMIT 1
SQL);
        $readBack->execute(['id' => $attachmentId]);
        $persisted = $readBack->fetch();

        if (
            !$persisted
            || (int) $persisted['id'] !== $attachmentId
            || (string) $persisted['mime_type'] !== $mime
            || (string) $persisted['guid'] !== $publicUrl
        ) {
            throw new RuntimeException('attachment_readback_mismatch');
        }

        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        @unlink($targetPath);

        if ($error instanceof PDOException && (string) $error->getCode() === '42000') {
            throw new NjApiHttpException(409, 'database_write_unavailable');
        }

        throw $error;
    }

    return [
        'media' => [
            'id' => $attachmentId,
            'title' => $title,
            'mimeType' => $mime,
            'url' => $publicPath,
            'alt' => '',
            'createdAt' => $now->format(DATE_ATOM),
            'modifiedAt' => $now->format(DATE_ATOM),
            'parentId' => 0,
        ],
    ];
});
