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
    moderateComments: boolean;
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
  };
};

type CategoryDetailPayload = {
  ok: boolean;
  data?: {
    category: {
      id: number;
      taxonomyId: number;
      name: string;
      slug: string;
      description: string;
      parentId: number | null;
      count: number;
      color: string;
      colorSource: 'palette' | 'termmeta';
      publicUrl: string;
    };
    parents: Array<{
      id: number;
      name: string;
      slug: string;
      parentId: number | null;
    }>;
  };
};

type UsersPayload = {
  ok: boolean;
  data?: {
    items: AdminUser[];
    count: number;
  };
};

type UserDetailPayload = {
  ok: boolean;
  data?: {
    user: AdminUser;
    roles: Array<{ key: string; name: string }>;
    canChangeRole: boolean;
  };
};


type MediaItem = {
  id: number;
  title: string;
  mimeType: string;
  url: string;
  alt: string;
  createdAt: string;
  modifiedAt: string;
  parentId: number;
};

type MediaPayload = {
  ok: boolean;
  data?: {
    items: MediaItem[];
    pagination: {
      page: number;
      perPage: number;
      total: number;
      totalPages: number;
    };
  };
};

type MediaDetailPayload = {
  ok: boolean;
  data?: {
    media: {
      id: number;
      authorId: number;
      title: string;
      caption: string;
      description: string;
      mimeType: string;
      url: string;
      alt: string;
      attachedFile: string;
      createdAt: string;
      modifiedAt: string;
      parentId: number;
      usedBy: Array<{
        id: number;
        title: string;
        status: string;
        adminUrl: string;
        publicUrl: string | null;
      }>;
    };
  };
};

type CommentsPayload = {
  ok: boolean;
  data?: {
    items: Array<{
      id: number;
      postId: number;
      postTitle: string;
      postUrl: string | null;
      author: string;
      email: string;
      authorUrl: string;
      content: string;
      createdAt: string;
      status: 'pending' | 'approved' | 'spam' | 'trash';
      parentId: number;
      userId: number;
    }>;
    query: string;
    status: string;
    counts: {
      pending: number;
      approved: number;
      spam: number;
      trash: number;
    };
    pagination: {
      page: number;
      perPage: number;
      total: number;
      totalPages: number;
    };
  };
};

type SettingsPayload = {
  ok: boolean;
  data?: {
    options: Record<string, string>;
  };
};

type WriteReadinessPayload = {
  ok: boolean;
  data?: {
    database: {
      select: { available: boolean; reason: string | null };
      insert: { available: boolean; reason: string | null };
      update: { available: boolean; reason: string | null };
      delete: { available: boolean; reason: string | null };
    };
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
  };
};

type AdminView = 'dashboard' | 'posts' | 'post' | 'categories' | 'category' | 'categoryNew' | 'media' | 'mediaItem' | 'comments' | 'users' | 'user' | 'settings' | 'pautas';

