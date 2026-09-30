<?php
declare(strict_types=1);

require __DIR__ . '/_pautas.php';

nj_m2m_run('GET', 'editorial.pautas.list', static function (array $context): array {
    $rawLimit = $_GET['limit'] ?? '10';
    if (!is_string($rawLimit) || !preg_match('/^[0-9]{1,2}$/', $rawLimit)) {
        throw new NjApiHttpException(422, 'limit_invalid');
    }

    $limit = (int) $rawLimit;
    if ($limit < 1 || $limit > 20) {
        throw new NjApiHttpException(422, 'limit_invalid');
    }

    $cursor = trim((string) ($_GET['cursor'] ?? ''));
    if (strlen($cursor) > 256 || preg_match('/[\r\n\0]/', $cursor)) {
        throw new NjApiHttpException(422, 'cursor_invalid');
    }

    return nj_m2m_list_pautas($context['pdo'], $limit, $cursor);
});
