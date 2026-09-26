import { useMemo } from 'react';

type AdminEditorialDiagnosticsProps = {
  title: string;
  slug: string;
  excerpt: string;
  content: string;
  seoTitle: string;
  seoDescription: string;
  urlPrefix?: string;
  disabled?: boolean;
  onSlugChange?: (value: string) => void;
  onSeoTitleChange?: (value: string) => void;
  onSeoDescriptionChange?: (value: string) => void;
};

type MetricTone = 'good' | 'attention' | 'neutral';

function plainText(html: string) {
  if (!html) return '';
  const element = document.createElement('div');
  element.innerHTML = html;
  return (element.textContent ?? '').replace(/\s+/gu, ' ').trim();
}

function slugify(value: string) {
  return value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/gu, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/gu, '-')
    .replace(/^-+|-+$/gu, '')
    .replace(/-{2,}/gu, '-')
    .slice(0, 180);
}

function metricTone(value: number, idealMin: number, idealMax: number): MetricTone {
  if (value === 0) return 'neutral';
  if (value >= idealMin && value <= idealMax) return 'good';
  return 'attention';
}

function Metric({
  label,
  value,
  detail,
  tone = 'neutral',
}: {
  label: string;
  value: string;
  detail: string;
  tone?: MetricTone;
}) {
  return (
    <div className={'admin-editorial-diagnostic admin-editorial-diagnostic--' + tone}>
      <span>{label}</span>
      <strong>{value}</strong>
      <small>{detail}</small>
    </div>
  );
}