function resolveAdminView(pathname: string): AdminView {
  const clean = pathname.replace(/\/+$/, '');

  if (clean === '/sistema/noticias') return 'posts';
  if (/^\/sistema\/noticias\/\d+$/.test(clean)) return 'post';
  if (clean === '/sistema/categorias') return 'categories';
  if (clean === '/sistema/categorias/nova') return 'categoryNew';
  if (/^\/sistema\/categorias\/\d+$/.test(clean)) return 'category';
  if (clean === '/sistema/midia') return 'media';
  if (/^\/sistema\/midia\/\d+$/.test(clean)) return 'mediaItem';
  if (clean === '/sistema/comentarios') return 'comments';
  if (clean === '/sistema/usuarios') return 'users';
  if (/^\/sistema\/usuarios\/\d+$/.test(clean)) return 'user';
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

function commentStatusLabel(status: string) {
  const labels: Record<string, string> = {
    pending: 'Pendente',
    approved: 'Aprovado',
    spam: 'Spam',
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
        <p>Use seu usuário e senha do Nosso Jornal.</p>

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
  | 'comments'
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

  if (name === 'comments') {
    return (
      <svg {...common}>
        <path d="M4 5h16v11H9l-5 4z" />
        <path d="M8 9h8M8 12h5" />
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
    ...(user.permissions.moderateComments
      ? [{ key: 'comments' as const, label: 'Comentários', href: '/sistema/comentarios', icon: 'comments' as const, group: 'content' as const }]
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
                    view === entry.key
                      || (view === 'post' && entry.key === 'posts')
                      || ((view === 'category' || view === 'categoryNew') && entry.key === 'categories')
                      || (view === 'mediaItem' && entry.key === 'media')
                      || (view === 'user' && entry.key === 'users')
                      ? 'admin-nav__item admin-nav__item--active'
                      : 'admin-nav__item'
                  }
                  aria-current={
                    view === entry.key
                      || (view === 'post' && entry.key === 'posts')
                      || ((view === 'category' || view === 'categoryNew') && entry.key === 'categories')
                      || (view === 'mediaItem' && entry.key === 'media')
                      || (view === 'user' && entry.key === 'users')
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
      <p>Atualize a página e tente novamente.</p>
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
    ...(user.permissions.moderateComments
      ? [{ label: 'Comentários', value: data.summary.comments.pending, href: '/sistema/comentarios?status=pending' }]
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
                  <h3><a href={'/sistema/noticias/' + post.id}>{post.title}</a></h3>
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
            <div><dt>Notícias</dt><dd>{data.summary.posts.total}</dd></div>
            <div><dt>Pendentes</dt><dd>{data.summary.posts.pending}</dd></div>
            <div><dt>Agendados</dt><dd>{data.summary.posts.future}</dd></div>
            <div><dt>Comentários pendentes</dt><dd>{data.summary.comments.pending}</dd></div>
          </dl>

        </aside>
      </div>
    </>
  );
}

function PostsView({
  user,
  csrfToken,
}: {
  user: AdminUser;
  csrfToken: string;
}) {
  const params = useMemo(() => new URLSearchParams(window.location.search), []);
  const status = params.get('status') ?? 'all';
  const query = params.get('q') ?? '';
  const page = Math.max(1, Number.parseInt(params.get('page') ?? '1', 10) || 1);

  const [data, setData] = useState<PostsPayload['data']>();
  const [writeReadiness, setWriteReadiness] = useState<WriteReadinessPayload['data']>();
  const [creating, setCreating] = useState(false);
  const [postActionId, setPostActionId] = useState(0);
  const [createError, setCreateError] = useState(false);
  const [actionError, setActionError] = useState(false);
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

    void adminFetch<WriteReadinessPayload>('/api/admin/write-readiness.php')
      .then((payload) => {
        if (payload.ok && payload.data) setWriteReadiness(payload.data);
      })
      .catch(() => {
        // A listagem continua funcional.
      });
  }, [page, query, status]);

  if (error) return <AdminError />;
  if (!data) return <AdminLoading />;

  const canCreateDraft = Boolean(
    writeReadiness?.database.insert.available
      && writeReadiness?.database.update.available,
  );

  function canManageTrash(post: AdminPost) {
    if (!writeReadiness?.database.insert.available
      || !writeReadiness.database.update.available
      || !writeReadiness.database.delete.available) {
      return false;
    }

    const authorId = typeof post.author === 'string' ? 0 : post.author.id;
    const ownsPost = authorId === user.id;

    if (ownsPost && !user.capabilities.includes('delete_posts')) return false;
    if (!ownsPost && !user.capabilities.includes('delete_others_posts')) return false;

    if (
      ['publish', 'future', 'private'].includes(post.status)
      && !user.capabilities.includes('delete_published_posts')
    ) {
      return false;
    }

    return true;
  }

  async function changeTrash(post: AdminPost, action: 'trash' | 'restore') {
    if (!canManageTrash(post) || postActionId > 0) return;

    if (
      action === 'trash'
      && !window.confirm('Mover esta notícia para a lixeira?')
    ) {
      return;
    }

    setPostActionId(post.id);
    setActionError(false);

    try {
      const payload = await adminFetch<{
        ok: boolean;
        data?: {
          post: {
            id: number;
            status: string;
            modifiedAt: string;
            publicUrl: string | null;
          };
        };
      }>('/api/admin/post-trash.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: JSON.stringify({
          postId: post.id,
          action,
        }),
      });

      if (!payload.ok || !payload.data) {
        throw new Error('post_trash_invalid_response');
      }

      const nextStatus = payload.data.post.status;
      const staysInCurrentFilter = status === 'all'
        ? nextStatus !== 'trash'
        : status === nextStatus;

      setData((current) => current
        ? {
            ...current,
            items: staysInCurrentFilter
              ? current.items.map((item) => item.id === post.id
                  ? {
                      ...item,
                      status: nextStatus,
                      modifiedAt: payload.data!.post.modifiedAt,
                      publicUrl: payload.data!.post.publicUrl,
                    }
                  : item)
              : current.items.filter((item) => item.id !== post.id),
            pagination: staysInCurrentFilter
              ? current.pagination
              : {
                  ...current.pagination,
                  total: Math.max(0, current.pagination.total - 1),
                },
          }
        : current
      );
    } catch {
      setActionError(true);
    } finally {
      setPostActionId(0);
    }
  }

  async function createDraft() {
    if (!canCreateDraft || creating) return;

    setCreating(true);
    setCreateError(false);

    try {
      const payload = await adminFetch<{
        ok: boolean;
        data?: {
          post: {
            id: number;
            adminUrl: string;
          };
        };
      }>('/api/admin/post-create-draft.php', {
        method: 'POST',
        headers: {
          'X-CSRF-Token': csrfToken,
        },
        body: JSON.stringify({
          title: 'Nova notícia',
        }),
      });

      if (!payload.ok || !payload.data) {
        throw new Error('draft_create_invalid_response');
      }

      window.location.href = payload.data.post.adminUrl;
    } catch {
      setCreateError(true);
      setCreating(false);
    }
  }

  return (
    <>
      <div className="admin-page-heading-row">
        <AdminPageHeader
          eyebrow="Conteúdo"
          title="Notícias"
          description="Gerencie notícias, rascunhos e publicações do site."
        />

        <button
          type="button"
          className="admin-create-button"
          disabled={!canCreateDraft || creating}
          title={canCreateDraft ? 'Criar nova notícia' : 'Criação de notícias temporariamente indisponível'}
          onClick={() => void createDraft()}
        >
          {creating ? 'Criando…' : '+ Nova notícia'}
        </button>
      </div>

      {createError && (
        <div className="admin-save-feedback admin-save-feedback--error" role="alert">
          Não foi possível criar a notícia. Tente novamente.
        </div>
      )}

      {actionError && (
        <div className="admin-save-feedback admin-save-feedback--error" role="alert">
          Não foi possível atualizar a lixeira. Tente novamente.
        </div>
      )}

      <form className="admin-toolbar" method="get" action="/sistema/noticias">
        <div className="admin-filter-tabs" aria-label="Filtrar notícias por status">
          {[
            ['all', 'Todas'],
            ['publish', 'Publicadas'],
            ['draft', 'Rascunhos'],
            ['pending', 'Pendentes'],
            ['future', 'Agendadas'],
            ['trash', 'Lixeira'],
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
                    {canManageTrash(post) && (
                      <button
                        type="button"
                        className={post.status === 'trash' ? 'admin-row-action-button' : 'admin-row-action-button admin-row-action-button--danger'}
                        disabled={postActionId === post.id}
                        onClick={() => void changeTrash(post, post.status === 'trash' ? 'restore' : 'trash')}
                      >
                        {postActionId === post.id
                          ? 'Aguarde…'
                          : post.status === 'trash'
                            ? 'Restaurar'
                            : 'Lixeira'}
                      </button>
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

function PostEditorView({
  user,
  csrfToken,
}: {
  user: AdminUser;
  csrfToken: string;
}) {
  const match = window.location.pathname.match(/^\/sistema\/noticias\/(\d+)\/?$/);
  const postId = match ? Number.parseInt(match[1], 10) : 0;

  const [data, setData] = useState<PostDetailPayload['data']>();
  const [writeReadiness, setWriteReadiness] = useState<WriteReadinessPayload['data']>();
  const [title, setTitle] = useState('');
  const [slug, setSlug] = useState('');
  const [excerpt, setExcerpt] = useState('');
  const [content, setContent] = useState('');
  const [categoryIds, setCategoryIds] = useState<number[]>([]);
  const [seoTitle, setSeoTitle] = useState('');
  const [seoDescription, setSeoDescription] = useState('');
  const [primaryCategoryId, setPrimaryCategoryId] = useState(0);
  const [scheduledAt, setScheduledAt] = useState('');
  const [mediaItems, setMediaItems] = useState<MediaItem[]>([]);
  const [mediaPickerOpen, setMediaPickerOpen] = useState(false);
  const [imageState, setImageState] = useState<'idle' | 'working' | 'error'>('idle');
  const [saveState, setSaveState] = useState<'idle' | 'saving' | 'saved' | 'error'>('idle');
  const [statusState, setStatusState] = useState<'idle' | 'working' | 'saved' | 'error'>('idle');
  const [error, setError] = useState(false);

  useEffect(() => {
    if (!postId) {
      setError(true);
      return;
    }

    void adminFetch<PostDetailPayload>('/api/admin/post.php?id=' + postId)
      .then((payload) => {
        if (!payload.ok || !payload.data) throw new Error('post_invalid');

        const post = payload.data.post;
        setData(payload.data);
        setTitle(post.title);
        setSlug(post.slug);
        setExcerpt(post.excerpt);
        setContent(post.content);
        setCategoryIds(post.categories.map((category) => category.id));
        setSeoTitle(post.seo.title);
        setSeoDescription(post.seo.description);
        setPrimaryCategoryId(post.seo.primaryCategoryId);
      })
      .catch(() => setError(true));

    void adminFetch<WriteReadinessPayload>('/api/admin/write-readiness.php')
      .then((payload) => {
        if (payload.ok && payload.data) setWriteReadiness(payload.data);
      })
      .catch(() => {
        // As ações permanecem indisponíveis quando a verificação não responder.
      });
  }, [postId]);

  if (error) return <AdminError />;
  if (!data) return <AdminLoading />;

  const post = data.post;
  const selectedSet = new Set(categoryIds);
  const selectedCategories = data.categories.filter((category) => selectedSet.has(category.id));
  const originalCategoryIds = post.categories.map((category) => category.id).sort((a, b) => a - b);
  const normalizedCategoryIds = [...categoryIds].sort((a, b) => a - b);

  const ownsPost = post.author.id === user.id;
  const canEditOthers = user.capabilities.includes('edit_others_posts');
  const canEditPublished = user.capabilities.includes('edit_published_posts');
  const publishedLike = ['publish', 'future', 'private'].includes(post.status);

  const databaseCanEdit = Boolean(
    writeReadiness?.database.insert.available
      && writeReadiness?.database.update.available
      && writeReadiness?.database.delete.available,
  );
  const databaseCanChangeStatus = Boolean(writeReadiness?.database.update.available);
  const canEdit = databaseCanEdit
    && (ownsPost || canEditOthers)
    && (!publishedLike || canEditPublished);

  const changed =
    title !== post.title
    || slug !== post.slug
    || excerpt !== post.excerpt
    || content !== post.content
    || seoTitle !== post.seo.title
    || seoDescription !== post.seo.description
    || primaryCategoryId !== post.seo.primaryCategoryId
    || JSON.stringify(normalizedCategoryIds) !== JSON.stringify(originalCategoryIds);

  const canChangeStatus = databaseCanChangeStatus
    && (ownsPost || canEditOthers)
    && (!publishedLike || canEditPublished);

  async function savePost() {
    if (!canEdit || !changed || saveState === 'saving') return;

    setSaveState('saving');

    try {
      const payload = await adminFetch<{
        ok: boolean;
        data?: {
          post: {
            id: number;
            title: string;
            slug: string;
            excerpt: string;
            content: string;
            status: string;
            categoryIds: number[];
            seo: {
              title: string;
              description: string;
              primaryCategoryId: number;
            };
            modifiedAt: string;
            publicUrl: string | null;
          };
        };
      }>('/api/admin/post-save.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: JSON.stringify({
          postId: post.id,
          title,
          slug,
          excerpt,
          content,
          categoryIds,
          seoTitle,
          seoDescription,
          primaryCategoryId,
        }),
      });

      if (!payload.ok || !payload.data) {
        throw new Error('post_save_invalid_response');
      }

      const saved = payload.data.post;
      const savedCategories = data.categories
        .filter((category) => saved.categoryIds.includes(category.id))
        .map((category) => ({
          id: category.id,
          name: category.name,
          slug: category.slug,
        }));

      setData((current) => current
        ? {
            ...current,
            post: {
              ...current.post,
              title: saved.title,
              slug: saved.slug,
              excerpt: saved.excerpt,
              content: saved.content,
              categories: savedCategories,
              seo: saved.seo,
              modifiedAt: saved.modifiedAt,
              publicUrl: saved.publicUrl,
            },
          }
        : current
      );
      setSlug(saved.slug);
      setSaveState('saved');
    } catch {
      setSaveState('error');
    }
  }

  async function changeStatus(action: 'publish' | 'draft' | 'schedule') {
    if (!canChangeStatus || statusState === 'working' || changed) return;
    if ((action === 'publish' || action === 'schedule') && !user.permissions.publishPosts) return;

    setStatusState('working');

    try {
      const payload = await adminFetch<{
        ok: boolean;
        data?: {
          post: {
            id: number;
            status: string;
            slug: string;
            publishedAt: string;
            modifiedAt: string;
            publicUrl: string | null;
          };
        };
      }>('/api/admin/post-status.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: JSON.stringify({
          postId: post.id,
          action,
          scheduledAt: action === 'schedule' ? scheduledAt : undefined,
        }),
      });

      if (!payload.ok || !payload.data) {
        throw new Error('post_status_invalid_response');
      }

      const saved = payload.data.post;

      setData((current) => current
        ? {
            ...current,
            post: {
              ...current.post,
              status: saved.status,
              slug: saved.slug,
              publishedAt: saved.publishedAt,
              modifiedAt: saved.modifiedAt,
              publicUrl: saved.publicUrl,
            },
          }
        : current
      );
      setSlug(saved.slug);
      setStatusState('saved');
    } catch {
      setStatusState('error');
    }
  }

  async function openMediaPicker() {
    setMediaPickerOpen(true);

    if (mediaItems.length > 0) return;

    try {
      const payload = await adminFetch<MediaPayload>('/api/admin/media.php?page=1&per_page=60');
      if (payload.ok && payload.data) {
        setMediaItems(payload.data.items.filter((item) => item.mimeType.startsWith('image/')));
      }
    } catch {
      setImageState('error');
    }
  }

  async function setFeaturedImage(item: MediaItem | null) {
    if (!canEdit || imageState === 'working') return;

    setImageState('working');

    try {
      const payload = await adminFetch<{
        ok: boolean;
        data?: {
          featuredImage: {
            id: number;
            title: string;
            url: string;
            alt: string;
          } | null;
        };
      }>('/api/admin/post-featured-image.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: JSON.stringify({
          postId: post.id,
          attachmentId: item?.id ?? 0,
        }),
      });

      if (!payload.ok || !payload.data) {
        throw new Error('featured_image_invalid_response');
      }

      setData((current) => current
        ? {
            ...current,
            post: {
              ...current.post,
              featuredImage: payload.data!.featuredImage,
            },
          }
        : current
      );
      setImageState('idle');
      setMediaPickerOpen(false);
    } catch {
      setImageState('error');
    }
  }

  function toggleCategory(categoryId: number) {
    setCategoryIds((current) => {
      const exists = current.includes(categoryId);
      const next = exists
        ? current.filter((id) => id !== categoryId)
        : [...current, categoryId];

      if (exists && primaryCategoryId === categoryId) {
        setPrimaryCategoryId(0);
      }

      return next;
    });
    setSaveState('idle');
  }

  const publicationActionDisabled = !canChangeStatus || changed || statusState === 'working';

  return (
    <>
      <header className="admin-editor-header">
        <div>
          <a href="/sistema/noticias" className="admin-editor-header__back">← Notícias</a>
          <div className="admin-editor-header__title">
            <span className={'admin-status admin-status--' + post.status}>
              {statusLabel(post.status)}
            </span>
            <h1>{post.status === 'draft' ? 'Editar rascunho' : 'Editar notícia'}</h1>
          </div>
          <p>Última alteração {formatAdminDate(post.modifiedAt)}</p>
        </div>

        <div className="admin-editor-header__actions">
          {post.publicUrl && post.status === 'publish' && (
            <a href={post.publicUrl} target="_blank" rel="noopener noreferrer">
              Ver no site ↗
            </a>
          )}

          <button
            type="button"
            disabled={!canEdit || !changed || saveState === 'saving'}
            onClick={() => void savePost()}
          >
            {saveState === 'saving' ? 'Salvando…' : 'Salvar'}
          </button>

          {post.status === 'publish' ? (
            <button
              type="button"
              disabled={publicationActionDisabled}
              onClick={() => void changeStatus('draft')}
            >
              Mover para rascunho
            </button>
          ) : (
            <button
              type="button"
              className="admin-button--primary"
              disabled={
                publicationActionDisabled
                || !user.permissions.publishPosts
              }
              onClick={() => void changeStatus('publish')}
            >
              {statusState === 'working' ? 'Publicando…' : 'Publicar'}
            </button>
          )}
        </div>
      </header>

      {saveState === 'saved' && (
        <div className="admin-save-feedback admin-save-feedback--success" role="status">
          Alterações salvas.
        </div>
      )}

      {saveState === 'error' && (
        <div className="admin-save-feedback admin-save-feedback--error" role="alert">
          Não foi possível salvar as alterações. Tente novamente.
        </div>
      )}

      {statusState === 'saved' && (
        <div className="admin-save-feedback admin-save-feedback--success" role="status">
          Status da notícia atualizado.
        </div>
      )}

      {statusState === 'error' && (
        <div className="admin-save-feedback admin-save-feedback--error" role="alert">
          Não foi possível atualizar a publicação. Tente novamente.
        </div>
      )}

      <div className="admin-editor-layout">
        <section className="admin-editor-main">
          <label className="admin-editor-field admin-editor-field--title">
            <span>Título</span>
            <input
              value={title}
              readOnly={!canEdit}
              onChange={(event) => {
                setTitle(event.target.value);
                setSaveState('idle');
              }}
            />
          </label>

          <label className="admin-editor-field">
            <span>Link</span>
            <div className="admin-slug-field">
              <span>/noticia/</span>
              <input
                value={slug}
                readOnly={!canEdit}
                onChange={(event) => {
                  setSlug(event.target.value);
                  setSaveState('idle');
                }}
              />
            </div>
          </label>

          <label className="admin-editor-field">
            <span>Resumo</span>
            <textarea
              value={excerpt}
              readOnly={!canEdit}
              rows={5}
              onChange={(event) => {
                setExcerpt(event.target.value);
                setSaveState('idle');
              }}
            />
          </label>

          <label className="admin-editor-field">
            <span>Conteúdo</span>
            <textarea
              className="admin-editor-content"
              value={content}
              readOnly={!canEdit}
              rows={28}
              onChange={(event) => {
                setContent(event.target.value);
                setSaveState('idle');
              }}
            />
          </label>

          <section className="admin-editor-card">
            <div className="admin-editor-card__head">
              <span>Busca e compartilhamento</span>
              <strong>SEO</strong>
            </div>

            <div className="admin-editor-card__body admin-editor-card__body--fields">
              <label className="admin-editor-field">
                <span>Título SEO</span>
                <input
                  value={seoTitle}
                  readOnly={!canEdit}
                  placeholder={title}
                  onChange={(event) => {
                    setSeoTitle(event.target.value);
                    setSaveState('idle');
                  }}
                />
              </label>

              <label className="admin-editor-field">
                <span>Descrição SEO</span>
                <textarea
                  value={seoDescription}
                  readOnly={!canEdit}
                  rows={4}
                  placeholder={excerpt}
                  onChange={(event) => {
                    setSeoDescription(event.target.value);
                    setSaveState('idle');
                  }}
                />
              </label>
            </div>
          </section>
        </section>

        <aside className="admin-editor-sidebar">
          <section className="admin-editor-card">
            <div className="admin-editor-card__head">
              <span>Publicação</span>
              <strong>{statusLabel(post.status)}</strong>
            </div>

            <dl className="admin-editor-meta">
              <div>
                <dt>Autor</dt>
                <dd>{post.author.name}</dd>
              </div>
              <div>
                <dt>Publicação</dt>
                <dd>{formatAdminDate(post.publishedAt)}</dd>
              </div>
              <div>
                <dt>Atualização</dt>
                <dd>{formatAdminDate(post.modifiedAt)}</dd>
              </div>
            </dl>

            {post.status !== 'publish' && user.permissions.publishPosts && (
              <div className="admin-schedule">
                <label>
                  <span>Agendar publicação</span>
                  <input
                    type="datetime-local"
                    value={scheduledAt}
                    disabled={!canChangeStatus}
                    onChange={(event) => setScheduledAt(event.target.value)}
                  />
                </label>
                <button
                  type="button"
                  disabled={publicationActionDisabled || scheduledAt === ''}
                  onClick={() => void changeStatus('schedule')}
                >
                  Agendar
                </button>
              </div>
            )}
          </section>

          <section className="admin-editor-card">
            <div className="admin-editor-card__head">
              <span>Organização</span>
              <strong>Categorias</strong>
            </div>

            <div className="admin-editor-categories">
              {data.categories.map((category) => (
                <label key={category.id}>
                  <input
                    type="checkbox"
                    checked={selectedSet.has(category.id)}
                    disabled={!canEdit}
                    onChange={() => toggleCategory(category.id)}
                  />
                  <i style={{ background: category.color }} aria-hidden="true" />
                  <span>{category.name}</span>
                </label>
              ))}
            </div>

            {selectedCategories.length > 0 && (
              <label className="admin-editor-primary-category">
                <span>Categoria principal</span>
                <select
                  value={primaryCategoryId || ''}
                  disabled={!canEdit}
                  onChange={(event) => {
                    setPrimaryCategoryId(Number(event.target.value) || 0);
                    setSaveState('idle');
                  }}
                >
                  <option value="">Automática</option>
                  {selectedCategories.map((category) => (
                    <option value={category.id} key={category.id}>
                      {category.name}
                    </option>
                  ))}
                </select>
              </label>
            )}
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
              <div className="admin-editor-empty">Nenhuma imagem selecionada.</div>
            )}

            <div className="admin-editor-media-actions">
              <button
                type="button"
                disabled={!canEdit || imageState === 'working'}
                onClick={() => void openMediaPicker()}
              >
                {post.featuredImage ? 'Trocar imagem' : 'Escolher imagem'}
              </button>
              {post.featuredImage && (
                <button
                  type="button"
                  className="danger"
                  disabled={!canEdit || imageState === 'working'}
                  onClick={() => void setFeaturedImage(null)}
                >
                  Remover
                </button>
              )}
            </div>

            {imageState === 'error' && (
              <p className="admin-editor-media-error">Não foi possível atualizar a imagem.</p>
            )}
          </section>
        </aside>
      </div>

      {mediaPickerOpen && (
        <div className="admin-media-picker" role="dialog" aria-modal="true" aria-label="Escolher imagem destacada">
          <div className="admin-media-picker__panel">
            <header>
              <div>
                <span>Biblioteca de mídia</span>
                <h2>Escolher imagem destacada</h2>
              </div>
              <button type="button" onClick={() => setMediaPickerOpen(false)} aria-label="Fechar">×</button>
            </header>

            <div className="admin-media-picker__grid">
              {mediaItems.map((item) => (
                <button
                  type="button"
                  key={item.id}
                  disabled={imageState === 'working'}
                  onClick={() => void setFeaturedImage(item)}
                >
                  <img src={item.url} alt={item.alt || item.title} loading="lazy" />
                  <span>{item.title}</span>
                </button>
              ))}
            </div>
          </div>
        </div>
      )}
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
      <div className="admin-page-heading-row">
        <AdminPageHeader
          eyebrow="Taxonomia"
          title="Categorias"
          description="Editorias e municípios usados na organização das notícias."
        />
        <a className="admin-create-button" href="/sistema/categorias/nova">+ Nova categoria</a>
      </div>


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
                  <strong>
                    <a href={'/sistema/categorias/' + category.id}>{category.name}</a>
                  </strong>
                  <div className="admin-row-actions">
                    <a href={'/sistema/categorias/' + category.id}>Abrir</a>
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
                    <small>{category.colorSource === 'termmeta' ? 'Personalizada' : 'Padrão'}</small>
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

