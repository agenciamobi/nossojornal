import { FormEvent, useCallback, useEffect, useMemo, useState } from 'react';
import './admin.css';

type AdminUser = {
  id: number;
  login: string;
  email: string;
  displayName: string;
  registeredAt: string;
  roles: string[];
  capabilities: string[];
  permissions: {
    editPosts: boolean;
    publishPosts: boolean;
    manageCategories: boolean;
    uploadFiles: boolean;
    listUsers: boolean;
    editUsers: boolean;
    manageOptions: boolean;
    managePautas: boolean;
  };
};

type AdminPost = {
  id: number;
  title: string;
  slug: string;
  status: string;
  publishedAt: string;
  modifiedAt: string;
  author: { id: number; name: string } | string;
  categories?: Array<{ id: number; name: string; slug: string }>;
  publicUrl: string | null;
};

type SessionPayload = {
  ok: boolean;
  data?: {
    authenticated: boolean;
    user: AdminUser | null;
    csrfToken: string | null;
  };
};

type DashboardPayload = {
  ok: boolean;
  data?: {
    user: AdminUser;
    summary: {
      posts: {
        published: number;
        draft: number;
        pending: number;
        future: number;
        private: number;
        trash: number;
        total: number;
      };
      categories: number;
      users: number;
      media: number;
      comments: { approved: number; pending: number; spam: number };
    };
    recentPosts: AdminPost[];
    mode: 'read_only';
  };
};

type PostsPayload = {
  ok: boolean;
  data?: {
    items: AdminPost[];
    query: string;
    status: string;
    pagination: {
      page: number;
      perPage: number;
      total: number;
      totalPages: number;
    };
    mode: 'read_only';
  };
};

type PostDetailPayload = {
  ok: boolean;
  data?: {
    post: {
      id: number;
      title: string;
      slug: string;
      excerpt: string;
      content: string;
      status: string;
      publishedAt: string;
      modifiedAt: string;
      author: { id: number; name: string };
      categories: Array<{ id: number; name: string; slug: string }>;
      featuredImage: {
        id: number;
        url: string;
        title: string;
        alt: string;
      } | null;
      seo: {
        title: string;
        description: string;
        primaryCategoryId: number;
      };
      publicUrl: string | null;
    };
    categories: Array<{
      id: number;
      name: string;
      slug: string;
      parentId: number | null;
      color: string;
    }>;
    mode: 'read_only';
  };
};

type CategoriesPayload = {
  ok: boolean;
  data?: {
    items: Array<{
      id: number;
      taxonomyId: number;
      name: string;
      slug: string;
      parentId: number | null;
      count: number;
      color: string;
      colorSource: 'palette' | 'termmeta';
      publicUrl: string;
    }>;
    count: number;
    mode: 'read_only';
  };
};

type UsersPayload = {
  ok: boolean;
  data?: {
    items: AdminUser[];
    count: number;
    mode: 'read_only';
  };
};


type MediaPayload = {
  ok: boolean;
  data?: {
    items: Array<{
      id: number;
      title: string;
      mimeType: string;
      url: string;
      alt: string;
      createdAt: string;
      modifiedAt: string;
      parentId: number;
    }>;
    pagination: {
      page: number;
      perPage: number;
      total: number;
      totalPages: number;
    };
    mode: 'read_only';
  };
};

type SettingsPayload = {
  ok: boolean;
  data?: {
    options: Record<string, string>;
    mode: 'read_only';
  };
};

type PautasPayload = {
  ok: boolean;
  data?: {
    owner: {
      login: string;
      userId: number;
    };
    pipeline: string[];
    sources: Array<{
      name: string;
      category: string;
      feedUrl: string;
      kind: string;
      priority: number;
    }>;
    storage: {
      status: 'pending_database_write';
      feedSourcesTable: string;
      queueTable: string;
    };
    mode: 'foundation';
  };
};

type AdminView = 'dashboard' | 'posts' | 'post' | 'categories' | 'media' | 'users' | 'settings' | 'pautas';

function resolveAdminView(pathname: string): AdminView {
  const clean = pathname.replace(/\/+$/, '');

  if (clean === '/sistema/noticias') return 'posts';
  if (/^\/sistema\/noticias\/\d+$/.test(clean)) return 'post';
  if (clean === '/sistema/categorias') return 'categories';
  if (clean === '/sistema/midia') return 'media';
  if (clean === '/sistema/usuarios') return 'users';
  if (clean === '/sistema/configuracoes') return 'settings';
  if (clean === '/sistema/pautas') return 'pautas';

  return 'dashboard';
}

function formatAdminDate(value: string) {
  if (!value) return '—';

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;

  return new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    timeZone: 'America/Sao_Paulo',
  }).format(date);
}

function initials(value: string) {
  const parts = value.trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return 'NJ';

  return parts
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('');
}

function roleLabel(role: string) {
  const labels: Record<string, string> = {
    administrator: 'Administrador',
    editor: 'Editor',
    author: 'Autor',
    contributor: 'Colaborador',
    subscriber: 'Assinante',
  };

  return labels[role] ?? role;
}

