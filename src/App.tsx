import { useCallback, useEffect, useMemo, useState } from 'react';
import type { CSSProperties } from 'react';
import { SiteHeader } from './Header';
import { InternalPage, resolvePublicRoute } from './InternalPages';
import { SiteFooter } from './SiteFooter';
import { AdminApp } from './AdminApp';
import { cleanLegacyText } from './contentText';
import './home.css';

type Category = {
  id: number;
  taxonomyId: number;
  name: string;
  slug: string;
  parentId: number | null;
  url: string;
  color: string;
};

type FeaturedImage = {
  url: string;
  alt: string;
  width: number | null;
  height: number | null;
  srcSet: string;
};

type Article = {
  id: number;
  title: string;
  homeHeadline?: string;
  slug: string;
  url: string;
  excerpt: string;
  publishedAt: string;
  modifiedAt: string;
  author: {
    id: number;
    name: string;
    slug: string;
    url: string | null;
  };
  featuredImage: FeaturedImage | null;
  views: number;
  primaryCategory: Category | null;
  categories: Category[];
};

type HomeSection = {
  category: Category;
  stories: Article[];
};

type HomePayload = {
  ok: boolean;
  data?: {
    hero: Article | null;
    latest: Article[];
    mostRead: Article[];
    sections: HomeSection[];
    summary: {
      publishedArticles: number;
      sectionCount: number;
      heroSelection: 'capa_category' | 'latest_fallback' | 'none';
    };
  };
};

function formatPublishedAt(value: string) {
  const normalized = value.includes('T') ? value : value.replace(' ', 'T');
  const date = new Date(normalized);

  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    timeZone: 'America/Sao_Paulo',
  }).format(date);
}

function formatViews(value: number) {
  return new Intl.NumberFormat('pt-BR').format(value);
}

function homeTitle(article: Article) {
  const override = cleanLegacyText(article.homeHeadline ?? '');
  const original = cleanLegacyText(article.title);

  return override || original || 'Notícia';
}

function StoryMedia({
  article,
  className = '',
  sizes = '(max-width: 720px) 100vw, 33vw',
  priority = false,
}: {
  article: Article;
  className?: string;
  sizes?: string;
  priority?: boolean;
}) {
  const category = article.primaryCategory?.name ?? 'Nosso Jornal';

  if (article.featuredImage) {
    return (
      <div className={`home-media ${className}`}>
        <img
          src={article.featuredImage.url}
          srcSet={article.featuredImage.srcSet || undefined}
          sizes={article.featuredImage.srcSet ? sizes : undefined}
          width={article.featuredImage.width ?? undefined}
          height={article.featuredImage.height ?? undefined}
          alt={article.featuredImage.alt}
          loading={priority ? 'eager' : 'lazy'}
        />
      </div>
    );
  }

  return (
    <div className={`home-media home-media--fallback ${className}`} aria-hidden="true">
      <span>{category}</span>
    </div>
  );
}

function StoryMeta({ article, showViews = false }: { article: Article; showViews?: boolean }) {
  return (
    <div className="home-story-meta">
      <span className="home-story-meta__date">{formatPublishedAt(article.publishedAt)}</span>
      {article.author.name && (
        article.author.url
          ? <a className="home-story-meta__author" href={article.author.url}>{article.author.name}</a>
          : <span className="home-story-meta__author">{article.author.name}</span>
      )}
      {showViews && article.views > 0 && (
        <span className="home-story-meta__views">{formatViews(article.views)} visualizações</span>
      )}
    </div>
  );
}

function editorialContrastColor(color?: string) {
  const normalized = color?.trim().replace('#', '') ?? '';

  if (!/^[0-9a-fA-F]{6}$/.test(normalized)) {
    return '#FFFFFF';
  }

  const red = Number.parseInt(normalized.slice(0, 2), 16);
  const green = Number.parseInt(normalized.slice(2, 4), 16);
  const blue = Number.parseInt(normalized.slice(4, 6), 16);
  const luminance = (red * 299 + green * 587 + blue * 114) / 1000;

  return luminance > 168 ? '#111827' : '#FFFFFF';
}

function editorialStyle(color?: string) {
  return color
    ? ({
        '--editorial-color': color,
        '--editorial-contrast': editorialContrastColor(color),
      } as CSSProperties)
    : undefined;
}