function NewCategoryView({ csrfToken }: { csrfToken: string }) {
  const [data, setData] = useState<CategoriesPayload['data']>();
  const [writeReadiness, setWriteReadiness] = useState<WriteReadinessPayload['data']>();
  const [name, setName] = useState('');
  const [slug, setSlug] = useState('');
  const [description, setDescription] = useState('');
  const [parentId, setParentId] = useState<number | null>(null);
  const [color, setColor] = useState('#0B57D0');
  const [saveState, setSaveState] = useState<'idle' | 'saving' | 'error'>('idle');
  const [error, setError] = useState(false);

  useEffect(() => {
    void adminFetch<CategoriesPayload>('/api/admin/categories.php')
      .then((payload) => {
        if (!payload.ok || !payload.data) throw new Error('categories_invalid');
        setData(payload.data);
      })
      .catch(() => setError(true));

    void adminFetch<WriteReadinessPayload>('/api/admin/write-readiness.php')
      .then((payload) => {
        if (payload.ok && payload.data) setWriteReadiness(payload.data);
      })
      .catch(() => {
        // O formulário permanece indisponível quando a verificação não responder.
      });
  }, []);

  if (error) return <AdminError />;
  if (!data) return <AdminLoading />;

  const canCreate = Boolean(
    writeReadiness?.database.insert.available
      && writeReadiness?.database.update.available,
  );

  async function createCategory() {
    if (!canCreate || name.trim() === '' || saveState === 'saving') return;

    setSaveState('saving');

    try {
      const payload = await adminFetch<{
        ok: boolean;
        data?: {
          category: {
            id: number;
            adminUrl: string;
          };
        };
      }>('/api/admin/category-create.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: JSON.stringify({
          name,
          slug,
          description,
          parentId,
          color,
        }),
      });

      if (!payload.ok || !payload.data) {
        throw new Error('category_create_invalid_response');
      }

      window.location.href = payload.data.category.adminUrl;
    } catch {
      setSaveState('error');
    }
  }

  return (
    <>
      <header className="admin-editor-header">
        <div>
          <a href="/sistema/categorias" className="admin-editor-header__back">← Categorias</a>
          <div className="admin-editor-header__title">
            <span className="admin-category-dot" style={{ background: color }} aria-hidden="true" />
            <h1>Nova categoria</h1>
          </div>
          <p>Crie uma nova editoria ou subdivisão regional.</p>
        </div>

        <div className="admin-editor-header__actions">
          <button
            type="button"
            className="admin-button--primary"
            disabled={!canCreate || name.trim() === '' || saveState === 'saving'}
            onClick={() => void createCategory()}
          >
            {saveState === 'saving' ? 'Criando…' : 'Criar categoria'}
          </button>
        </div>
      </header>

      {saveState === 'error' && (
        <div className="admin-save-feedback admin-save-feedback--error" role="alert">
          Não foi possível criar a categoria. Verifique o nome e o slug.
        </div>
      )}

      <div className="admin-category-editor">
        <section className="admin-editor-card">
          <div className="admin-editor-card__head">
            <span>Categoria</span>
            <strong>Informações</strong>
          </div>

          <div className="admin-editor-card__body admin-editor-card__body--fields">
            <label className="admin-editor-field">
              <span>Nome</span>
              <input
                value={name}
                readOnly={!canCreate}
                autoFocus
                onChange={(event) => {
                  setName(event.target.value);
                  if (slug === '') {
                    setSlug(
                      event.target.value
                        .normalize('NFD')
                        .replace(/[\u0300-\u036f]/g, '')
                        .toLowerCase()
                        .replace(/[^a-z0-9]+/g, '-')
                        .replace(/^-+|-+$/g, ''),
                    );
                  }
                }}
              />
            </label>

            <label className="admin-editor-field">
              <span>Slug</span>
              <input
                value={slug}
                readOnly={!canCreate}
                onChange={(event) => setSlug(event.target.value)}
              />
            </label>

            <label className="admin-editor-field">
              <span>Descrição</span>
              <textarea
                value={description}
                readOnly={!canCreate}
                rows={6}
                onChange={(event) => setDescription(event.target.value)}
              />
            </label>

            <label className="admin-editor-field">
              <span>Categoria superior</span>
              <select
                value={parentId ?? ''}
                disabled={!canCreate}
                onChange={(event) => setParentId(event.target.value === '' ? null : Number(event.target.value))}
              >
                <option value="">Nenhuma</option>
                {data.items.map((item) => (
                  <option value={item.id} key={item.id}>{item.name}</option>
                ))}
              </select>
            </label>
          </div>
        </section>

        <aside className="admin-editor-card">
          <div className="admin-editor-card__head">
            <span>Identidade</span>
            <strong>Cor editorial</strong>
          </div>

          <div className="admin-category-color-editor">
            <div className="admin-category-color-editor__swatch" style={{ background: color }} />
            <div>
              <strong>{color}</strong>
              <span>Usada nos detalhes visuais da editoria</span>
            </div>
            <input
              type="color"
              value={color}
              disabled={!canCreate}
              aria-label="Cor editorial"
              onChange={(event) => setColor(event.target.value.toUpperCase())}
            />
          </div>
        </aside>
      </div>
    </>
  );
}