function statusLabel(status: string) {
  const labels: Record<string, string> = {
    publish: 'Publicado',
    draft: 'Rascunho',
    pending: 'Pendente',
    future: 'Agendado',
    private: 'Privado',
    trash: 'Lixeira',
  };

  return labels[status] ?? status;
}

async function adminFetch<T>(url: string, init?: RequestInit): Promise<T> {
  const response = await fetch(url, {
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      ...(init?.body ? { 'Content-Type': 'application/json' } : {}),
      ...(init?.headers ?? {}),
    },
    ...init,
  });

  const payload = (await response.json()) as T & {
    error?: { code?: string };
  };

  if (!response.ok) {
    throw new Error(payload.error?.code ?? 'admin_http_' + response.status);
  }

  return payload;
}

function AdminLogin({
  onAuthenticated,
}: {
  onAuthenticated: (user: AdminUser, csrfToken: string) => void;
}) {
  const [identity, setIdentity] = useState('');
  const [password, setPassword] = useState('');
  const [state, setState] = useState<'idle' | 'loading' | 'error'>('idle');

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setState('loading');

    try {
      const payload = await adminFetch<{
        ok: boolean;
        data?: { user: AdminUser; csrfToken: string };
      }>('/api/admin/login.php', {
        method: 'POST',
        body: JSON.stringify({ identity, password }),
      });

      if (!payload.ok || !payload.data) {
        throw new Error('invalid_login_payload');
      }

      onAuthenticated(payload.data.user, payload.data.csrfToken);
    } catch {
      setState('error');
    }
  }

  return (
    <main className="admin-login">
      <section className="admin-login__panel" aria-labelledby="admin-login-title">
        <a className="admin-login__brand" href="/" aria-label="Voltar para o Nosso Jornal">
          <img src="/nosso-jornal-hulha-negra-bage.png" alt="Nosso Jornal" />
        </a>

        <span className="admin-login__eyebrow">Painel editorial</span>
        <h1 id="admin-login-title">Acessar o sistema</h1>
        <p>Use o mesmo usuário e senha cadastrados no antigo WordPress.</p>

        <form onSubmit={submit}>
          <label>
            <span>Usuário ou e-mail</span>
            <input
              type="text"
              name="identity"
              autoComplete="username"
              value={identity}
              onChange={(event) => setIdentity(event.target.value)}
              required
              autoFocus
            />
          </label>

          <label>
            <span>Senha</span>
            <input
              type="password"
              name="password"
              autoComplete="current-password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              required
            />
          </label>

          {state === 'error' && (
            <div className="admin-login__error" role="alert">
              Usuário ou senha inválidos.
            </div>
          )}

          <button type="submit" disabled={state === 'loading'}>
            {state === 'loading' ? 'Entrando…' : 'Entrar'}
          </button>
        </form>

        <a className="admin-login__back" href="/">← Voltar para o site</a>
      </section>
    </main>
  );
}

type AdminIconName =
  | 'dashboard'
  | 'news'
  | 'categories'
  | 'media'
  | 'users'
  | 'pautas'
  | 'settings';

function AdminIcon({ name }: { name: AdminIconName }) {
  const common = {
    width: 20,
    height: 20,
    viewBox: '0 0 24 24',
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: 1.8,
    strokeLinecap: 'round' as const,
    strokeLinejoin: 'round' as const,
    'aria-hidden': true,
  };

  if (name === 'dashboard') {
    return (
      <svg {...common}>
        <rect x="3" y="3" width="7" height="7" rx="1.4" />
        <rect x="14" y="3" width="7" height="7" rx="1.4" />
        <rect x="3" y="14" width="7" height="7" rx="1.4" />
        <rect x="14" y="14" width="7" height="7" rx="1.4" />
      </svg>
    );
  }

  if (name === 'news') {
    return (
      <svg {...common}>
        <path d="M4 4.5h11.5v15H4z" />
        <path d="M15.5 7H20v10.5a2 2 0 0 1-2 2h-2.5" />
        <path d="M7 8h5.5M7 11h5.5M7 14h5.5M7 17h3.5" />
      </svg>
    );
  }

  if (name === 'categories') {
    return (
      <svg {...common}>
        <path d="M4 6h6l2 2h8v10H4z" />
        <path d="M4 8V5h6l2 3" />
      </svg>
    );
  }

  if (name === 'media') {
    return (
      <svg {...common}>
        <rect x="3" y="4" width="18" height="16" rx="2" />
        <circle cx="8.5" cy="9" r="1.6" />
        <path d="m5 17 4.5-4.5 3.2 3.2 2.2-2.2L19 17" />
      </svg>
    );
  }

  if (name === 'users') {
    return (
      <svg {...common}>
        <circle cx="9" cy="8" r="3" />
        <path d="M3.5 19c.5-3.3 2.4-5 5.5-5s5 1.7 5.5 5" />
        <path d="M16 7.5a2.5 2.5 0 1 1 0 5M16 14.5c2.7.2 4.2 1.7 4.5 4.5" />
      </svg>
    );
  }

  if (name === 'pautas') {
    return (
      <svg {...common}>
        <path d="M4 5h10v14H4z" />
        <path d="M7 9h4M7 12h4M7 15h2.5" />
        <path d="M17 4v6M14 7h6" />
      </svg>
    );
  }

  return (
    <svg {...common}>
      <circle cx="12" cy="12" r="3.2" />
      <path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21h-4v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3v-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-1.5V3h4v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.5 1h.1v4h-.1a1.7 1.7 0 0 0-1.5 1Z" />
    </svg>
  );
}

