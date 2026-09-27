import { useEffect, useMemo, useState } from 'react';
import type { CSSProperties } from 'react';
import {
  loadNavigation,
  navigationLinkProps,
  selectNavigationMenu,
  type NavigationItem,
  type NavigationMenu,
} from './navigation';
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
  color: string;
  children?: Category[];
};

type LatestStory = {
  id: number;
  title: string;
  slug: string;
  url: string;
  publishedAt: string;
  modifiedAt: string;
  primaryCategory: {
    name: string;
    slug: string;
    url: string;
    color: string;
  } | null;
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
  const timeZone = 'America/Sao_Paulo';

  const label = new Intl.DateTimeFormat('pt-BR', {
    weekday: 'long',
    day: '2-digit',
    month: 'long',
    year: 'numeric',
    timeZone,
  }).format(now);

  const isoParts = new Intl.DateTimeFormat('en-CA', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    timeZone,
  }).formatToParts(now);

  const part = (type: 'year' | 'month' | 'day') =>
    isoParts.find((item) => item.type === type)?.value ?? '';

  return {
    iso: `${part('year')}-${part('month')}-${part('day')}`,
    label: label.charAt(0).toUpperCase() + label.slice(1),
  };
}

function navigationPath(url: string) {
  if (!url.startsWith('/')) return '';
  return url.split(/[?#]/, 1)[0].replace(/\/+$/, '') || '/';
}

function isNewsNavigationItem(item: NavigationItem) {
  const normalizedTitle = item.title
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .trim()
    .toLocaleLowerCase('pt-BR');
  const path = navigationPath(item.url);

  return normalizedTitle === 'noticias' || path === '/noticias';
}

function NativePrimaryItem({
  item,
  currentPath,
  categoryColorByPath,
  dynamicCategories = [],
}: {
  item: NavigationItem;
  currentPath: string;
  categoryColorByPath: Map<string, string>;
  dynamicCategories?: Category[];
}) {
  const [submenuOpen, setSubmenuOpen] = useState(false);
  const itemPath = navigationPath(item.url);
  const active = itemPath !== '' && currentPath === itemPath;
  const useDynamicCategories = dynamicCategories.length > 0;
  const hasChildren = useDynamicCategories || item.children.length > 0;
  const descendantActive = useDynamicCategories
    ? dynamicCategories.some((category) => navigationPath(category.url) === currentPath)
    : item.children.some((child) => {
        const childPath = navigationPath(child.url);
        return childPath !== '' && childPath === currentPath;
      });
  const itemColor = categoryColorByPath.get(itemPath);

  return (
    <div
      className={'primary-nav__native-item' + (hasChildren ? ' has-children' : '')}
      data-active={active || descendantActive ? 'true' : undefined}
      data-open={submenuOpen ? 'true' : undefined}
      onBlur={(event) => {
        if (!event.currentTarget.contains(event.relatedTarget as Node | null)) {
          setSubmenuOpen(false);
        }
      }}
      onKeyDown={(event) => {
        if (event.key === 'Escape') {
          setSubmenuOpen(false);
        }
      }}
    >
      <a
        href={item.url}
        aria-current={active ? 'page' : undefined}
        style={itemColor ? ({ '--category-color': itemColor } as CSSProperties) : undefined}
        {...navigationLinkProps(item)}
      >
        {item.title}
      </a>

      {hasChildren && (
        <>
          <button
            className="primary-nav__submenu-toggle"
            type="button"
            aria-label={`${submenuOpen ? 'Fechar' : 'Abrir'} submenu de ${item.title}`}
            aria-expanded={submenuOpen}
            onClick={() => setSubmenuOpen((open) => !open)}
          >
            <span aria-hidden="true" />
          </button>

          <div className="primary-nav__submenu" aria-label={`Submenu de ${item.title}`}>
            {useDynamicCategories
              ? dynamicCategories.map((category) => {
                  const categoryPath = navigationPath(category.url);
                  const categoryActive = categoryPath !== '' && currentPath === categoryPath;

                  return (
                    <a
                      key={category.id}
                      href={category.url}
                      aria-current={categoryActive ? 'page' : undefined}
                      style={{ '--category-color': category.color } as CSSProperties}
                    >
                      {category.name}
                    </a>
                  );
                })
              : item.children.map((child) => {
                  const childPath = navigationPath(child.url);
                  const childActive = childPath !== '' && currentPath === childPath;
                  const childColor = categoryColorByPath.get(childPath);

                  return (
                    <a
                      key={child.id}
                      href={child.url}
                      aria-current={childActive ? 'page' : undefined}
                      style={childColor ? ({ '--category-color': childColor } as CSSProperties) : undefined}
                      {...navigationLinkProps(child)}
                    >
                      {child.title}
                    </a>
                  );
                })}
          </div>
        </>
      )}
    </div>
  );
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
          style={story.primaryCategory?.color
            ? ({ '--story-color': story.primaryCategory.color } as CSSProperties)
            : undefined}
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
  const [navigationMenus, setNavigationMenus] = useState<NavigationMenu[]>([]);
  const [categoryState, setCategoryState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [latestState, setLatestState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [navigationState, setNavigationState] = useState<'loading' | 'ready' | 'error'>('loading');
  const editionDate = useMemo(formatEditionDate, []);
  const currentPath = window.location.pathname.replace(/\/+$/, '') || '/';

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

    async function loadNativeNavigation() {
      try {
        const menus = await loadNavigation();
        if (controller.signal.aborted) return;

        setNavigationMenus(menus);
        setNavigationState('ready');
      } catch {
        if (controller.signal.aborted) return;

        setNavigationMenus([]);
        setNavigationState('error');
      }
    }

    void Promise.all([loadCategories(), loadLatestStories(), loadNativeNavigation()]);

    return () => controller.abort();
  }, []);

  const editorialCategories = useMemo(
    () =>
      sortEditorialCategories(
        categories.filter(
          (category) =>
            category.parentId === null &&
            category.publishedCount > 0 &&
            !SYSTEM_CATEGORY_SLUGS.has(category.slug),
        ),
      ),
    [categories],
  );

  const categoryColorByPath = useMemo(() => {
    const colors = new Map<string, string>();

    const visit = (items: Category[]) => {
      items.forEach((category) => {
        const path = navigationPath(category.url);
        if (path && category.color) {
          colors.set(path, category.color);
        }

        if (category.children?.length) {
          visit(category.children);
        }
      });
    };

    visit(categories);
    return colors;
  }, [categories]);

  const primaryMenu = useMemo(
    () => selectNavigationMenu(navigationMenus, 'primary'),
    [navigationMenus],
  );

  const utilityMenu = useMemo(
    () => selectNavigationMenu(navigationMenus, 'utility'),
    [navigationMenus],
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
            {utilityMenu ? (
              utilityMenu.items.map((item) => (
                <a
                  key={item.id}
                  href={item.url}
                  {...navigationLinkProps(item)}
                >
                  {item.title}
                </a>
              ))
            ) : (
              <>
                <a href="/sobre">Sobre</a>
                <a href="/classificados">Classificados</a>
                <a href="/comunicados">Comunicados</a>
                <a href="/contato">Contato</a>
                <a href="/busca" className="utility__search" aria-label="Buscar no Nosso Jornal">
                  Buscar
                </a>
              </>
            )}
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
          className={
            'container primary-nav__scroll'
            + (primaryMenu ? ' primary-nav__scroll--native' : '')
          }
          aria-busy={
            primaryMenu
              ? navigationState === 'loading'
              : categoryState === 'loading'
          }
        >
          {primaryMenu ? (
            primaryMenu.items.map((item) => (
              <NativePrimaryItem
                key={item.id}
                item={item}
                currentPath={currentPath}
                categoryColorByPath={categoryColorByPath}
                dynamicCategories={isNewsNavigationItem(item) ? editorialCategories : undefined}
              />
            ))
          ) : (
            <>
              <a href="/ultimas">Últimas</a>

              {editorialCategories.map((category) => {
                const active = currentPath === category.url;

                return (
                  <a
                    key={category.id}
                    href={category.url}
                    className="primary-nav__category"
                    aria-current={active ? 'page' : undefined}
                    style={{ '--category-color': category.color } as CSSProperties}
                  >
                    {category.name}
                  </a>
                );
              })}

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
            </>
          )}
        </div>
      </nav>

      {regionalCities.length > 0 && (
        <nav className="regional-nav" aria-label="Cobertura regional">
          <div className="container regional-nav__inner">
            <a
              className="regional-nav__label"
              href={regionalCategory?.url}
              aria-current={currentPath === regionalCategory?.url ? 'page' : undefined}
              style={regionalCategory?.color
                ? ({ '--category-color': regionalCategory.color } as CSSProperties)
                : undefined}
            >
              Cobertura Regional
            </a>
            <div className="regional-nav__scroll">
              {regionalCities.map((city) => {
                const active = currentPath === city.url;

                return (
                  <a
                    key={city.id}
                    href={city.url}
                    className="regional-nav__category"
                    aria-current={active ? 'page' : undefined}
                    style={{ '--category-color': city.color } as CSSProperties}
                  >
                    {city.name}
                  </a>
                );
              })}
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
