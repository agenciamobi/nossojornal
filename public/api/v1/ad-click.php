<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

// The redirect target is read only from the original server-side ad delivery.
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        throw new NjApiHttpException(405, 'method_not_allowed');
    }
    $token = (string) ($_GET['t'] ?? '');
    if (!preg_match('/^[0-9a-f]{32}$/D', $token)) {
        throw new NjApiHttpException(404, 'ad_not_found');
    }
    $table = nj_app_table('ad_serves');
    $stmt = nj_db()->prepare(
        "SELECT click_url FROM {$table} WHERE token = :token AND expires_at >= NOW() LIMIT 1"
    );
    $stmt->execute(['token' => $token]);
    $row = $stmt->fetch();
    $url = is_array($row) ? trim((string) ($row['click_url'] ?? '')) : '';
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, ['https', 'http'], true)) {
        throw new NjApiHttpException(404, 'ad_not_found');
    }
    $update = nj_db()->prepare(
        "UPDATE {$table} SET click_at = NOW(), impression_at = COALESCE(impression_at, NOW())
         WHERE token = :token AND click_at IS NULL AND expires_at >= NOW()"
    );
    $update->execute(['token' => $token]);
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    header('Location: ' . $url, true, 302);
    exit;
} catch (NjApiHttpException $error) {
    nj_json(['ok' => false, 'error' => ['code' => $error->errorCode]], $error->status);
} catch (Throwable $error) {
    error_log('[nossojornal-ads] click error=' . get_class($error));
    nj_json(['ok' => false, 'error' => ['code' => 'redirect_unavailable']], 503);
}
