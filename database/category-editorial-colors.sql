-- Nosso Jornal: identidade cromática das editorias
-- Autoridade: wp_termmeta / njsite_termmeta
-- Chave: nj_editorial_color
-- Seguro para reexecução: remove somente esta chave antes de recriá-la.

START TRANSACTION;

DELETE tm
FROM njsite_termmeta tm
INNER JOIN njsite_terms t ON t.term_id = tm.term_id
WHERE
  tm.meta_key = 'nj_editorial_color'
  AND t.slug IN (
    'capa',
    'geral',
    'outros',
    'eleicoes-2024',
    'cobertura-regional',
    'hulha-negra',
    'politica',
    'seguranca',
    'economia',
    'esportes',
    'cultura',
    'educacao',
    'rural',
    'saude',
    'rio-grande-do-sul',
    'brasil',
    'internacional',
    'acegua',
    'bage',
    'candiota',
    'dom-pedrito',
    'herval',
    'pedras-altas',
    'pinheiro-machado',
    'piratini'
  );

INSERT INTO njsite_termmeta (term_id, meta_key, meta_value)
SELECT
  t.term_id,
  'nj_editorial_color',
  CASE t.slug
    WHEN 'capa' THEN '#123B8C'
    WHEN 'geral' THEN '#475569'
    WHEN 'outros' THEN '#64748B'
    WHEN 'eleicoes-2024' THEN '#9333EA'
    WHEN 'cobertura-regional' THEN '#1D4ED8'

    WHEN 'hulha-negra' THEN '#0B57D0'
    WHEN 'politica' THEN '#6D28D9'
    WHEN 'seguranca' THEN '#C2410C'
    WHEN 'economia' THEN '#0F766E'
    WHEN 'esportes' THEN '#15803D'
    WHEN 'cultura' THEN '#BE185D'
    WHEN 'educacao' THEN '#0369A1'
    WHEN 'rural' THEN '#4D7C0F'
    WHEN 'saude' THEN '#0891B2'
    WHEN 'rio-grande-do-sul' THEN '#B45309'
    WHEN 'brasil' THEN '#166534'
    WHEN 'internacional' THEN '#334155'

    WHEN 'acegua' THEN '#4338CA'
    WHEN 'bage' THEN '#2563EB'
    WHEN 'candiota' THEN '#0284C7'
    WHEN 'dom-pedrito' THEN '#0E7490'
    WHEN 'herval' THEN '#0369A1'
    WHEN 'pedras-altas' THEN '#4F46E5'
    WHEN 'pinheiro-machado' THEN '#1E40AF'
    WHEN 'piratini' THEN '#075985'
  END
FROM njsite_terms t
WHERE t.slug IN (
  'capa',
  'geral',
  'outros',
  'eleicoes-2024',
  'cobertura-regional',
  'hulha-negra',
  'politica',
  'seguranca',
  'economia',
  'esportes',
  'cultura',
  'educacao',
  'rural',
  'saude',
  'rio-grande-do-sul',
  'brasil',
  'internacional',
  'acegua',
  'bage',
  'candiota',
  'dom-pedrito',
  'herval',
  'pedras-altas',
  'pinheiro-machado',
  'piratini'
);

COMMIT;

SELECT
  t.term_id,
  t.name,
  t.slug,
  tm.meta_value AS editorial_color
FROM njsite_terms t
INNER JOIN njsite_termmeta tm
  ON tm.term_id = t.term_id
  AND tm.meta_key = 'nj_editorial_color'
ORDER BY t.name ASC;