function CategoryEditorView({ csrfToken }: { csrfToken: string }) {
  const match = window.location.pathname.match(/^\/sistema\/categorias\/(\d+)\/?$/);
  const categoryId = match ? Number.parseInt(match[1], 10) : 0;

  const [data, setData] = useState<CategoryDetailPayload['data']>();
  const [writeReadiness, setWriteReadiness] = useState<WriteReadinessPayload['data']>();
  const [name, setName] = useState('');
  const [slug, setSlug] = useState('');
  const [description, setDescription] = useState('');
  const [parentId, setParentId] = useState<number | null>(null);
  const [color, setColor] = useState('');
  const [saveState, setSaveState] = useState<'idle' | 'saving' | 'saved' | 'error'>('idle');
  const [error, setError] = useState(false);

  useEffect(() => {
    if (!categoryId) {
      setError(true);
      return;
    }

    void adminFetch<CategoryDetailPayload>('/api/admin/category.php?id=' + categoryId)
      .then((payload) => {
        if (!payload.ok || !payload.data) throw new Error('category_invalid');

        const category = payload.data.category;
        setData(payload.data);
        setName(category.name);
        setSlug(category.slug);
        setDescription(category.description);
        setParentId(category.parentId);
        setColor(category.color);
      })
      .catch(() => setError(true));

    void adminFetch<WriteReadinessPayload>('/api/admin/write-readiness.php')
      .then((payload) => {
        if (payload.ok && payload.data) setWriteReadiness(payload.data);
      })
      .catch(() => {
        // A edição permanece indisponível quando a verificação não responder.
      });
  }, [categoryId]);

  if (error) return <AdminError />;
  if (!data) return <AdminLoading />;

  const category = data.category;
  const canEdit = Boolean(
    writeReadiness?.database.insert.available
      && writeReadiness?.database.update.available,
  );

  const changed =
    name !== category.name
    || slug !== category.slug
    || description !== category.description
    || parentId !== category.parentId
    || color.toUpperCase() !== category.color.toUpperCase();

  async function saveCategory() {
    if (!canEdit || !changed || saveState === 'saving') return;

    setSaveState('saving');

    try {
      const payload = await adminFetch<{
        ok: boolean;
        data?: {
          category: {
            id: number;
            name: string;
            slug: string;
            description: string;
            parentId: number | null;
            color: string;
            colorSource: 'termmeta';
            publicUrl: string;
          };
        };
      }>('/api/admin/category-save.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: JSON.stringify({
          categoryId: category.id,
          name,
          slug,
          description,
          parentId,
          color,
        }),
      });

      if (!payload.ok || !payload.data) {
        throw new Error('category_save_invalid_response');
      }

      const saved = payload.data.category;
      setData((current) => current
        ? {
            ...current,
            category: {
              ...current.category,
              ...saved,
            },
          }
        : current
      );
      setName(saved.name);
      setSlug(saved.slug);
      setDescription(saved.description);
      setParentId(saved.parentId);
      setColor(saved.color);
      setSaveState('saved');
    } catch {
      setSaveState('error');
    }
  }

  return (
    <>
      <header className="admin-editor-header">
        <div>
          <a href="/sistema/categorias" className="admin-editor-header__back">← Categorias</a>
          <div className="admin-editor-header__title">
            <span
              className="admin-category-dot"
              style={{ background: color || category.color }}
              aria-hidden="true"
            />
            <h1>Editar categoria</h1>
          </div>
          <p>{category.count} posts associados</p>
        </div>

        <div className="admin-editor-header__actions">
          <a href={category.publicUrl} target="_blank" rel="noopener noreferrer">
            Ver editoria ↗
          </a>
          <button
            type="button"
            className="admin-button--primary"
            disabled={!canEdit || !changed || saveState === 'saving'}
            onClick={() => void saveCategory()}
          >
            {saveState === 'saving' ? 'Salvando…' : 'Salvar'}
          </button>
        </div>
      </header>

      {saveState === 'saved' && (
        <div className="admin-save-feedback admin-save-feedback--success" role="status">
          Categoria atualizada.
        </div>
      )}

      {saveState === 'error' && (
        <div className="admin-save-feedback admin-save-feedback--error" role="alert">
          Não foi possível salvar a categoria. Verifique os campos e tente novamente.
        </div>
      )}

      <div className="admin-category-editor">
        <section className="admin-editor-card">
          <div className="admin-editor-card__head">
            <span>Categoria</span>
            <strong>Informações</strong>
          </div>

          <div className="admin-editor-card__body admin-editor-card__body--fields">
            <label className="admin-editor-field">
              <span>Nome</span>
              <input
                value={name}
                readOnly={!canEdit}
                onChange={(event) => {
                  setName(event.target.value);
                  setSaveState('idle');
                }}
              />
            </label>

            <label className="admin-editor-field">
              <span>Slug</span>
              <input
                value={slug}
                readOnly={!canEdit}
                onChange={(event) => {
                  setSlug(event.target.value);
                  setSaveState('idle');
                }}
              />
            </label>

            <label className="admin-editor-field">
              <span>Descrição</span>
              <textarea
                value={description}
                readOnly={!canEdit}
                rows={6}
                onChange={(event) => {
                  setDescription(event.target.value);
                  setSaveState('idle');
                }}
              />
            </label>

            <label className="admin-editor-field">
              <span>Categoria superior</span>
              <select
                value={parentId ?? ''}
                disabled={!canEdit}
                onChange={(event) => {
                  setParentId(event.target.value === '' ? null : Number(event.target.value));
                  setSaveState('idle');
                }}
              >
                <option value="">Nenhuma</option>
                {data.parents.map((parent) => (
                  <option value={parent.id} key={parent.id}>{parent.name}</option>
                ))}
              </select>
            </label>
          </div>
        </section>

        <aside className="admin-editor-sidebar">
          <section className="admin-editor-card">
            <div className="admin-editor-card__head">
              <span>Identidade</span>
              <strong>Cor editorial</strong>
            </div>

            <div className="admin-category-color-editor">
              <div className="admin-category-color-editor__swatch" style={{ background: color || category.color }} />
              <div>
                <strong>{color || category.color}</strong>
                <span>{category.colorSource === 'termmeta' ? 'Cor personalizada' : 'Cor padrão da editoria'}</span>
              </div>
              <input
                type="color"
                value={color || category.color}
                disabled={!canEdit || saveState === 'saving'}
                aria-label="Cor editorial"
                onChange={(event) => {
                  setColor(event.target.value.toUpperCase());
                  setSaveState('idle');
                }}
              />
            </div>
          </section>
        </aside>
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
        description="Gerencie as contas com acesso ao painel."
      />

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
                  <strong>
                    <a href={'/sistema/usuarios/' + user.id}>{user.displayName}</a>
                  </strong>
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

function UserEditorView({ csrfToken }: { csrfToken: string }) {
  const match = window.location.pathname.match(/^\/sistema\/usuarios\/(\d+)\/?$/);
  const userId = match ? Number.parseInt(match[1], 10) : 0;

  const [data, setData] = useState<UserDetailPayload['data']>();
  const [writeReadiness, setWriteReadiness] = useState<WriteReadinessPayload['data']>();
  const [displayName, setDisplayName] = useState('');
  const [email, setEmail] = useState('');
  const [role, setRole] = useState('');
  const [saveState, setSaveState] = useState<'idle' | 'saving' | 'saved' | 'error'>('idle');
  const [error, setError] = useState(false);

  useEffect(() => {
    if (!userId) {
      setError(true);
      return;
    }

    void adminFetch<UserDetailPayload>('/api/admin/user.php?id=' + userId)
      .then((payload) => {
        if (!payload.ok || !payload.data) throw new Error('user_invalid');

        setData(payload.data);
        setDisplayName(payload.data.user.displayName);
        setEmail(payload.data.user.email);
        setRole(payload.data.user.roles[0] ?? '');
      })
      .catch(() => setError(true));

    void adminFetch<WriteReadinessPayload>('/api/admin/write-readiness.php')
      .then((payload) => {
        if (payload.ok && payload.data) setWriteReadiness(payload.data);
      })
      .catch(() => {
        // A edição permanece indisponível quando a verificação não responder.
      });
  }, [userId]);

  if (error) return <AdminError />;
  if (!data) return <AdminLoading />;

  const profileCanSave = Boolean(writeReadiness?.database.update.available);
  const roleCanSave = data.canChangeRole && Boolean(
    writeReadiness?.database.insert.available
      && writeReadiness?.database.update.available,
  );
  const originalRole = data.user.roles[0] ?? '';
  const changed =
    displayName !== data.user.displayName
    || email !== data.user.email
    || role !== originalRole;

  async function saveUser() {
    if (!profileCanSave || !changed || saveState === 'saving') return;
    if (role !== originalRole && !roleCanSave) return;

    setSaveState('saving');

    try {
      const payload = await adminFetch<{
        ok: boolean;
        data?: { user: AdminUser };
      }>('/api/admin/user-save.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: JSON.stringify({
          userId: data.user.id,
          displayName,
          email,
          role,
        }),
      });

      if (!payload.ok || !payload.data) {
        throw new Error('user_save_invalid_response');
      }

      setData((current) => current
        ? {
            ...current,
            user: payload.data!.user,
          }
        : current
      );
      setDisplayName(payload.data.user.displayName);
      setEmail(payload.data.user.email);
      setRole(payload.data.user.roles[0] ?? role);
      setSaveState('saved');
    } catch {
      setSaveState('error');
    }
  }

  return (
    <>
      <header className="admin-editor-header">
        <div>
          <a href="/sistema/usuarios" className="admin-editor-header__back">← Usuários</a>
          <div className="admin-editor-header__title">
            <span className="admin-avatar">{initials(data.user.displayName)}</span>
            <h1>Editar usuário</h1>
          </div>
          <p>@{data.user.login} • cadastrado em {formatAdminDate(data.user.registeredAt)}</p>
        </div>

        <div className="admin-editor-header__actions">
          <button
            type="button"
            className="admin-button--primary"
            disabled={!profileCanSave || !changed || saveState === 'saving' || (role !== originalRole && !roleCanSave)}
            onClick={() => void saveUser()}
          >
            {saveState === 'saving' ? 'Salvando…' : 'Salvar'}
          </button>
        </div>
      </header>

      {saveState === 'saved' && (
        <div className="admin-save-feedback admin-save-feedback--success" role="status">
          Usuário atualizado.
        </div>
      )}

      {saveState === 'error' && (
        <div className="admin-save-feedback admin-save-feedback--error" role="alert">
          Não foi possível salvar o usuário. Verifique os dados e tente novamente.
        </div>
      )}

      <div className="admin-user-editor">
        <section className="admin-editor-card">
          <div className="admin-editor-card__head">
            <span>Perfil</span>
            <strong>Informações do usuário</strong>
          </div>

          <div className="admin-editor-card__body admin-editor-card__body--fields">
            <label className="admin-editor-field">
              <span>Nome de exibição</span>
              <input
                value={displayName}
                readOnly={!profileCanSave}
                onChange={(event) => {
                  setDisplayName(event.target.value);
                  setSaveState('idle');
                }}
              />
            </label>

            <label className="admin-editor-field">
              <span>Login</span>
              <input value={data.user.login} readOnly />
            </label>

            <label className="admin-editor-field">
              <span>E-mail</span>
              <input
                type="email"
                value={email}
                readOnly={!profileCanSave}
                onChange={(event) => {
                  setEmail(event.target.value);
                  setSaveState('idle');
                }}
              />
            </label>

            <label className="admin-editor-field">
              <span>Função</span>
              <select
                value={role}
                disabled={!roleCanSave}
                onChange={(event) => {
                  setRole(event.target.value);
                  setSaveState('idle');
                }}
              >
                {data.roles.map((item) => (
                  <option value={item.key} key={item.key}>
                    {roleLabel(item.key)}
                  </option>
                ))}
              </select>
            </label>
          </div>
        </section>

        <aside className="admin-editor-card">
          <div className="admin-editor-card__head">
            <span>Acesso</span>
            <strong>Permissões</strong>
          </div>

          <div className="admin-user-permissions">
            <span className={data.user.permissions.editPosts ? 'active' : ''}>Editar notícias</span>
            <span className={data.user.permissions.publishPosts ? 'active' : ''}>Publicar notícias</span>
            <span className={data.user.permissions.manageCategories ? 'active' : ''}>Gerenciar categorias</span>
            <span className={data.user.permissions.uploadFiles ? 'active' : ''}>Enviar mídia</span>
            <span className={data.user.permissions.editUsers ? 'active' : ''}>Gerenciar usuários</span>
            <span className={data.user.permissions.manageOptions ? 'active' : ''}>Configurar site</span>
          </div>
        </aside>
      </div>
    </>
  );
}

