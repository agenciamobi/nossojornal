<?php
declare(strict_types=1);

require __DIR__ . '/_pautas.php';

nj_m2m_run('POST', 'editorial.pauta.resolve', static function (array $context): array {
    $body = nj_m2m_body($context);

    foreach (array_keys($body) as $key) {
        if (!in_array($key, ['pauta_id', 'claim'], true)) {
            throw new NjApiHttpException(422, 'body_field_not_allowed');
        }
    }

    $pautaId = nj_m2m_pauta_id($body['pauta_id'] ?? '');
    $claim = trim((string) ($body['claim'] ?? ''));

    return nj_m2m_resolve_pauta(
        $context['pdo'],
        $pautaId,
        $claim,
        (string) $context['requestId']
    );
});
