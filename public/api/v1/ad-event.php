<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

// Each short-lived, unpredictable delivery ticket can count one visible impression.
// No cookies, IPs or user-agent fingerprints are stored.
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        throw new NjApiHttpException(405, 'method_not_allowed');
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 256) {
        throw new NjApiHttpException(413, 'event_too_large');
    }
    $raw = (string) file_get_contents('php://input');
    if (strlen($raw) > 256) throw new NjApiHttpException(413, 'event_too_large');
    $body = json_decode($raw, true);
    $token = is_array($body) ? (string) ($body['token'] ?? '') : '';
    if (!preg_match('/^[0-9a-f]{32}$/D', $token) || ($body['type'] ?? '') !== 'impression') {
        throw new NjApiHttpException(422, 'invalid_ad_event');
    }
    $table = nj_app_table('ad_serves');
    $stmt = nj_db()->prepare(
        "UPDATE {$table} SET impression_at = NOW()
         WHERE token = :token AND impression_at IS NULL AND expires_at >= NOW()"
    );
    $stmt->execute(['token' => $token]);
    http_response_code(204);
    header('Cache-Control: no-store');
    header('Content-Length: 0');
} catch (NjApiHttpException $error) {
    nj_json(['ok' => false, 'error' => ['code' => $error->errorCode]], $error->status);
} catch (Throwable $error) {
    error_log('[nossojornal-ads] impression error=' . get_class($error));
    nj_json(['ok' => false, 'error' => ['code' => 'event_unavailable']], 503);
}
