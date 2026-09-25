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

type LatestStory = {
  id: number;
  title: string;
  slug: string;
  url: string;
  publishedAt: string;
  modifiedAt: string;
};

type CategoriesResponse = {
  ok: boolean;
  data?: {
    tree?: Category[];
  };
};

type LatestResponse = {
  ok: boolean;
  data?: {
    items?: LatestStory[];
  };
};

const SYSTEM_CATEGORY_SLUGS = new Set([
  'capa',
  'geral',
  'outros',
  'eleicoes-2024',
  'cobertura-regional',
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

function formatEditionDate() {
  const now = new Date();
  const label = new Intl.DateTimeFormat('pt-BR', {
    weekday: 'long',
    day: '2-digit',
    month: 'long',
    year: 'numeric',
  }).format(now);

  return {
    iso: now.toISOString().slice(0, 10),
    label: label.charAt(0).toUpperCase() + label.slice(1),
  };
}

function TickerGroup({
  stories,
  duplicate = false,
}: {
  stories: LatestStory[];
  duplicate?: boolean;
}) {
  return (
    <div className="news-ticker__group" aria-hidden={duplicate || undefined}>
      {stories.map((story) => (
        <a
          key={story.id}
          href={story.url}
          className="news-ticker__item"
          tabIndex={duplicate ? -1 : undefined}
        >
          <span className="news-ticker__dot" aria-hidden="true" />
          <span>{story.title}</span>
        </a>
      ))}
    </div>
  );
}

export function Brand() {
  return (
    <a className="brand" href="/" aria-label="Nosso Jornal - página inicial">
      <img
        src="/nosso-jornal-hulha-negra-bage.png"
        alt="Nosso Jornal"
        className="brand__wordmark"
      />
    </a>
  );
}

export function SiteHeader() {
  const [categories, setCategories] = useState<Category[]>([]);
  const [latestStories, setLatestStories] = useState<LatestStory[]>([]);
  const [categoryState, setCategoryState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [latestState, setLatestState] = useState<'loading' | 'ready' | 'error'>('loading');
  const editionDate = useMemo(formatEditionDate, []);

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

    async function loadLatestStories() {
      try {
        const response = await fetch('/api/v1/latest.php?limit=6', {
          headers: { Accept: 'application/json' },
          signal: controller.signal,
        });

        if (!response.ok) {
          throw new Error(`latest_http_${response.status}`);
        }

        const payload = (await response.json()) as LatestResponse;

        if (!payload.ok || !Array.isArray(payload.data?.items)) {
          throw new Error('latest_invalid_payload');
        }

        setLatestStories(payload.data.items.slice(0, 6));
        setLatestState('ready');
      } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') {
          return;
        }

        setLatestStories([]);
        setLatestState('error');
      }
    }

    void Promise.all([loadCategories(), loadLatestStories()]);

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
    <header className="site-header">
      <div className="utility">
        <div className="container utility__inner">
          <div className="utility__edition">
            <strong>Hulha Negra e região</strong>
            <time dateTime={editionDate.iso}>{editionDate.label}</time>
          </div>

          <nav className="utility__links" aria-label="Links institucionais">
            <a href="/sobre">Sobre</a>
            <a href="/classificados">Classificados</a>
            <a href="/comunicados">Comunicados</a>
            <a href="/contato">Contato</a>
            <a href="/busca" className="utility__search" aria-label="Buscar no Nosso Jornal">
              Buscar
            </a>
          </nav>
        </div>
      </div>

      <div className="masthead">
        <div className="container masthead__inner">
          <Brand />
          <div className="masthead__message">
            <span>Jornalismo local • cobertura regional</span>
            <strong>Informação de Hulha Negra, da região e do Rio Grande do Sul.</strong>
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

      <section className="news-ticker" aria-label="Últimas notícias">
        <div className="container news-ticker__inner">
          <a className="news-ticker__label" href="/ultimas">
            Últimas
          </a>

          <div className="news-ticker__viewport">
            {latestStories.length > 0 ? (
              <div className="news-ticker__track">
                <TickerGroup stories={latestStories} />
                <TickerGroup stories={latestStories} duplicate />
              </div>
            ) : (
              <div className="news-ticker__fallback" aria-live="polite">
                {latestState === 'loading' ? (
                  <span>Carregando as notícias mais recentes…</span>
                ) : (
                  <a href="/ultimas">Acompanhe as últimas notícias do Nosso Jornal</a>
                )}
              </div>
            )}
          </div>
        </div>
      </section>
    </header>
  );
}
