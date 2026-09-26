import { FormEvent, useEffect, useMemo, useState } from 'react';
import './pages.css';

type Category = {
  id: number;
  taxonomyId: number;
  name: string;
  slug: string;
  parentId: number | null;
  url: string;
};

type FeaturedImage = {
  url: string;
  alt: string;
};

type Article = {
  id: number;
  title: string;
  slug: string;
  url: string;
  excerpt: string;
  contentHtml?: string;
  publishedAt: string;
  modifiedAt: string;
  author: {
    id: number;
    name: string;
  };
  featuredImage: FeaturedImage | null;
  views: number;
  primaryCategory: Category | null;
  categories: Category[];
};

type ArticlePayload = {
  ok: boolean;
  data?: {
    article: Article;
    related: Article[];
    seo: {
      title: string;
      description: string;
      canonical: string;
    };
  };
};

type ArticlesPayload = {
  ok: boolean;
  data?: {
    items: Article[];
    category: Category | null;
    query: string;
    pagination: {
      page: number;
      perPage: number;
      total: number;
      totalPages: number;
      hasPrevious: boolean;
      hasNext: boolean;
    };
  };
};

type StaticPagePayload = {
  ok: boolean;
  data?: {
    page: {
      id: number;
      title: string;
      slug: string;
      legacySlug: string;
      contentHtml: string;
      excerpt: string;
      modifiedAt: string;
    };
  };
};

export type PublicRoute =
  | { kind: 'home' }
  | { kind: 'article'; slug: string }
  | { kind: 'category'; slug: string }
  | { kind: 'latest' }
  | { kind: 'search' }
  | { kind: 'static'; slug: 'sobre' | 'contato' }
  | { kind: 'service'; slug: 'classificados' | 'comunicados' }
  | { kind: 'not-found' };

export function resolvePublicRoute(pathname: string): PublicRoute {
  const clean = pathname !== '/' ? pathname.replace(/\/+$/, '') : pathname;

  if (clean === '/') return { kind: 'home' };
  if (clean === '/ultimas') return { kind: 'latest' };
  if (clean === '/busca') return { kind: 'search' };
  if (clean === '/sobre' || clean === '/quem-somos') return { kind: 'static', slug: 'sobre' };
  if (clean === '/contato') return { kind: 'static', slug: 'contato' };
  if (clean === '/noticias') return { kind: 'latest' };
  if (clean === '/classificados') return { kind: 'service', slug: 'classificados' };
  if (clean === '/comunicados') return { kind: 'service', slug: 'comunicados' };

  const article = clean.match(/^\/noticia\/([^/]+)$/);
  if (article) return { kind: 'article', slug: decodeURIComponent(article[1]) };

  const category = clean.match(/^\/categoria\/([^/]+)$/);
  if (category) return { kind: 'category', slug: decodeURIComponent(category[1]) };

  const legacyArticle = clean.match(/^\/([^/]+)$/);
  if (legacyArticle) return { kind: 'article', slug: decodeURIComponent(legacyArticle[1]) };

  return { kind: 'not-found' };
}

function ensureMeta(name: string, content: string) {
  let element = document.head.querySelector<HTMLMetaElement>(`meta[name="${name}"]`);
  if (!element) {
    element = document.createElement('meta');
    element.name = name;
    document.head.appendChild(element);
  }
  element.content = content;
}

function ensurePropertyMeta(property: string, content: string) {
  let element = document.head.querySelector<HTMLMetaElement>(`meta[property="${property}"]`);
  if (!element) {
    element = document.createElement('meta');
    element.setAttribute('property', property);
    document.head.appendChild(element);
  }
  element.content = content;
}

function setCanonical(path: string) {
  let element = document.head.querySelector<HTMLLinkElement>('link[rel="canonical"]');
  if (!element) {
    element = document.createElement('link');
    element.rel = 'canonical';
    document.head.appendChild(element);
  }
  element.href = new URL(path, window.location.origin).toString();
}