export function AdminEditorialDiagnostics({
  title,
  slug,
  excerpt,
  content,
  seoTitle,
  seoDescription,
  urlPrefix = '/',
  disabled = false,
  onSlugChange,
  onSeoTitleChange,
  onSeoDescriptionChange,
}: AdminEditorialDiagnosticsProps) {
  const diagnostics = useMemo(() => {
    const parser = document.createElement('div');
    parser.innerHTML = content;

    const text = plainText(content);
    const words = text ? text.split(/\s+/u).filter(Boolean).length : 0;
    const readingMinutes = words === 0 ? 0 : Math.max(1, Math.ceil(words / 220));
    const headings = parser.querySelectorAll('h2, h3').length;
    const images = [...parser.querySelectorAll<HTMLImageElement>('img')];
    const missingAlt = images.filter((image) => !(image.getAttribute('alt') ?? '').trim()).length;
    const links = [...parser.querySelectorAll<HTMLAnchorElement>('a[href]')];
    const externalLinks = links.filter((anchor) => {
      const href = anchor.getAttribute('href') ?? '';
      return /^https?:\/\//i.test(href) && !href.includes(window.location.hostname);
    }).length;

    return {
      words,
      readingMinutes,
      headings,
      images: images.length,
      missingAlt,
      links: links.length,
      externalLinks,
    };
  }, [content]);

  const effectiveSeoTitle = seoTitle.trim() || title.trim();
  const effectiveDescription = seoDescription.trim() || excerpt.trim();
  const canonicalPreview = urlPrefix + (slug || slugify(title) || 'endereco-da-publicacao');

  return (
    <section className="admin-editor-card admin-editorial-diagnostics">
      <div className="admin-editor-card__head">
        <span>Qualidade editorial</span>
        <strong>Diagnóstico em tempo real</strong>
      </div>

      <div className="admin-editorial-diagnostics__metrics">
        <Metric
          label="Leitura"
          value={diagnostics.readingMinutes ? diagnostics.readingMinutes + ' min' : '—'}
          detail={diagnostics.words.toLocaleString('pt-BR') + ' palavras'}
        />
        <Metric
          label="Título"
          value={title.length + ' caracteres'}
          detail="Faixa editorial sugerida: 35–85"
          tone={metricTone(title.trim().length, 35, 85)}
        />
        <Metric
          label="Resumo"
          value={excerpt.length + ' caracteres'}
          detail="Faixa sugerida: 90–220"
          tone={metricTone(excerpt.trim().length, 90, 220)}
        />
        <Metric
          label="Estrutura"
          value={diagnostics.headings + ' subtítulos'}
          detail={diagnostics.links + ' links • ' + diagnostics.images + ' imagens'}
          tone={diagnostics.words > 450 && diagnostics.headings === 0 ? 'attention' : 'neutral'}
        />
      </div>

      <div className="admin-editorial-diagnostics__grid">
        <div className="admin-editorial-diagnostics__panel">
          <div className="admin-editorial-diagnostics__panel-head">
            <div>
              <span>Busca</span>
              <strong>Prévia do resultado</strong>
            </div>
            <div className="admin-editorial-diagnostics__compact-metrics">
              <span className={'tone-' + metricTone(effectiveSeoTitle.length, 35, 65)}>
                título {effectiveSeoTitle.length}/65
              </span>
              <span className={'tone-' + metricTone(effectiveDescription.length, 90, 160)}>
                descrição {effectiveDescription.length}/160
              </span>
            </div>
          </div>

          <div className="admin-serp-preview" aria-label="Prévia de resultado em buscador">
            <span>nossojornal.com.br › {canonicalPreview.replace(/^\/+/, '').replaceAll('/', ' › ')}</span>
            <strong>{effectiveSeoTitle || 'Título da publicação'}</strong>
            <p>{effectiveDescription || 'Adicione um resumo ou uma descrição SEO para controlar melhor esta prévia.'}</p>
          </div>

          <div className="admin-editorial-diagnostics__quick-actions">
            {onSlugChange && (
              <button
                type="button"
                disabled={disabled || !title.trim()}
                onClick={() => onSlugChange(slugify(title))}
              >
                Gerar link pelo título
              </button>
            )}
            {onSeoTitleChange && (
              <button
                type="button"
                disabled={disabled || !title.trim()}
                onClick={() => onSeoTitleChange(title.trim())}
              >
                Usar título no SEO
              </button>
            )}
            {onSeoDescriptionChange && (
              <button
                type="button"
                disabled={disabled || !excerpt.trim()}
                onClick={() => onSeoDescriptionChange(excerpt.trim().slice(0, 320))}
              >
                Usar resumo na descrição
              </button>
            )}
          </div>
        </div>

        <div className="admin-editorial-diagnostics__panel">
          <div className="admin-editorial-diagnostics__panel-head">
            <div>
              <span>Conteúdo</span>
              <strong>Sinais para revisão</strong>
            </div>
          </div>

          <ul className="admin-editorial-signal-list">
            <li className={diagnostics.words >= 180 ? 'is-ok' : 'is-attention'}>
              <strong>{diagnostics.words >= 180 ? 'Texto com corpo editorial' : 'Texto curto'}</strong>
              <span>{diagnostics.words.toLocaleString('pt-BR')} palavras no conteúdo.</span>
            </li>
            <li className={diagnostics.words < 450 || diagnostics.headings > 0 ? 'is-ok' : 'is-attention'}>
              <strong>{diagnostics.headings > 0 ? 'Leitura segmentada' : 'Subtítulos'}</strong>
              <span>{diagnostics.headings > 0 ? diagnostics.headings + ' subtítulos encontrados.' : 'Textos longos ficam mais escaneáveis com H2/H3.'}</span>
            </li>
            <li className={diagnostics.missingAlt === 0 ? 'is-ok' : 'is-attention'}>
              <strong>Imagens acessíveis</strong>
              <span>
                {diagnostics.images === 0
                  ? 'Nenhuma imagem inserida no corpo.'
                  : diagnostics.missingAlt === 0
                    ? 'Todas as imagens do corpo possuem texto alternativo.'
                    : diagnostics.missingAlt + ' imagem(ns) sem texto alternativo.'}
              </span>
            </li>
            <li className={diagnostics.externalLinks > 0 ? 'is-ok' : 'is-neutral'}>
              <strong>Referências externas</strong>
              <span>{diagnostics.externalLinks} link(s) externo(s) encontrado(s).</span>
            </li>
          </ul>
        </div>
      </div>
    </section>
  );
}