function MediaView({ csrfToken }: { csrfToken: string }) {
  const params = useMemo(() => new URLSearchParams(window.location.search), []);
  const page = Math.max(1, Number.parseInt(params.get('page') ?? '1', 10) || 1);

  const [data, setData] = useState<MediaPayload['data']>();
  const [writeReadiness, setWriteReadiness] = useState<WriteReadinessPayload['data']>();
  const [uploadState, setUploadState] = useState<'idle' | 'uploading' | 'saved' | 'error'>('idle');
  const [error, setError] = useState(false);

  useEffect(() => {
    void adminFetch<MediaPayload>('/api/admin/media.php?page=' + page + '&per_page=36')
      .then((payload) => {
        if (!payload.ok || !payload.data) throw new Error('media_invalid');
        setData(payload.data);
      })
      .catch(() => setError(true));

    void adminFetch<WriteReadinessPayload>('/api/admin/write-readiness.php')
      .then((payload) => {
        if (payload.ok && payload.data) setWriteReadiness(payload.data);
      })
      .catch(() => {
        // Upload permanece indisponível quando a verificação não responder.
      });
  }, [page]);

  if (error) return <AdminError />;
  if (!data) return <AdminLoading />;

  const canUpload = Boolean(writeReadiness?.database.insert.available);

  async function uploadFile(file: File) {
    if (!canUpload || uploadState === 'uploading') return;

    setUploadState('uploading');

    try {
      const body = new FormData();
      body.append('file', file);

      const response = await fetch('/api/admin/media-upload.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'X-CSRF-Token': csrfToken,
        },
        body,
      });

      const payload = (await response.json()) as {
        ok: boolean;
        data?: {
          media: MediaItem;
        };
      };

      if (!response.ok || !payload.ok || !payload.data) {
        throw new Error('media_upload_failed');
      }

      setData((current) => current
        ? {
            ...current,
            items: [payload.data!.media, ...current.items].slice(0, current.pagination.perPage),
            pagination: {
              ...current.pagination,
              total: current.pagination.total + 1,
              totalPages: Math.max(
                1,
                Math.ceil((current.pagination.total + 1) / current.pagination.perPage),
              ),
            },
          }
        : current
      );
      setUploadState('saved');
    } catch {
      setUploadState('error');
    }
  }

  return (
    <>
      <div className="admin-page-heading-row">
        <AdminPageHeader
          eyebrow="Acervo"
          title="Mídia"
          description="Imagens e arquivos usados nas publicações do site."
        />

        <label className={canUpload ? 'admin-create-button' : 'admin-create-button admin-create-button--disabled'}>
          {uploadState === 'uploading' ? 'Enviando…' : '+ Enviar imagem'}
          <input
            type="file"
            accept="image/jpeg,image/png,image/webp,image/gif"
            disabled={!canUpload || uploadState === 'uploading'}
            onChange={(event) => {
              const file = event.target.files?.[0];
              if (file) void uploadFile(file);
              event.currentTarget.value = '';
            }}
          />
        </label>
      </div>

      {uploadState === 'saved' && (
        <div className="admin-save-feedback admin-save-feedback--success" role="status">
          Imagem adicionada à biblioteca.
        </div>
      )}

      {uploadState === 'error' && (
        <div className="admin-save-feedback admin-save-feedback--error" role="alert">
          Não foi possível enviar a imagem. Use JPG, PNG, WebP ou GIF com até 12 MB.
        </div>
      )}

      <section className="admin-media-grid" aria-label="Biblioteca de mídia">
        {data.items.map((item) => (
          <article className="admin-media-card" key={item.id}>
            <a className="admin-media-card__preview" href={'/sistema/midia/' + item.id}>
              {item.mimeType.startsWith('image/') ? (
                <img src={item.url} alt={item.alt || item.title} loading="lazy" />
              ) : (
                <span>{item.mimeType || 'arquivo'}</span>
              )}
            </a>

            <div className="admin-media-card__body">
              <strong><a href={'/sistema/midia/' + item.id}>{item.title}</a></strong>
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

function SettingsView({ csrfToken }: { csrfToken: string }) {
  const [data, setData] = useState<SettingsPayload['data']>();
  const [writeReadiness, setWriteReadiness] = useState<WriteReadinessPayload['data']>();
  const [values, setValues] = useState<Record<string, string>>({});
  const [saveState, setSaveState] = useState<'idle' | 'saving' | 'saved' | 'error'>('idle');
  const [error, setError] = useState(false);

  useEffect(() => {
    void adminFetch<SettingsPayload>('/api/admin/settings.php')
      .then((payload) => {
        if (!payload.ok || !payload.data) throw new Error('settings_invalid');
        setData(payload.data);
        setValues(payload.data.options);
      })
      .catch(() => setError(true));

    void adminFetch<WriteReadinessPayload>('/api/admin/write-readiness.php')
      .then((payload) => {
        if (payload.ok && payload.data) setWriteReadiness(payload.data);
      })
      .catch(() => {
        // A edição permanece indisponível quando a verificação não responder.
      });
  }, []);

  if (error) return <AdminError />;
  if (!data) return <AdminLoading />;

  const canEdit = Boolean(
    writeReadiness?.database.insert.available
      && writeReadiness?.database.update.available,
  );

  const editableKeys = [
    'blogname',
    'blogdescription',
    'admin_email',
    'posts_per_page',
    'timezone_string',
    'date_format',
    'time_format',
  ];

  const changed = editableKeys.some(
    (key) => (values[key] ?? '') !== (data.options[key] ?? ''),
  );

  function setOption(key: string, value: string) {
    setValues((current) => ({ ...current, [key]: value }));
    setSaveState('idle');
  }

  async function saveSettings() {
    if (!canEdit || !changed || saveState === 'saving') return;

    setSaveState('saving');

    try {
      const payload = await adminFetch<{
        ok: boolean;
        data?: { options: Record<string, string> };
      }>('/api/admin/settings-save.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: JSON.stringify({
          options: Object.fromEntries(
            editableKeys.map((key) => [key, values[key] ?? '']),
          ),
        }),
      });

      if (!payload.ok || !payload.data) {
        throw new Error('settings_save_invalid_response');
      }

      setData((current) => current
        ? {
            ...current,
            options: {
              ...current.options,
              ...payload.data!.options,
            },
          }
        : current
      );
      setValues((current) => ({
        ...current,
        ...payload.data!.options,
      }));
      setSaveState('saved');
    } catch {
      setSaveState('error');
    }
  }

  return (
    <>
      <div className="admin-page-heading-row">
        <AdminPageHeader
          eyebrow="Site"
          title="Configurações"
          description="Informações gerais e preferências do site."
        />

        <button
          type="button"
          className="admin-create-button"
          disabled={!canEdit || !changed || saveState === 'saving'}
          onClick={() => void saveSettings()}
        >
          {saveState === 'saving' ? 'Salvando…' : 'Salvar alterações'}
        </button>
      </div>

      {saveState === 'saved' && (
        <div className="admin-save-feedback admin-save-feedback--success" role="status">
          Configurações salvas.
        </div>
      )}

      {saveState === 'error' && (
        <div className="admin-save-feedback admin-save-feedback--error" role="alert">
          Não foi possível salvar as configurações. Verifique os campos e tente novamente.
        </div>
      )}

      <section className="admin-settings admin-settings--form">
        <label>
          <span>Nome do site</span>
          <input
            value={values.blogname ?? ''}
            readOnly={!canEdit}
            onChange={(event) => setOption('blogname', event.target.value)}
          />
        </label>

        <label className="admin-settings__wide">
          <span>Descrição</span>
          <textarea
            value={values.blogdescription ?? ''}
            readOnly={!canEdit}
            rows={3}
            onChange={(event) => setOption('blogdescription', event.target.value)}
          />
        </label>

        <label>
          <span>E-mail administrativo</span>
          <input
            type="email"
            value={values.admin_email ?? ''}
            readOnly={!canEdit}
            onChange={(event) => setOption('admin_email', event.target.value)}
          />
        </label>

        <label>
          <span>Notícias por página</span>
          <input
            type="number"
            min="1"
            max="100"
            value={values.posts_per_page ?? '10'}
            readOnly={!canEdit}
            onChange={(event) => setOption('posts_per_page', event.target.value)}
          />
        </label>

        <label>
          <span>Fuso horário</span>
          <select
            value={values.timezone_string || 'America/Sao_Paulo'}
            disabled={!canEdit}
            onChange={(event) => setOption('timezone_string', event.target.value)}
          >
            <option value="America/Sao_Paulo">Brasília / Rio Grande do Sul</option>
            <option value="America/Fortaleza">Fortaleza</option>
            <option value="America/Manaus">Manaus</option>
            <option value="America/Rio_Branco">Rio Branco</option>
            <option value="America/Noronha">Fernando de Noronha</option>
          </select>
        </label>

        <label>
          <span>Formato de data</span>
          <input
            value={values.date_format ?? 'd/m/Y'}
            readOnly={!canEdit}
            onChange={(event) => setOption('date_format', event.target.value)}
          />
        </label>

        <label>
          <span>Formato de hora</span>
          <input
            value={values.time_format ?? 'H:i'}
            readOnly={!canEdit}
            onChange={(event) => setOption('time_format', event.target.value)}
          />
        </label>

        <div className="admin-settings__wide admin-settings__info">
          <span>Endereço público</span>
          <strong>{data.options.home || 'https://nossojornal.com.br'}</strong>
        </div>
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
        eyebrow="Planejamento editorial"
        title="Mesa de Pautas"
        description="Radar editorial para Pelotas, tecnologia, inteligência artificial, universo, ciência e temas correlatos."
      />

      <section className="admin-pautas-summary" aria-label="Resumo da Mesa de Pautas">
        <div>
          <span>Fontes</span>
          <strong>{data.sources.length}</strong>
        </div>
        <div>
          <span>Temas</span>
          <strong>{categories.length}</strong>
        </div>
        <div>
          <span>Fluxo</span>
          <strong>{data.pipeline.length} etapas</strong>
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
            <h2>Fontes monitoradas</h2>
          </div>

        </div>

        <div className="admin-pautas-sources">
          {data.sources.map((source) => (
            <article key={source.feedUrl}>
              <div>
                <span className="admin-pautas-source__category">{source.category}</span>
                <h3>{source.name}</h3>
                <p>{source.kind} • prioridade editorial {source.priority}</p>
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
          {view === 'posts' && <PostsView csrfToken={csrfToken} />}
          {view === 'post' && <PostEditorView user={user} csrfToken={csrfToken} />}
          {view === 'categories' && <CategoriesView />}
          {view === 'categoryNew' && <NewCategoryView csrfToken={csrfToken} />}
          {view === 'category' && <CategoryEditorView csrfToken={csrfToken} />}
          {view === 'media' && <MediaView csrfToken={csrfToken} />}
          {view === 'users' && <UsersView />}
          {view === 'user' && <UserEditorView csrfToken={csrfToken} />}
          {view === 'settings' && <SettingsView csrfToken={csrfToken} />}
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