function usePageMeta(
  title: string,
  description: string,
  canonicalPath: string,
  options?: { type?: 'website' | 'article'; image?: string },
) {
  const type = options?.type ?? 'website';
  const image = options?.image ?? '';

  useEffect(() => {
    if (!title) return;

    const fullTitle = title.includes('Nosso Jornal') ? title : `${title} | Nosso Jornal`;
    document.title = fullTitle;
    ensureMeta('description', description);
    ensurePropertyMeta('og:title', fullTitle);
    ensurePropertyMeta('og:description', description);
    ensurePropertyMeta('og:url', new URL(canonicalPath, window.location.origin).toString());
    ensurePropertyMeta('og:type', type);

    if (image) {
      ensurePropertyMeta('og:image', new URL(image, window.location.origin).toString());
    }

    setCanonical(canonicalPath);
  }, [title, description, canonicalPath, image, type]);
}

function formatDate(value: string, includeTime = true) {
  const normalized = value.includes('T') ? value : value.replace(' ', 'T');
  const date = new Date(normalized);

  if (Number.isNaN(date.getTime())) return value;

  return new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    month: 'long',
    year: 'numeric',
    ...(includeTime ? { hour: '2-digit', minute: '2-digit' } : {}),
  }).format(date);
}

function LoadingState({ label = 'Carregando conteúdo' }: { label?: string }) {
  return (
    <main className="internal-main" aria-busy="true">
      <div className="container internal-loading" aria-label={label}>
        <span />
        <span />
        <span />
      </div>
    </main>
  );
}

function ErrorState({
  title = 'Conteúdo indisponível',
  description = 'Não foi possível carregar esta página agora.',
}: {
  title?: string;
  description?: string;
}) {
  return (
    <main className="internal-main">
      <div className="container internal-state">
        <span className="internal-kicker">Nosso Jornal</span>
        <h1>{title}</h1>
        <p>{description}</p>
        <a href="/">Voltar para a capa</a>
      </div>
    </main>
  );
}

function Breadcrumbs({
  items,
}: {
  items: Array<{ label: string; href?: string }>;
}) {
  return (
    <nav className="internal-breadcrumbs" aria-label="Breadcrumb">
      <ol>
        {items.map((item, index) => (
          <li key={`${item.label}-${index}`}>
            {item.href ? <a href={item.href}>{item.label}</a> : <span>{item.label}</span>}
          </li>
        ))}
      </ol>
    </nav>
  );
}

function ArticleCard({ article }: { article: Article }) {
  return (
    <article className="archive-card">
      <a className="archive-card__media" href={article.url} aria-label={article.title}>
        {article.featuredImage ? (
          <img src={article.featuredImage.url} alt={article.featuredImage.alt} loading="lazy" />
        ) : (
          <span>{article.primaryCategory?.name ?? 'Nosso Jornal'}</span>
        )}
      </a>

      <div className="archive-card__body">
        {article.primaryCategory && (
          <a className="internal-kicker" href={article.primaryCategory.url}>
            {article.primaryCategory.name}
          </a>
        )}
        <h2><a href={article.url}>{article.title}</a></h2>
        {article.excerpt && <p>{article.excerpt}</p>}
        <div className="archive-card__meta">
          <span>{formatDate(article.publishedAt)}</span>
          {article.author.name && <span>{article.author.name}</span>}
        </div>
      </div>
    </article>
  );
}

function Pagination({
  page,
  totalPages,
  hasPrevious,
  hasNext,
}: {
  page: number;
  totalPages: number;
  hasPrevious: boolean;
  hasNext: boolean;
}) {
  if (totalPages <= 1) return null;

  const hrefFor = (target: number) => {
    const params = new URLSearchParams(window.location.search);
    params.set('page', String(target));
    return `${window.location.pathname}?${params.toString()}`;
  };

  return (
    <nav className="archive-pagination" aria-label="Paginação">
      {hasPrevious ? <a href={hrefFor(page - 1)}>← Anterior</a> : <span />}
      <span>Página {page} de {totalPages}</span>
      {hasNext ? <a href={hrefFor(page + 1)}>Próxima →</a> : <span />}
    </nav>
  );
}