function AdminNav({ user, view }: { user: AdminUser; view: AdminView }) {
  const entries: Array<{
    key: AdminView;
    label: string;
    href: string;
    icon: AdminIconName;
    group: 'content' | 'management' | 'system';
  }> = [
    { key: 'dashboard', label: 'Painel', href: '/sistema', icon: 'dashboard', group: 'content' },
    ...(user.permissions.editPosts
      ? [{ key: 'posts' as const, label: 'Notícias', href: '/sistema/noticias', icon: 'news' as const, group: 'content' as const }]
      : []),
    ...(user.permissions.manageCategories
      ? [{ key: 'categories' as const, label: 'Categorias', href: '/sistema/categorias', icon: 'categories' as const, group: 'content' as const }]
      : []),
    ...(user.permissions.uploadFiles
      ? [{ key: 'media' as const, label: 'Mídia', href: '/sistema/midia', icon: 'media' as const, group: 'content' as const }]
      : []),
    ...(user.permissions.listUsers
      ? [{ key: 'users' as const, label: 'Usuários', href: '/sistema/usuarios', icon: 'users' as const, group: 'management' as const }]
      : []),
    ...(user.login === 'agenciamobi' && user.permissions.managePautas
      ? [{ key: 'pautas' as const, label: 'Mesa de Pautas', href: '/sistema/pautas', icon: 'pautas' as const, group: 'management' as const }]
      : []),
    ...(user.permissions.manageOptions
      ? [{ key: 'settings' as const, label: 'Configurações', href: '/sistema/configuracoes', icon: 'settings' as const, group: 'system' as const }]
      : []),
  ];

  const groups = [
    { key: 'content' as const, label: 'Conteúdo' },
    { key: 'management' as const, label: 'Gestão' },
    { key: 'system' as const, label: 'Sistema' },
  ];

  return (
    <aside className="admin-sidebar">
      <a className="admin-sidebar__brand" href="/sistema" aria-label="Painel Nosso Jornal">
        <img src="/nosso-jornal-hulha-negra-bage.png" alt="" />
        <span>Nosso Jornal</span>
      </a>

      <nav aria-label="Menu administrativo">
        {groups.map((group) => {
          const items = entries.filter((entry) => entry.group === group.key);
          if (items.length === 0) return null;

          return (
            <section className="admin-nav__group" key={group.key}>
              <span className="admin-nav__group-label">{group.label}</span>

              {items.map((entry) => (
                <a
                  key={entry.key}
                  href={entry.href}
                  className={
                    view === entry.key || (view === 'post' && entry.key === 'posts')
                      ? 'admin-nav__item admin-nav__item--active'
                      : 'admin-nav__item'
                  }
                  aria-current={
                    view === entry.key || (view === 'post' && entry.key === 'posts')
                      ? 'page'
                      : undefined
                  }
                >
                  <span className="admin-nav__icon">
                    <AdminIcon name={entry.icon} />
                  </span>
                  <span>{entry.label}</span>
                </a>
              ))}
            </section>
          );
        })}
      </nav>

      <div className="admin-sidebar__bottom">
        <a href="/" target="_blank" rel="noopener noreferrer">
          <span>Ver site</span>
          <span aria-hidden="true">↗</span>
        </a>
      </div>
    </aside>
  );
}

function AdminTopbar({
  user,
  csrfToken,
  onLogout,
}: {
  user: AdminUser;
  csrfToken: string;
  onLogout: () => void;
}) {
  const [busy, setBusy] = useState(false);

  async function logout() {
    setBusy(true);

    try {
      await adminFetch('/api/admin/logout.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
      });
    } finally {
      onLogout();
    }
  }

  return (
    <header className="admin-topbar">
      <div>
        <strong>Nosso Jornal</strong>
        <span>Administração</span>
      </div>

      <div className="admin-topbar__user">
        <span className="admin-avatar" aria-hidden="true">{initials(user.displayName)}</span>
        <span>
          <strong>{user.displayName}</strong>
          <small>{user.roles.map(roleLabel).join(', ') || user.login}</small>
        </span>
        <button type="button" onClick={() => void logout()} disabled={busy}>Sair</button>
      </div>
    </header>
  );
}

function ReadOnlyNotice() {
  return (
    <div className="admin-readonly" role="status">
      <strong>MVP em modo leitura.</strong>
      <span>Os dados vêm diretamente do WordPress legado. Edição será liberada quando o write MySQL estiver homologado.</span>
    </div>
  );
}

function AdminPageHeader({
  eyebrow,
  title,
  description,
}: {
  eyebrow: string;
  title: string;
  description: string;
}) {
  return (
    <header className="admin-page-header">
      <span>{eyebrow}</span>
      <h1>{title}</h1>
      <p>{description}</p>
    </header>
  );
}

function AdminLoading() {
  return (
    <div className="admin-loading" aria-busy="true">
      <span />
      <span />
      <span />
    </div>
  );
}

