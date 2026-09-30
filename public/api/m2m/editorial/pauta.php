<?php
declare(strict_types=1);

require __DIR__ . '/_pautas.php';

nj_m2m_run('GET', 'editorial.pauta.get', static function (array $context): array {
    $pautaId = nj_m2m_pauta_id($_GET['pauta_id'] ?? '');

    return [
        'pauta' => nj_m2m_pauta_projection(
            nj_m2m_load_pauta($context['pdo'], $pautaId)
        ),
    ];
});