function ArticlePage({ slug }: { slug: string }) {
  const [payload, setPayload] = useState<ArticlePayload['data']>();
  const [state, setState] = useState<'loading' | 'ready' | 'error' | 'not-found'>('loading');

  useEffect(() => {
    const controller = new AbortController();

    fetch(`/api/v1/article.php?slug=${encodeURIComponent(slug)}`, {
      headers: { Accept: 'application/json' },
      signal: controller.signal,
    })
      .then(async (response) => {
        if (response.status === 404) {
          setState('not-found');
          return null;
        }
        if (!response.ok) throw new Error('article_request_failed');
        return response.json() as Promise<ArticlePayload>;
      })
      .then((response) => {
        if (!response) return;
        if (!response.ok || !response.data) throw new Error('article_invalid_payload');
        setPayload(response.data);
        setState('ready');
      })
      .catch((error) => {
        if (error instanceof DOMException && error.name === 'AbortError') return;
        setState('error');
      });

    return () => controller.abort();
  }, [slug]);

  const article = payload?.article;
  usePageMeta(
    payload?.seo.title ?? '',
    payload?.seo.description ?? '',
    payload?.seo.canonical ?? `/noticia/${slug}`,
    {
      type: 'article',
      image: article?.featuredImage?.url,
    },
  );

  const jsonLd = useMemo(() => {
    if (!article) return '';

    return JSON.stringify({
      '@context': 'https://schema.org',
      '@type': 'NewsArticle',
      headline: article.title,
      datePublished: article.publishedAt,
      dateModified: article.modifiedAt,
      mainEntityOfPage: new URL(article.url, window.location.origin).toString(),
      image: article.featuredImage
        ? [new URL(article.featuredImage.url, window.location.origin).toString()]
        : undefined,
      author: article.author.name
        ? { '@type': 'Person', name: article.author.name }
        : { '@type': 'Organization', name: 'Nosso Jornal' },
      publisher: {
        '@type': 'Organization',
        name: 'Nosso Jornal',
        url: window.location.origin,
        logo: {
          '@type': 'ImageObject',
          url: new URL('/nosso-jornal-hulha-negra-bage.png', window.location.origin).toString(),
        },
      },
      articleSection: article.primaryCategory?.name,
      description: article.excerpt,
    });
  }, [article]);

  if (state === 'loading') return <LoadingState label="Carregando matéria" />;
  if (state === 'not-found') return <ErrorState title="Matéria não encontrada" description="A notícia pode ter mudado de endereço ou não estar mais publicada." />;
  if (state === 'error' || !article) return <ErrorState />;

  const shareUrl = new URL(article.url, window.location.origin).toString();
  const whatsappUrl = `https://wa.me/?text=${encodeURIComponent(`${article.title} ${shareUrl}`)}`;

  return (
    <main className="internal-main article-page">
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLd }} />

      <div className="container article-shell">
        <Breadcrumbs
          items={[
            { label: 'Capa', href: '/' },
            ...(article.primaryCategory
              ? [{ label: article.primaryCategory.name, href: article.primaryCategory.url }]
              : []),
            { label: article.title },
          ]}
        />

        <article className="article-detail">
          <header className="article-detail__header">
            {article.primaryCategory && (
              <a className="internal-kicker" href={article.primaryCategory.url}>
                {article.primaryCategory.name}
              </a>
            )}

            <h1>{article.title}</h1>
            {article.excerpt && <p className="article-detail__deck">{article.excerpt}</p>}

            <div className="article-detail__byline">
              <div>
                <strong>{article.author.name || 'Nosso Jornal'}</strong>
                <span>Publicado em {formatDate(article.publishedAt)}</span>
                {article.modifiedAt !== article.publishedAt && (
                  <span>Atualizado em {formatDate(article.modifiedAt)}</span>
                )}
              </div>

              <a
                href={whatsappUrl}
                target="_blank"
                rel="noopener noreferrer"
                aria-label="Compartilhar esta notícia no WhatsApp em nova aba"
              >
                Compartilhar
              </a>
            </div>
          </header>

          {article.featuredImage && (
            <figure className="article-detail__hero">
              <img src={article.featuredImage.url} alt={article.featuredImage.alt} />
            </figure>
          )}

          <div className="article-detail__layout">
            <div
              className="article-body"
              dangerouslySetInnerHTML={{ __html: article.contentHtml ?? '' }}
            />

            <aside className="article-detail__aside">
              <span className="internal-kicker">Editorias</span>
              <div className="article-detail__categories">
                {article.categories.map((category) => (
                  <a href={category.url} key={category.id}>{category.name}</a>
                ))}
              </div>
            </aside>
          </div>
        </article>

        {(payload?.related ?? []).length > 0 && (
          <section className="related-news" aria-labelledby="related-title">
            <div className="internal-section-heading">
              <span>Continue lendo</span>
              <h2 id="related-title">Notícias relacionadas</h2>
            </div>
            <div className="related-news__grid">
              {payload?.related.map((related) => (
                <ArticleCard article={related} key={related.id} />
              ))}
            </div>
          </section>
        )}
      </div>
    </main>
  );
}

