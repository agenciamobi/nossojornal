import { useEffect, useMemo, useState } from 'react';
import './header.css';

type Category = {
  id: number;
  taxonomyId: number;
  name: string;
  slug: string;
  parentId: number | null;
  url: string;
  legacyCount: number;
  publishedCount: number;
  latestPublishedAt: string | null;
  children?: Category[];
};

type CategoriesResponse = {
  ok: boolean;
  data?: {
    tree?: Category[];
  };
};

const SYSTEM_CATEGORY_SLUGS = new Set([
  'capa',
  'geral',
  'outros',
  'eleicoes-2024',
]);

const EDITORIAL_ORDER = [
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
  'cobertura-regional',
];

function sortEditorialCategories(categories: Category[]) {
  const order = new Map(EDITORIAL_ORDER.map((slug, index) => [slug, index]));

  return [...categories].sort((a, b) => {
    const aOrder = order.get(a.slug) ?? Number.MAX_SAFE_INTEGER;
    const bOrder = order.get(b.slug) ?? Number.MAX_SAFE_INTEGER;

    if (aOrder !== bOrder) {
      return aOrder - bOrder;
    }

    return a.name.localeCompare(b.name, 'pt-BR');
  });
}

export function Brand() {
  return (
    <a className="brand" href="/" aria-label="Nosso Jornal - página inicial">
      <img src="/nosso-jornal-brand.webp" alt="" className="brand__mark" />
      <span className="brand__copy">
        <strong>NOSSO</strong>
        <span>JORNAL</span>
      </span>
    </a>
  );
}

export function SiteHeader() {
  const [categories, setCategories] = useState<Category[]>([]);
  const [categoryState, setCategoryState] = useState<'loading' | 'ready' | 'error'>('loading');

  useEffect(() => {
    const controller = new AbortController();

    async function loadCategories() {
      try {
        const response = await fetch('/api/v1/categories.php?include_empty=1', {
          headers: { Accept: 'application/json' },
          signal: controller.signal,
        });

        if (!response.ok) {
          throw new Error(`categories_http_${response.status}`);
        }

        const payload = (await response.json()) as CategoriesResponse;

        if (!payload.ok || !Array.isArray(payload.data?.tree)) {
          throw new Error('categories_invalid_payload');
        }

        setCategories(payload.data.tree);
        setCategoryState('ready');
      } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') {
          return;
        }

        setCategories([]);
        setCategoryState('error');
      }
    }

    void loadCategories();

    return () => controller.abort();
  }, []);

  const editorialCategories = useMemo(
    () =>
      sortEditorialCategories(
        categories.filter(
          (category) =>
            category.parentId === null &&
            !SYSTEM_CATEGORY_SLUGS.has(category.slug),
        ),
      ),
    [categories],
  );

  const regionalCategory = categories.find(
    (category) => category.slug === 'cobertura-regional',
  );

  const regionalCities = useMemo(
    () =>
      [...(regionalCategory?.children ?? [])].sort((a, b) =>
        a.name.localeCompare(b.name, 'pt-BR'),
      ),
    [regionalCategory],
  );

  return (
    <>
      <div className="demo-notice">
        <span>FUNDAÇÃO VISUAL</span>
        <p>Conteúdo demonstrativo. Nenhum título abaixo representa notícia real.</p>
      </div>

      <header className="site-header">
        <div className="utility">
          <div className="container utility__inner">
            <span>Nosso Jornal</span>
            <div className="utility__links">
              <a href="#sobre">Sobre</a>
              <a href="#classificados">Classificados</a>
              <a href="#comunicados">Comunicados</a>
              <button type="button" className="utility__search" aria-label="Abrir busca">
                Buscar
              </button>
            </div>
          </div>
        </div>

        <div className="masthead">
          <div className="container masthead__inner">
            <Brand />
            <div className="masthead__message">
              <span>Nova experiência editorial</span>
              <strong>Informação local com leitura mais clara, rápida e organizada.</strong>
            </div>
          </div>
        </div>

        <nav className="primary-nav" aria-label="Editorias">
          <div
            className="container primary-nav__scroll"
            aria-busy={categoryState === 'loading'}
          >
            <a href="/ultimas">Últimas</a>

            {editorialCategories.map((category) => (
              <a key={category.id} href={category.url}>
                {category.name}
              </a>
            ))}

            {categoryState === 'loading' && (
              <span className="primary-nav__status" aria-live="polite">
                Carregando editorias…
              </span>
            )}

            {categoryState === 'error' && (
              <span className="primary-nav__status" role="status">
                Editorias temporariamente indisponíveis
              </span>
            )}
          </div>
        </nav>

        {regionalCities.length > 0 && (
          <nav className="regional-nav" aria-label="Cobertura regional">
            <div className="container regional-nav__inner">
              <a className="regional-nav__label" href={regionalCategory?.url}>
                Cobertura Regional
              </a>
              <div className="regional-nav__scroll">
                {regionalCities.map((city) => (
                  <a key={city.id} href={city.url}>
                    {city.name}
                  </a>
                ))}
              </div>
            </div>
          </nav>
        )}

        <div className="breaking">
          <div className="container breaking__inner">
            <strong>EM DESENVOLVIMENTO</strong>
            <span>Esta capa estabelece a base visual que receberá o acervo e as notícias reais.</span>
          </div>
        </div>
      </header>
    </>
  );
}