function CategoryBadge({
  category,
  inheritColor = false,
}: {
  category: Category | null;
  inheritColor?: boolean;
}) {
  if (!category) {
    return <span className="home-eyebrow">Nosso Jornal</span>;
  }

  return (
    <a
      className="home-eyebrow"
      href={category.url}
      style={inheritColor ? undefined : editorialStyle(category.color)}
    >
      {category.name}
    </a>
  );
}

function HomeSkeleton() {
  return (
    <main className="home-main" aria-busy="true" aria-label="Carregando homepage">
      <section className="container home-skeleton">
        <div className="home-skeleton__hero" />
        <div className="home-skeleton__rail">
          <span />
          <span />
          <span />
          <span />
        </div>
      </section>
    </main>
  );
}

function PublicSite() {
  const route = resolvePublicRoute(window.location.pathname);
  const [data, setData] = useState<HomePayload['data']>();
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');

  const loadHome = useCallback(async () => {
    setState('loading');

    try {
      const response = await fetch('/api/v1/home.php', {
        headers: { Accept: 'application/json' },
      });

      if (!response.ok) {
        throw new Error(`home_http_${response.status}`);
      }

      const payload = (await response.json()) as HomePayload;

      if (!payload.ok || !payload.data) {
        throw new Error('home_invalid_payload');
      }

      setData(payload.data);
      setState('ready');
    } catch {
      setData(undefined);
      setState('error');
    }
  }, []);

  useEffect(() => {
    if (route.kind !== 'home') {
      return;
    }

    void loadHome();
  }, [loadHome, route.kind]);

  const hero = data?.hero ?? null;
  const latest = data?.latest ?? [];
  const mostRead = data?.mostRead ?? [];
  const sections = data?.sections ?? [];

  const heroSide = useMemo(() => latest.slice(0, 4), [latest]);

  if (route.kind !== 'home') {
    return (
      <div className="site-shell">
        <SiteHeader />
        <InternalPage route={route} />
        <SiteFooter />
      </div>
    );
  }

  return (
    <div className="site-shell">
      <SiteHeader />

      {state === 'loading' && <HomeSkeleton />}

      {state === 'error' && (
        <main className="home-main">
          <section className="container home-error">
            <span className="home-eyebrow">Nosso Jornal</span>
            <h1>Não foi possível carregar a capa agora.</h1>
            <p>A conexão editorial pode ser tentada novamente sem recarregar a página.</p>
            <button type="button" onClick={() => void loadHome()}>
              Tentar novamente
            </button>
          </section>
        </main>
      )}

      {state === 'ready' && hero && (
        <main className="home-main">
          <section className="container home-cover" aria-labelledby="home-cover-title">
            <article
              className="home-hero"
              style={editorialStyle(hero.primaryCategory?.color)}
            >
              <a href={hero.url} className="home-hero__media-link" aria-label={homeTitle(hero)}>
                <StoryMedia
                  article={hero}
                  className="home-hero__media"
                  sizes="(max-width: 980px) 100vw, 70vw"
                  priority
                />
              </a>

              <div className="home-hero__content">
                <div className="home-hero__kicker">
                  <span>Capa do dia</span>
                  <CategoryBadge category={hero.primaryCategory} />
                </div>

                <h1 id="home-cover-title">
                  <a href={hero.url}>{homeTitle(hero)}</a>
                </h1>

                {cleanLegacyText(hero.excerpt) && <p>{cleanLegacyText(hero.excerpt)}</p>}
                <StoryMeta article={hero} />
              </div>
            </article>

            <aside className="home-cover__rail" aria-label="Últimas notícias">
              <div className="home-section-heading home-section-heading--compact">
                <div>
                  <span>Agora</span>
                  <h2>Últimas notícias</h2>
                </div>
                <a href="/ultimas">Ver todas</a>
              </div>

              <div className="home-cover__latest">
                {heroSide.map((article, index) => (
                  <article
                    className="home-compact-story"
                    key={article.id}
                    style={editorialStyle(article.primaryCategory?.color)}
                  >
                    <span className="home-compact-story__index">
                      {String(index + 1).padStart(2, '0')}
                    </span>
                    <div>
                      <CategoryBadge category={article.primaryCategory} />
                      <h3>
                        <a href={article.url}>{homeTitle(article)}</a>
                      </h3>
                      <StoryMeta article={article} />
                    </div>
                  </article>
                ))}
              </div>
            </aside>
          </section>

          <section className="container home-latest" aria-labelledby="latest-title">
            <div className="home-section-heading">
              <div>
                <span>Atualização</span>
                <h2 id="latest-title">Últimas notícias</h2>
              </div>
              <a href="/ultimas">Acompanhar todas</a>
            </div>

            <div className="home-latest__layout">
              <div className="home-latest__grid">
                {latest.map((article) => (
                  <article
                    className="home-story-card"
                    key={article.id}
                    style={editorialStyle(article.primaryCategory?.color)}
                  >
                    <a href={article.url} aria-label={homeTitle(article)}>
                      <StoryMedia
                        article={article}
                        className="home-story-card__media"
                        sizes="(max-width: 720px) 100vw, (max-width: 1100px) 50vw, 360px"
                      />
                    </a>

                    <div className="home-story-card__body">
                      <CategoryBadge category={article.primaryCategory} />
                      <h3>
                        <a href={article.url}>{homeTitle(article)}</a>
                      </h3>
                      {cleanLegacyText(article.excerpt) && <p>{cleanLegacyText(article.excerpt)}</p>}
                      <StoryMeta article={article} />
                    </div>
                  </article>
                ))}
              </div>

              {mostRead.length > 0 && (
                <aside className="home-most-read" aria-labelledby="most-read-title">
                  <div className="home-most-read__heading">
                    <span>Leitura</span>
                    <h2 id="most-read-title">Mais lidas</h2>
                  </div>

                  <ol>
                    {mostRead.map((article) => (
                      <li
                        key={article.id}
                        style={editorialStyle(article.primaryCategory?.color)}
                      >
                        <div>
                          <CategoryBadge category={article.primaryCategory} />
                          <h3>
                            <a href={article.url}>{homeTitle(article)}</a>
                          </h3>
                          <StoryMeta article={article} showViews />
                        </div>
                      </li>
                    ))}
                  </ol>
                </aside>
              )}
            </div>
          </section>

          {sections.map((section, sectionIndex) => (
            <section
              className={`home-category-section ${sectionIndex % 2 === 1 ? 'home-category-section--alt' : ''}`}
              key={section.category.id}
              aria-labelledby={`section-${section.category.slug}`}
              style={editorialStyle(section.category.color)}
            >
              <div className="container">
                <div className="home-section-heading home-section-heading--editorial">
                  <div className="home-section-heading__identity">
                    <span>Editoria</span>
                    <h2 id={`section-${section.category.slug}`}>
                      <a href={section.category.url}>{section.category.name}</a>
                    </h2>
                  </div>
                  <a className="home-section-heading__cta" href={section.category.url}>
                    Ver editoria
                    <span aria-hidden="true">→</span>
                  </a>
                </div>

                <div className="home-category-grid">
                  {section.stories.map((article, index) => (
                    <article
                      className={`home-category-card ${
                        index === 0
                          ? 'home-category-card--lead'
                          : index <= 2
                            ? 'home-category-card--secondary'
                            : 'home-category-card--latest'
                      }`}
                      key={article.id}
                      style={editorialStyle(section.category.color)}
                    >
                      <a href={article.url} aria-label={homeTitle(article)}>
                        <StoryMedia
                          article={article}
                          className="home-category-card__media"
                          sizes={
                            index === 0
                              ? '(max-width: 720px) 100vw, (max-width: 1100px) 58vw, 640px'
                              : index <= 2
                                ? '(max-width: 720px) 132px, (max-width: 1100px) 50vw, 300px'
                                : '(max-width: 720px) 96px, (max-width: 1100px) 220px, 260px'
                          }
                        />
                      </a>

                      <div className="home-category-card__body">
                        <CategoryBadge category={article.primaryCategory} inheritColor />
                        <h3>
                          <a href={article.url}>{homeTitle(article)}</a>
                        </h3>
                        {index === 0 && cleanLegacyText(article.excerpt) && (
                          <p>{cleanLegacyText(article.excerpt)}</p>
                        )}
                        <StoryMeta article={article} />
                      </div>
                    </article>
                  ))}
                </div>
              </div>
            </section>
          ))}
        </main>
      )}

      <SiteFooter />
    </div>
  );
}


export function App() {
  const isAdmin = window.location.pathname === '/sistema'
    || window.location.pathname.startsWith('/sistema/');

  return isAdmin ? <AdminApp /> : <PublicSite />;
}