function ArchivePage({
  categorySlug,
  searchQuery,
  mode,
}: {
  categorySlug?: string;
  searchQuery?: string;
  mode: 'category' | 'latest' | 'search';
}) {
  const params = new URLSearchParams(window.location.search);
  const currentPage = Math.max(1, Number.parseInt(params.get('page') ?? '1', 10) || 1);
  const [payload, setPayload] = useState<ArticlesPayload['data']>();
  const [state, setState] = useState<'loading' | 'ready' | 'error' | 'not-found'>('loading');

  useEffect(() => {
    if (mode === 'search' && !searchQuery) {
      setPayload({
        items: [],
        category: null,
        query: '',
        pagination: {
          page: 1,
          perPage: 12,
          total: 0,
          totalPages: 1,
          hasPrevious: false,
          hasNext: false,
        },
      });
      setState('ready');
      return;
    }

    const query = new URLSearchParams({
      page: String(currentPage),
      per_page: '12',
    });

    if (categorySlug) query.set('category', categorySlug);
    if (searchQuery) query.set('q', searchQuery);

    const controller = new AbortController();

    fetch(`/api/v1/articles.php?${query.toString()}`, {
      headers: { Accept: 'application/json' },
      signal: controller.signal,
    })
      .then(async (response) => {
        if (response.status === 404) {
          setState('not-found');
          return null;
        }
        if (!response.ok) throw new Error('archive_request_failed');
        return response.json() as Promise<ArticlesPayload>;
      })
      .then((response) => {
        if (!response) return;
        if (!response.ok || !response.data) throw new Error('archive_invalid_payload');
        setPayload(response.data);
        setState('ready');
      })
      .catch((error) => {
        if (error instanceof DOMException && error.name === 'AbortError') return;
        setState('error');
      });

    return () => controller.abort();
  }, [categorySlug, currentPage, mode, searchQuery]);

  const title =
    mode === 'category'
      ? payload?.category?.name ?? 'Editoria'
      : mode === 'search'
        ? searchQuery
          ? `Busca por “${searchQuery}”`
          : 'Buscar no Nosso Jornal'
        : 'Últimas notícias';

  const description =
    mode === 'category'
      ? `Notícias publicadas na editoria ${payload?.category?.name ?? ''} do Nosso Jornal.`
      : mode === 'search'
        ? searchQuery
          ? `Resultados de busca para ${searchQuery} no Nosso Jornal.`
          : 'Pesquise no acervo de notícias do Nosso Jornal.'
        : 'As notícias mais recentes publicadas pelo Nosso Jornal.';

  usePageMeta(title, description, window.location.pathname + window.location.search);

  if (state === 'loading') return <LoadingState />;
  if (state === 'not-found') return <ErrorState title="Editoria não encontrada" />;
  if (state === 'error' || !payload) return <ErrorState />;

  return (
    <main className="internal-main archive-page">
      <div className="container">
        <Breadcrumbs
          items={[
            { label: 'Capa', href: '/' },
            { label: title },
          ]}
        />

        <header className="archive-header">
          <span className="internal-kicker">
            {mode === 'category' ? 'Editoria' : mode === 'search' ? 'Pesquisa' : 'Atualização'}
          </span>
          <h1>{title}</h1>
          <p>{description}</p>

          {mode === 'search' && <SearchForm initialQuery={searchQuery ?? ''} />}
        </header>

        {payload.items.length > 0 ? (
          <>
            <div className="archive-grid">
              {payload.items.map((article) => (
                <ArticleCard article={article} key={article.id} />
              ))}
            </div>

            <Pagination {...payload.pagination} />
          </>
        ) : (
          <div className="archive-empty">
            <strong>Nenhuma notícia encontrada.</strong>
            <p>
              {mode === 'search'
                ? 'Tente outro termo ou consulte as últimas notícias.'
                : 'Ainda não há publicações disponíveis nesta seção.'}
            </p>
            <a href="/ultimas">Ver últimas notícias</a>
          </div>
        )}
      </div>
    </main>
  );
}