function AdminError() {
  return (
    <div className="admin-error" role="alert">
      <strong>Não foi possível carregar esta área.</strong>
      <p>Atualize a página. Se o problema persistir, o endpoint administrativo deve ser verificado.</p>
    </div>
  );
}

function DashboardView({ user }: { user: AdminUser }) {
  const [data, setData] = useState<DashboardPayload['data']>();
  const [error, setError] = useState(false);

  useEffect(() => {
    void adminFetch<DashboardPayload>('/api/admin/dashboard.php')
      .then((payload) => {
        if (!payload.ok || !payload.data) throw new Error('dashboard_invalid');
        setData(payload.data);
      })
      .catch(() => setError(true));
  }, []);

  if (error) return <AdminError />;
  if (!data) return <AdminLoading />;

  const stats = [
    { label: 'Publicadas', value: data.summary.posts.published, href: '/sistema/noticias?status=publish' },
    { label: 'Rascunhos', value: data.summary.posts.draft, href: '/sistema/noticias?status=draft' },
    { label: 'Categorias', value: data.summary.categories, href: '/sistema/categorias' },
    ...(user.permissions.uploadFiles
      ? [{ label: 'Mídia', value: data.summary.media, href: '/sistema/midia' }]
      : []),
    { label: 'Usuários', value: data.summary.users, href: user.permissions.listUsers ? '/sistema/usuarios' : '/sistema' },
  ];

  return (
    <>
      <AdminPageHeader
        eyebrow="Visão geral"
        title="Painel"
        description="Resumo editorial e atividade recente do Nosso Jornal."
      />

      <ReadOnlyNotice />

      <section className="admin-stats" aria-label="Resumo">
        {stats.map((stat) => (
          <a href={stat.href} className="admin-stat" key={stat.label}>
            <strong>{stat.value}</strong>
            <span>{stat.label}</span>
          </a>
        ))}
      </section>

      <div className="admin-dashboard-grid">
        <section className="admin-widget">
          <div className="admin-widget__head">
            <div>
              <span>Conteúdo</span>
              <h2>Atividade recente</h2>
            </div>
            <a href="/sistema/noticias">Ver todas</a>
          </div>

          <div className="admin-recent-list">
            {data.recentPosts.map((post) => (
              <article key={post.id}>
                <div>
                  <span className={'admin-status admin-status--' + post.status}>
                    {statusLabel(post.status)}
                  </span>
                  <h3>{post.title}</h3>
                  <p>
                    {typeof post.author === 'string' ? post.author : post.author.name}
                    {' • '}
                    {formatAdminDate(post.modifiedAt)}
                  </p>
                </div>
                {post.publicUrl && post.status === 'publish' && (
                  <a
                    href={post.publicUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    aria-label={'Abrir ' + post.title + ' no site'}
                  >
                    ↗
                  </a>
                )}
              </article>
            ))}
          </div>
        </section>

        <aside className="admin-widget admin-widget--compact">
          <div className="admin-widget__head">
            <div>
              <span>Agora</span>
              <h2>No sistema</h2>
            </div>
          </div>

          <dl className="admin-system-list">
            <div><dt>Posts totais</dt><dd>{data.summary.posts.total}</dd></div>
            <div><dt>Pendentes</dt><dd>{data.summary.posts.pending}</dd></div>
            <div><dt>Agendados</dt><dd>{data.summary.posts.future}</dd></div>
            <div><dt>Comentários pendentes</dt><dd>{data.summary.comments.pending}</dd></div>
          </dl>
        </aside>
      </div>
    </>
  );
}

function PostsView() {
  const params = useMemo(() => new URLSearchParams(window.location.search), []);
  const status = params.get('status') ?? 'all';
  const query = params.get('q') ?? '';
  const page = Math.max(1, Number.parseInt(params.get('page') ?? '1', 10) || 1);

  const [data, setData] = useState<PostsPayload['data']>();
  const [error, setError] = useState(false);

  useEffect(() => {
    const search = new URLSearchParams({ status, page: String(page) });
    if (query) search.set('q', query);

    void adminFetch<PostsPayload>('/api/admin/posts.php?' + search.toString())
      .then((payload) => {
        if (!payload.ok || !payload.data) throw new Error('posts_invalid');
        setData(payload.data);
      })
      .catch(() => setError(true));
  }, [page, query, status]);

  if (error) return <AdminError />;
  if (!data) return <AdminLoading />;

  return (
    <>
      <AdminPageHeader
        eyebrow="Conteúdo"
        title="Notícias"
        description="Posts importados do WordPress legado."
      />

      <ReadOnlyNotice />

      <form className="admin-toolbar" method="get" action="/sistema/noticias">
        <div className="admin-filter-tabs" aria-label="Filtrar notícias por status">
          {[
            ['all', 'Todas'],
            ['publish', 'Publicadas'],
            ['draft', 'Rascunhos'],
            ['pending', 'Pendentes'],
            ['future', 'Agendadas'],
          ].map(([value, label]) => (
            <a
              key={value}
              className={status === value ? 'active' : ''}
              href={'/sistema/noticias?status=' + value}
            >
              {label}
            </a>
          ))}
        </div>

        <div className="admin-search">
          <input
            type="search"
            name="q"
            defaultValue={query}
            placeholder="Buscar notícias"
            aria-label="Buscar notícias"
          />
          {status !== 'all' && <input type="hidden" name="status" value={status} />}
          <button type="submit">Buscar</button>
        </div>
      </form>

      <div className="admin-table-wrap">
        <table className="admin-table">
          <thead>
            <tr>
              <th>Título</th>
              <th>Autor</th>
              <th>Categorias</th>
              <th>Status</th>
              <th>Atualização</th>
            </tr>
          </thead>
          <tbody>
            {data.items.map((post) => (
              <tr key={post.id}>
                <td className="admin-table__primary">
                  <strong>
                    <a href={'/sistema/noticias/' + post.id}>{post.title}</a>
                  </strong>
                  <div className="admin-row-actions">
                    <a href={'/sistema/noticias/' + post.id}>Abrir</a>
                    <span>#{post.id}</span>
                    {post.publicUrl && post.status === 'publish' && (
                      <a href={post.publicUrl} target="_blank" rel="noopener noreferrer">Ver ↗</a>
                    )}
                  </div>
                </td>
                <td>{typeof post.author === 'string' ? post.author : post.author.name}</td>
                <td>
                  <div className="admin-chips">
                    {(post.categories ?? []).map((category) => (
                      <a
                        href={'/categoria/' + category.slug}
                        target="_blank"
                        rel="noopener noreferrer"
                        key={category.id}
                      >
                        {category.name}
                      </a>
                    ))}
                  </div>
                </td>
                <td>
                  <span className={'admin-status admin-status--' + post.status}>
                    {statusLabel(post.status)}
                  </span>
                </td>
                <td>{formatAdminDate(post.modifiedAt)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <AdminPagination
        page={data.pagination.page}
        totalPages={data.pagination.totalPages}
        base="/sistema/noticias"
        params={{ status, q: query }}
      />
    </>
  );
}

function PostEditorView() {
  const match = window.location.pathname.match(/^\/sistema\/noticias\/(\d+)\/?$/);
  const postId = match ? Number.parseInt(match[1], 10) : 0;
  const [data, setData] = useState<PostDetailPayload['data']>();
  const [error, setError] = useState(false);

  useEffect(() => {
    if (!postId) {
      setError(true);
      return;
    }

    void adminFetch<PostDetailPayload>('/api/admin/post.php?id=' + postId)
      .then((payload) => {
        if (!payload.ok || !payload.data) throw new Error('post_invalid');
        setData(payload.data);
      })
      .catch(() => setError(true));
  }, [postId]);

  if (error) return <AdminError />;
  if (!data) return <AdminLoading />;

  const post = data.post;
  const selectedCategoryIds = new Set(post.categories.map((category) => category.id));

  return (
    <>
      <header className="admin-editor-header">
        <div>
          <a href="/sistema/noticias" className="admin-editor-header__back">← Notícias</a>
          <div className="admin-editor-header__title">
            <span className={'admin-status admin-status--' + post.status}>
              {statusLabel(post.status)}
            </span>
            <h1>Editar notícia</h1>
          </div>
          <p>#{post.id} • última alteração {formatAdminDate(post.modifiedAt)}</p>
        </div>

        <div className="admin-editor-header__actions">
          {post.publicUrl && post.status === 'publish' && (
            <a href={post.publicUrl} target="_blank" rel="noopener noreferrer">
              Ver no site ↗
            </a>
          )}
          <button type="button" disabled title="Aguardando write MySQL">
            Salvar rascunho
          </button>
          <button type="button" className="admin-button--primary" disabled title="Aguardando write MySQL">
            Publicar
          </button>
        </div>
      </header>

      <ReadOnlyNotice />

      <div className="admin-editor-layout">
        <section className="admin-editor-main">
          <label className="admin-editor-field admin-editor-field--title">
            <span>Título</span>
            <input value={post.title} readOnly />
          </label>

          <label className="admin-editor-field">
            <span>Slug</span>
            <input value={post.slug} readOnly />
          </label>

          <label className="admin-editor-field">
            <span>Resumo</span>
            <textarea value={post.excerpt} readOnly rows={5} />
          </label>

          <label className="admin-editor-field">
            <span>Conteúdo</span>
            <textarea
              className="admin-editor-content"
              value={post.content}
              readOnly
              rows={28}
            />
          </label>

          <section className="admin-editor-card">
            <div className="admin-editor-card__head">
              <span>SEO</span>
              <strong>Metadados</strong>
            </div>

            <div className="admin-editor-card__body admin-editor-card__body--fields">
              <label className="admin-editor-field">
                <span>Título SEO</span>
                <input value={post.seo.title} readOnly />
              </label>

              <label className="admin-editor-field">
                <span>Descrição SEO</span>
                <textarea value={post.seo.description} readOnly rows={4} />
              </label>
            </div>
          </section>
        </section>

        <aside className="admin-editor-sidebar">
          <section className="admin-editor-card">
            <div className="admin-editor-card__head">
              <span>Publicação</span>
              <strong>Estado</strong>
            </div>

            <dl className="admin-editor-meta">
              <div>
                <dt>Status</dt>
                <dd>{statusLabel(post.status)}</dd>
              </div>
              <div>
                <dt>Autor</dt>
                <dd>{post.author.name}</dd>
              </div>
              <div>
                <dt>Publicado</dt>
                <dd>{formatAdminDate(post.publishedAt)}</dd>
              </div>
              <div>
                <dt>Atualizado</dt>
                <dd>{formatAdminDate(post.modifiedAt)}</dd>
              </div>
            </dl>
          </section>

          <section className="admin-editor-card">
            <div className="admin-editor-card__head">
              <span>Taxonomia</span>
              <strong>Categorias</strong>
            </div>

            <div className="admin-editor-categories">
              {data.categories.map((category) => (
                <label key={category.id}>
                  <input
                    type="checkbox"
                    checked={selectedCategoryIds.has(category.id)}
                    readOnly
                  />
                  <i style={{ background: category.color }} aria-hidden="true" />
                  <span>{category.name}</span>
                </label>
              ))}
            </div>
          </section>

          <section className="admin-editor-card">
            <div className="admin-editor-card__head">
              <span>Imagem</span>
              <strong>Destacada</strong>
            </div>

            {post.featuredImage ? (
              <figure className="admin-editor-featured">
                <img
                  src={post.featuredImage.url}
                  alt={post.featuredImage.alt || post.featuredImage.title || post.title}
                />
                <figcaption>
                  <strong>{post.featuredImage.title || 'Imagem destacada'}</strong>
                  <span>#{post.featuredImage.id}</span>
                </figcaption>
              </figure>
            ) : (
              <div className="admin-editor-empty">Sem imagem destacada.</div>
            )}
          </section>

          <section className="admin-editor-card">
            <div className="admin-editor-card__head">
              <span>Próxima capacidade</span>
              <strong>Write</strong>
            </div>

            <div className="admin-editor-next">
              <p>Assim que o runtime MySQL permitir escrita, este editor será ligado a:</p>
              <ul>
                <li>Salvar rascunho</li>
                <li>Publicar e despublicar</li>
                <li>Agendar publicação</li>
                <li>Alterar categorias</li>
                <li>Trocar imagem destacada</li>
                <li>Atualizar SEO</li>
              </ul>
            </div>
          </section>
        </aside>
      </div>
    </>
  );
}

function CategoriesView() {
  const [data, setData] = useState<CategoriesPayload['data']>();
  const [error, setError] = useState(false);

  useEffect(() => {
    void adminFetch<CategoriesPayload>('/api/admin/categories.php')
      .then((payload) => {
        if (!payload.ok || !payload.data) throw new Error('categories_invalid');
        setData(payload.data);
      })
      .catch(() => setError(true));
  }, []);

  if (error) return <AdminError />;
  if (!data) return <AdminLoading />;

  const byId = new Map(data.items.map((item) => [item.id, item]));

  return (
    <>
      <AdminPageHeader
        eyebrow="Taxonomia"
        title="Categorias"
        description="Editorias e municípios usados na organização das notícias."
      />

      <ReadOnlyNotice />

      <div className="admin-table-wrap">
        <table className="admin-table">
          <thead>
            <tr>
              <th>Nome</th>
              <th>Slug</th>
              <th>Categoria superior</th>
              <th>Cor editorial</th>
              <th>Posts</th>
            </tr>
          </thead>
          <tbody>
            {data.items.map((category) => (
              <tr key={category.id}>
                <td className="admin-table__primary">
                  <strong>{category.name}</strong>
                  <div className="admin-row-actions">
                    <span>#{category.id}</span>
                    <a href={category.publicUrl} target="_blank" rel="noopener noreferrer">Ver ↗</a>
                  </div>
                </td>
                <td><code>{category.slug}</code></td>
                <td>
                  {category.parentId
                    ? byId.get(category.parentId)?.name ?? '#' + category.parentId
                    : '—'}
                </td>
                <td>
                  <span className="admin-color">
                    <i style={{ background: category.color }} />
                    <span>{category.color}</span>
                    <small>{category.colorSource === 'termmeta' ? 'Banco' : 'Fallback'}</small>
                  </span>
                </td>
                <td>{category.count}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}

function UsersView() {
  const [data, setData] = useState<UsersPayload['data']>();
  const [error, setError] = useState(false);

  useEffect(() => {
    void adminFetch<UsersPayload>('/api/admin/users.php')
      .then((payload) => {
        if (!payload.ok || !payload.data) throw new Error('users_invalid');
        setData(payload.data);
      })
      .catch(() => setError(true));
  }, []);

  if (error) return <AdminError />;
  if (!data) return <AdminLoading />;

  return (
    <>
      <AdminPageHeader
        eyebrow="Acesso"
        title="Usuários"
        description="Contas e permissões reaproveitadas diretamente do WordPress."
      />

      <ReadOnlyNotice />

      <div className="admin-table-wrap">
        <table className="admin-table">
          <thead>
            <tr>
              <th>Usuário</th>
              <th>Login</th>
              <th>E-mail</th>
              <th>Função</th>
              <th>Cadastro</th>
            </tr>
          </thead>
          <tbody>
            {data.items.map((user) => (
              <tr key={user.id}>
                <td className="admin-user-cell">
                  <span className="admin-avatar">{initials(user.displayName)}</span>
                  <strong>{user.displayName}</strong>
                </td>
                <td><code>{user.login}</code></td>
                <td><a href={'mailto:' + user.email}>{user.email}</a></td>
                <td>
                  <div className="admin-chips">
                    {user.roles.map((role) => <span key={role}>{roleLabel(role)}</span>)}
                  </div>
                </td>
                <td>{formatAdminDate(user.registeredAt)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}


function MediaView() {
  const params = useMemo(() => new URLSearchParams(window.location.search), []);
  const page = Math.max(1, Number.parseInt(params.get('page') ?? '1', 10) || 1);
  const [data, setData] = useState<MediaPayload['data']>();
  const [error, setError] = useState(false);

  useEffect(() => {
    void adminFetch<MediaPayload>('/api/admin/media.php?page=' + page + '&per_page=36')
      .then((payload) => {
        if (!payload.ok || !payload.data) throw new Error('media_invalid');
        setData(payload.data);
      })
      .catch(() => setError(true));
  }, [page]);

  if (error) return <AdminError />;
  if (!data) return <AdminLoading />;

  return (
    <>
      <AdminPageHeader
        eyebrow="Acervo"
        title="Mídia"
        description="Biblioteca de imagens e arquivos reaproveitada do WordPress."
      />

      <ReadOnlyNotice />

      <section className="admin-media-grid" aria-label="Biblioteca de mídia">
        {data.items.map((item) => (
          <article className="admin-media-card" key={item.id}>
            <div className="admin-media-card__preview">
              {item.mimeType.startsWith('image/') ? (
                <img src={item.url} alt={item.alt || item.title} loading="lazy" />
              ) : (
                <span>{item.mimeType || 'arquivo'}</span>
              )}
            </div>

            <div className="admin-media-card__body">
              <strong>{item.title}</strong>
              <small>{formatAdminDate(item.createdAt)}</small>
            </div>
          </article>
        ))}
      </section>

      <AdminPagination
        page={data.pagination.page}
        totalPages={data.pagination.totalPages}
        base="/sistema/midia"
        params={{}}
      />
    </>
  );
}

function SettingsView() {
  const [data, setData] = useState<SettingsPayload['data']>();
  const [error, setError] = useState(false);

  useEffect(() => {
    void adminFetch<SettingsPayload>('/api/admin/settings.php')
      .then((payload) => {
        if (!payload.ok || !payload.data) throw new Error('settings_invalid');
        setData(payload.data);
      })
      .catch(() => setError(true));
  }, []);

  if (error) return <AdminError />;
  if (!data) return <AdminLoading />;

  const labels: Record<string, string> = {
    blogname: 'Nome do site',
    blogdescription: 'Descrição',
    home: 'URL pública',
    siteurl: 'URL do WordPress legado',
    admin_email: 'E-mail administrativo',
    posts_per_page: 'Posts por página',
    date_format: 'Formato de data',
    time_format: 'Formato de hora',
    timezone_string: 'Fuso horário',
    permalink_structure: 'Estrutura histórica de links',
  };

  return (
    <>
      <AdminPageHeader
        eyebrow="Site"
        title="Configurações"
        description="Configurações gerais herdadas do WordPress."
      />

      <ReadOnlyNotice />

      <section className="admin-settings">
        {Object.entries(data.options).map(([key, value]) => (
          <label key={key}>
            <span>{labels[key] ?? key}</span>
            <input value={value} readOnly />
          </label>
        ))}
      </section>
    </>
  );
}

function PautasView() {
  const [data, setData] = useState<PautasPayload['data']>();
  const [error, setError] = useState(false);

  useEffect(() => {
    void adminFetch<PautasPayload>('/api/admin/pautas.php')
      .then((payload) => {
        if (!payload.ok || !payload.data) throw new Error('pautas_invalid');
        setData(payload.data);
      })
      .catch(() => setError(true));
  }, []);

  if (error) {
    return (
      <div className="admin-error" role="alert">
        <strong>Acesso restrito.</strong>
        <p>A Mesa de Pautas está disponível somente para o usuário agenciamobi.</p>
      </div>
    );
  }

  if (!data) return <AdminLoading />;

  const categories = Array.from(new Set(data.sources.map((source) => source.category)));

  return (
    <>
      <AdminPageHeader
        eyebrow="Exclusivo • agenciamobi"
        title="Mesa de Pautas"
        description="Radar editorial para Pelotas, tecnologia, inteligência artificial, universo, ciência e temas correlatos."
      />

      <section className="admin-pautas-summary" aria-label="Estado da Mesa de Pautas">
        <div>
          <span>Fontes iniciais</span>
          <strong>{data.sources.length}</strong>
        </div>
        <div>
          <span>Editorias monitoradas</span>
          <strong>{categories.length}</strong>
        </div>
        <div>
          <span>Fila</span>
          <strong>Preparada</strong>
        </div>
      </section>

      <section className="admin-widget admin-pautas-pipeline">
        <div className="admin-widget__head">
          <div>
            <span>Fluxo editorial</span>
            <h2>Da captura à publicação</h2>
          </div>
        </div>

        <ol>
          {data.pipeline.map((step, index) => (
            <li key={step}>
              <span>{String(index + 1).padStart(2, '0')}</span>
              <strong>{step}</strong>
            </li>
          ))}
        </ol>
      </section>

      <section className="admin-widget">
        <div className="admin-widget__head">
          <div>
            <span>RSS</span>
            <h2>Catálogo inicial de fontes</h2>
          </div>
          <small>{data.storage.status === 'pending_database_write' ? 'Aguardando persistência no banco' : ''}</small>
        </div>

        <div className="admin-pautas-sources">
          {data.sources.map((source) => (
            <article key={source.feedUrl}>
              <div>
                <span className="admin-pautas-source__category">{source.category}</span>
                <h3>{source.name}</h3>
                <p>{source.kind} • prioridade {source.priority}</p>
              </div>

              <a
                href={source.feedUrl}
                target="_blank"
                rel="noopener noreferrer"
                aria-label={'Abrir feed RSS de ' + source.name + ' em nova aba'}
              >
                RSS ↗
              </a>
            </article>
          ))}
        </div>
      </section>

      <div className="admin-readonly" role="status">
        <strong>Próximo estágio:</strong>
        <span>
          persistir {data.storage.feedSourcesTable} e {data.storage.queueTable}, capturar os feeds
          e habilitar Ignorar, Salvar e Produzir matéria.
        </span>
      </div>
    </>
  );
}

function AdminPagination({
  page,
  totalPages,
  base,
  params,
}: {
  page: number;
  totalPages: number;
  base: string;
  params: Record<string, string>;
}) {
  if (totalPages <= 1) return null;

  const href = (target: number) => {
    const search = new URLSearchParams();

    Object.entries(params).forEach(([key, value]) => {
      if (value && value !== 'all') search.set(key, value);
    });

    search.set('page', String(target));
    return base + '?' + search.toString();
  };

  return (
    <nav className="admin-pagination" aria-label="Paginação">
      {page > 1 ? <a href={href(page - 1)}>← Anterior</a> : <span />}
      <span>Página {page} de {totalPages}</span>
      {page < totalPages ? <a href={href(page + 1)}>Próxima →</a> : <span />}
    </nav>
  );
}

export function AdminApp() {
  const view = resolveAdminView(window.location.pathname);
  const [sessionState, setSessionState] = useState<'loading' | 'guest' | 'authenticated'>('loading');
  const [user, setUser] = useState<AdminUser | null>(null);
  const [csrfToken, setCsrfToken] = useState('');

  const loadSession = useCallback(async () => {
    try {
      const payload = await adminFetch<SessionPayload>('/api/admin/session.php');

      if (
        payload.ok
        && payload.data?.authenticated
        && payload.data.user
        && payload.data.csrfToken
      ) {
        setUser(payload.data.user);
        setCsrfToken(payload.data.csrfToken);
        setSessionState('authenticated');
        return;
      }
    } catch {
      // Sessão inválida é tratada como visitante.
    }

    setUser(null);
    setCsrfToken('');
    setSessionState('guest');
  }, []);

  useEffect(() => {
    void loadSession();
  }, [loadSession]);

  useEffect(() => {
    document.title = sessionState === 'authenticated'
      ? 'Sistema | Nosso Jornal'
      : 'Acessar sistema | Nosso Jornal';

    let robots = document.head.querySelector<HTMLMetaElement>('meta[name="robots"]');

    if (!robots) {
      robots = document.createElement('meta');
      robots.name = 'robots';
      document.head.appendChild(robots);
    }

    robots.content = 'noindex,nofollow';
  }, [sessionState]);

  if (sessionState === 'loading') {
    return (
      <main className="admin-boot" aria-busy="true">
        <span />
      </main>
    );
  }

  if (sessionState === 'guest' || !user) {
    return (
      <AdminLogin
        onAuthenticated={(nextUser, token) => {
          setUser(nextUser);
          setCsrfToken(token);
          setSessionState('authenticated');
        }}
      />
    );
  }

  return (
    <div className="admin-shell">
      <AdminNav user={user} view={view} />

      <div className="admin-workspace">
        <AdminTopbar
          user={user}
          csrfToken={csrfToken}
          onLogout={() => {
            setUser(null);
            setCsrfToken('');
            setSessionState('guest');
          }}
        />

        <main className="admin-content">
          {view === 'dashboard' && <DashboardView user={user} />}
          {view === 'posts' && <PostsView />}
          {view === 'post' && <PostEditorView />}
          {view === 'categories' && <CategoriesView />}
          {view === 'media' && <MediaView />}
          {view === 'users' && <UsersView />}
          {view === 'settings' && <SettingsView />}
          {view === 'pautas' && (
            user.login === 'agenciamobi' && user.permissions.managePautas
              ? <PautasView />
              : (
                <div className="admin-error" role="alert">
                  <strong>Acesso restrito.</strong>
                  <p>A Mesa de Pautas está disponível somente para o usuário agenciamobi.</p>
                </div>
              )
          )}
        </main>
      </div>
    </div>
  );
}