function SearchForm({ initialQuery = '' }: { initialQuery?: string }) {
  const [query, setQuery] = useState(initialQuery);

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const normalized = query.trim();
    window.location.href = normalized ? `/busca?q=${encodeURIComponent(normalized)}` : '/busca';
  }

  return (
    <form className="internal-search" role="search" onSubmit={submit}>
      <label htmlFor="site-search">Buscar notícias</label>
      <div>
        <input
          id="site-search"
          type="search"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          placeholder="Digite uma palavra, assunto ou local"
        />
        <button type="submit">Buscar</button>
      </div>
    </form>
  );
}

function StaticPage({ slug }: { slug: 'sobre' | 'contato' }) {
  const [payload, setPayload] = useState<StaticPagePayload['data']>();
  const [state, setState] = useState<'loading' | 'ready' | 'error' | 'not-found'>('loading');

  useEffect(() => {
    const controller = new AbortController();

    fetch(`/api/v1/page.php?slug=${slug}`, {
      headers: { Accept: 'application/json' },
      signal: controller.signal,
    })
      .then(async (response) => {
        if (response.status === 404) {
          setState('not-found');
          return null;
        }
        if (!response.ok) throw new Error('page_request_failed');
        return response.json() as Promise<StaticPagePayload>;
      })
      .then((response) => {
        if (!response) return;
        if (!response.ok || !response.data) throw new Error('page_invalid_payload');
        setPayload(response.data);
        setState('ready');
      })
      .catch((error) => {
        if (error instanceof DOMException && error.name === 'AbortError') return;
        setState('error');
      });

    return () => controller.abort();
  }, [slug]);

  const page = payload?.page;
  usePageMeta(page?.title ?? '', page?.excerpt ?? '', `/${slug}`);

  if (state === 'loading') return <LoadingState />;
  if (state === 'not-found') return <ErrorState title="Página não encontrada" />;
  if (state === 'error' || !page) return <ErrorState />;

  return (
    <main className="internal-main static-page">
      <div className="container static-page__shell">
        <Breadcrumbs items={[{ label: 'Capa', href: '/' }, { label: page.title }]} />

        <header className="static-page__header">
          <span className="internal-kicker">Nosso Jornal</span>
          <h1>{page.title}</h1>
          {page.excerpt && <p>{page.excerpt}</p>}
        </header>

        <div className="static-page__body" dangerouslySetInnerHTML={{ __html: page.contentHtml }} />
      </div>
    </main>
  );
}

function ServicePage({ slug }: { slug: 'classificados' | 'comunicados' }) {
  const isClassifieds = slug === 'classificados';
  const title = isClassifieds ? 'Classificados' : 'Comunicados';
  const description = isClassifieds
    ? 'Área de classificados do Nosso Jornal.'
    : 'Área de comunicados e publicações oficiais do Nosso Jornal.';

  usePageMeta(title, description, `/${slug}`);

  return (
    <main className="internal-main">
      <div className="container internal-state internal-state--service">
        <span className="internal-kicker">Serviço</span>
        <h1>{title}</h1>
        <p>
          Esta página já faz parte da nova estrutura do portal. Os registros serão exibidos
          aqui assim que a fonte específica deste produto editorial for normalizada no banco.
        </p>
        <a href="/">Voltar para a capa</a>
      </div>
    </main>
  );
}

function NotFoundPage() {
  usePageMeta('Página não encontrada', 'A página solicitada não foi encontrada no Nosso Jornal.', window.location.pathname);

  return (
    <ErrorState
      title="Página não encontrada"
      description="O endereço informado não corresponde a uma página publicada no novo portal."
    />
  );
}

export function InternalPage({ route }: { route: Exclude<PublicRoute, { kind: 'home' }> }) {
  if (route.kind === 'article') return <ArticlePage slug={route.slug} />;
  if (route.kind === 'category') return <ArchivePage mode="category" categorySlug={route.slug} />;
  if (route.kind === 'latest') return <ArchivePage mode="latest" />;
  if (route.kind === 'search') {
    const query = new URLSearchParams(window.location.search).get('q')?.trim() ?? '';
    return <ArchivePage mode="search" searchQuery={query} />;
  }
  if (route.kind === 'static') return <StaticPage slug={route.slug} />;
  if (route.kind === 'service') return <ServicePage slug={route.slug} />;
  return <NotFoundPage />;
}
