import { FormEvent, useCallback, useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import './system.css';

type SystemPermission =
  | 'dashboard'
  | 'posts'
  | 'categories'
  | 'media'
  | 'users'
  | 'settings'
  | 'write';

type SystemUser = {
  id: number;
  login: string;
  nicename: string;
  email: string;
  url: string;
  registeredAt: string;
  status: number;
  displayName: string;
  roles: string[];
  permissions: Record<SystemPermission, boolean>;
};

type SessionPayload = {
  ok: boolean;
  data?: {
    authenticated: boolean;
    user: SystemUser | null;
    csrfToken: string;
  };
  error?: { code: string };
};

type DashboardPayload = {
  ok: boolean;
  data?: {
    summary: {
      posts: Record<string, number>;
      categories: number;
      users: number;
      media: number;
    };
    recentPosts: Array<{
      id: number;
      title: string;
      slug: string;
      status: string;
      publishedAt: string;
      modifiedAt: string;
      authorName: string;
      publicUrl: string | null;
    }>;
    user: SystemUser;
  };
};

type Category = {
  id: number;
  name: string;
  slug: string;
  url: string;
  parentId: number | null;
  publishedCount: number;
  color: string;
  colorSource?: 'termmeta' | 'palette';
};

type CategoriesPayload = {
  ok: boolean;
  data?: {
    items: Category[];
  };
};

type PostsPayload = {
  ok: boolean;
  data?: {
    items: Array<{
      id: number;
      title: string;
      slug: string;
      status: string;
      publishedAt: string;
      modifiedAt: string;
      author: { id: number; name: string };
      views: number;
      categories: Category[];
      publicUrl: string | null;
    }>;
    filters: {
      status: string;
      query: string;
    };
    pagination: {
      page: number;
      perPage: number;
      total: number;
      totalPages: number;
    };
  };
};

type UsersPayload = {
  ok: boolean;
  data?: {
    items: Array<{
      id: number;
      login: string;
      nicename: string;
      email: string;
      url: string;
      registeredAt: string;
      status: number;
      displayName: string;
      roles: string[];
    }>;
    count: number;
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
  };
};

type SettingsPayload = {
  ok: boolean;
  data?: {
    options: Record<string, string>;
    readOnly: boolean;
  };
};

type SystemSection =
  | 'dashboard'
  | 'posts'
  | 'categories'
  | 'media'
  | 'users'
  | 'settings';

const SYSTEM_MENU: Array<{
  key: SystemSection;
  label: string;
  path: string;
  permission: SystemPermission;
}> = [
  { key: 'dashboard', label: 'Painel', path: '/sistema', permission: 'dashboard' },
  { key: 'posts', label: 'Posts', path: '/sistema/posts', permission: 'posts' },
  { key: 'categories', label: 'Categorias', path: '/sistema/categorias', permission: 'categories' },
  { key: 'media', label: 'Mídia', path: '/sistema/midia', permission: 'media' },
  { key: 'users', label: 'Usuários', path: '/sistema/usuarios', permission: 'users' },
  { key: 'settings', label: 'Configurações', path: '/sistema/configuracoes', permission: 'settings' },
];

function resolveSystemSection(pathname: string): SystemSection {
  const clean = pathname.replace(/\/+$/, '') || '/sistema';

  if (clean === '/sistema/posts') return 'posts';
  if (clean === '/sistema/categorias') return 'categories';
  if (clean === '/sistema/midia') return 'media';
  if (clean === '/sistema/usuarios') return 'users';
  if (clean === '/sistema/configuracoes') return 'settings';

  return 'dashboard';
}

function formatSystemDate(value: string) {
  const date = new Date(value.includes('T') ? value : value.replace(' ', 'T'));

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

async function systemFetch<T>(url: string, init?: RequestInit): Promise<T> {
  const response = await fetch(url, {
    ...init,
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      ...(init?.headers ?? {}),
    },
  });

  const payload = (await response.json()) as T & { error?: { code?: string } };

  if (!response.ok) {
    throw new Error(payload.error?.code ?? `http_${response.status}`);
  }

  return payload;
}

function initials(name: string) {
  const parts = name.trim().split(/\s+/).filter(Boolean);

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

function SystemLogin({ onAuthenticated }: { onAuthenticated: (session: NonNullable<SessionPayload['data']>) => void }) {
  const [login, setLogin] = useState('');
  const [password, setPassword] = useState('');
  const [state, setState] = useState<'idle' | 'loading' | 'error'>('idle');

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setState('loading');

    try {
      const payload = await systemFetch<SessionPayload>('/api/v1/system/session.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ login, password }),
      });

      if (!payload.data?.authenticated || !payload.data.user) {
        throw new Error('invalid_session');
      }

      onAuthenticated(payload.data);
    } catch {
      setState('error');
    }
  }

  return (
    <main className="system-login">
      <section className="system-login__card" aria-labelledby="system-login-title">
        <a className="system-login__brand" href="/" aria-label="Voltar para o Nosso Jornal">
          <img src="/nosso-jornal-hulha-negra-bage.png" alt="Nosso Jornal" />
        </a>

        <span className="system-login__kicker">Administração editorial</span>
        <h1 id="system-login-title">Acessar o sistema</h1>
        <p>Use o mesmo usuário do legado WordPress para entrar.</p>

        <form onSubmit={submit}>
          <label htmlFor="system-login-user">Usuário ou e-mail</label>
          <input
            id="system-login-user"
            value={login}
            onChange={(event) => setLogin(event.target.value)}
            autoComplete="username"
            required
          />

          <label htmlFor="system-login-password">Senha</label>
          <input
            id="system-login-password"
            type="password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            autoComplete="current-password"
            required
          />

          {state === 'error' && (
            <div className="system-login__error" role="alert">
              Usuário ou senha inválidos.
            </div>
          )}

          <button type="submit" disabled={state === 'loading'}>
            {state === 'loading' ? 'Entrando…' : 'Entrar'}
          </button>
        </form>

        <a className="system-login__back" href="/">← Voltar para o site</a>
      </section>
    </main>
  );
}

function SystemShell({
  session,
  section,
  onLogout,
  children,
}: {
  session: NonNullable<SessionPayload['data']>;
  section: SystemSection;
  onLogout: () => void;
  children: ReactNode;
}) {
  const user = session.user!;

  const menu = SYSTEM_MENU.filter((item) => user.permissions[item.permission]);

  return (
    <div className="system-shell">
      <aside className="system-sidebar">
        <a className="system-sidebar__brand" href="/sistema">
          <img src="/nosso-jornal-hulha-negra-bage.png" alt="" />
          <span>
            <strong>Nosso Jornal</strong>
            <small>Sistema</small>
          </span>
        </a>

        <nav className="system-sidebar__nav" aria-label="Administração">
          {menu.map((item) => (
            <a
              key={item.key}
              href={item.path}
              className={section === item.key ? 'is-active' : undefined}
              aria-current={section === item.key ? 'page' : undefined}
            >
              <span className="system-sidebar__dot" />
              {item.label}
            </a>
          ))}
        </nav>

        <div className="system-sidebar__footer">
          <a href="/" target="_blank" rel="noopener noreferrer">
            Ver site ↗
          </a>
        </div>
      </aside>

      <div className="system-workspace">
        <header className="system-topbar">
          <div>
            <strong>{SYSTEM_MENU.find((item) => item.key === section)?.label ?? 'Painel'}</strong>
            <span>Nosso Jornal</span>
          </div>

          <div className="system-topbar__user">
            <span className="system-avatar" aria-hidden="true">{initials(user.displayName || user.login)}</span>
            <div>
              <strong>{user.displayName || user.login}</strong>
              <small>{user.roles.map(roleLabel).join(', ') || 'Usuário'}</small>
            </div>
            <button type="button" onClick={onLogout}>Sair</button>
          </div>
        </header>

        <main className="system-content">
          {children}
        </main>
      </div>
    </div>
  );
}

function SystemPageHeading({
  eyebrow,
  title,
  description,
  actionLabel,
  actionDisabled = true,
}: {
  eyebrow?: string;
  title: string;
  description?: string;
  actionLabel?: string;
  actionDisabled?: boolean;
}) {
  return (
    <header className="system-page-heading">
      <div>
        {eyebrow && <span>{eyebrow}</span>}
        <h1>{title}</h1>
        {description && <p>{description}</p>}
      </div>

      {actionLabel && (
        <button
          className="system-primary-action"
          type="button"
          disabled={actionDisabled}
          title={actionDisabled ? 'Disponível quando o write do banco for habilitado' : undefined}
        >
          {actionLabel}
        </button>
      )}
    </header>
  );
}

function SystemLoading() {
  return (
    <div className="system-loading" aria-busy="true">
      <span />
      <span />
      <span />
    </div>
  );
}

function DashboardView() {
  const [data, setData] = useState<DashboardPayload['data']>();
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');

  useEffect(() => {
    systemFetch<DashboardPayload>('/api/v1/system/dashboard.php')
      .then((payload) => {
        if (!payload.data) throw new Error('dashboard_empty');
        setData(payload.data);
        setState('ready');
      })
      .catch(() => setState('error'));
  }, []);

  if (state === 'loading') return <SystemLoading />;
  if (state === 'error' || !data) {
    return <div className="system-notice system-notice--error">Não foi possível carregar o painel.</div>;
  }

  const totalPosts = Object.values(data.summary.posts).reduce((sum, value) => sum + value, 0);

  return (
    <>
      <SystemPageHeading
        eyebrow="Visão geral"
        title="Painel"
        description="Atalhos e estado atual do conteúdo do Nosso Jornal."
      />

      <section className="system-stat-grid" aria-label="Resumo do site">
        <a className="system-stat" href="/sistema/posts">
          <span>Posts</span>
          <strong>{totalPosts}</strong>
          <small>{data.summary.posts.publish ?? 0} publicados</small>
        </a>
        <a className="system-stat" href="/sistema/categorias">
          <span>Categorias</span>
          <strong>{data.summary.categories}</strong>
          <small>Editorias e cobertura</small>
        </a>
        <a className="system-stat" href="/sistema/midia">
          <span>Mídia</span>
          <strong>{data.summary.media}</strong>
          <small>Arquivos no acervo</small>
        </a>
        {data.user.permissions.users && (
          <a className="system-stat" href="/sistema/usuarios">
            <span>Usuários</span>
            <strong>{data.summary.users}</strong>
            <small>Acessos cadastrados</small>
          </a>
        )}
      </section>

      <div className="system-dashboard-grid">
        <section className="system-panel">
          <div className="system-panel__heading">
            <div>
              <span>Conteúdo</span>
              <h2>Posts recentes</h2>
            </div>
            <a href="/sistema/posts">Ver todos</a>
          </div>

          <div className="system-list">
            {data.recentPosts.map((post) => (
              <div className="system-list__row" key={post.id}>
                <div>
                  <strong>{post.title}</strong>
                  <small>{post.authorName || 'Nosso Jornal'} • {formatSystemDate(post.modifiedAt)}</small>
                </div>
                <span className={`system-status system-status--${post.status}`}>
                  {statusLabel(post.status)}
                </span>
              </div>
            ))}
          </div>
        </section>

        <aside className="system-panel">
          <div className="system-panel__heading">
            <div>
              <span>Publicação</span>
              <h2>Estado editorial</h2>
            </div>
          </div>

          <dl className="system-status-list">
            {Object.entries(data.summary.posts).map(([status, count]) => (
              <div key={status}>
                <dt>{statusLabel(status)}</dt>
                <dd>{count}</dd>
              </div>
            ))}
          </dl>

          <div className="system-readonly-note">
            <strong>Modo seguro</strong>
            <p>Leitura ativa. Escrita será liberada quando a capability MySQL write estiver homologada.</p>
          </div>
        </aside>
      </div>
    </>
  );
}

function PostsView() {
  const [data, setData] = useState<PostsPayload['data']>();
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [query, setQuery] = useState('');
  const [status, setStatus] = useState('all');

  const load = useCallback((nextQuery = query, nextStatus = status) => {
    setState('loading');
    const params = new URLSearchParams({
      page: '1',
      per_page: '30',
      status: nextStatus,
    });

    if (nextQuery.trim()) params.set('q', nextQuery.trim());

    systemFetch<PostsPayload>(`/api/v1/system/posts.php?${params.toString()}`)
      .then((payload) => {
        if (!payload.data) throw new Error('posts_empty');
        setData(payload.data);
        setState('ready');
      })
      .catch(() => setState('error'));
  }, [query, status]);

  useEffect(() => {
    load('', 'all');
  }, []);

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    load();
  }

  function changeStatus(next: string) {
    setStatus(next);
    load(query, next);
  }

  return (
    <>
      <SystemPageHeading
        eyebrow="Conteúdo"
        title="Posts"
        description="Listagem editorial do acervo e das publicações do jornal."
        actionLabel="Adicionar novo"
      />

      <form className="system-toolbar" onSubmit={submit}>
        <div className="system-status-tabs" aria-label="Filtrar por status">
          {['all', 'publish', 'draft', 'pending', 'future'].map((item) => (
            <button
              type="button"
              key={item}
              className={status === item ? 'is-active' : undefined}
              onClick={() => changeStatus(item)}
            >
              {item === 'all' ? 'Todos' : statusLabel(item)}
            </button>
          ))}
        </div>

        <div className="system-search">
          <input
            type="search"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            placeholder="Buscar posts"
            aria-label="Buscar posts"
          />
          <button type="submit">Buscar</button>
        </div>
      </form>

      {state === 'loading' && <SystemLoading />}
      {state === 'error' && (
        <div className="system-notice system-notice--error">Não foi possível carregar os posts.</div>
      )}

      {state === 'ready' && data && (
        <section className="system-table-wrap">
          <table className="system-table">
            <thead>
              <tr>
                <th>Título</th>
                <th>Autor</th>
                <th>Categorias</th>
                <th>Status</th>
                <th>Data</th>
                <th>Views</th>
              </tr>
            </thead>
            <tbody>
              {data.items.map((post) => (
                <tr key={post.id}>
                  <td className="system-table__title">
                    <strong>{post.title}</strong>
                    <div className="system-row-actions">
                      <span>Editar</span>
                      {post.publicUrl && (
                        <a href={post.publicUrl} target="_blank" rel="noopener noreferrer">Ver</a>
                      )}
                    </div>
                  </td>
                  <td>{post.author.name || 'Nosso Jornal'}</td>
                  <td>
                    <div className="system-category-tags">
                      {post.categories.map((category) => (
                        <span key={category.id} style={{ borderColor: category.color }}>
                          <i style={{ background: category.color }} />
                          {category.name}
                        </span>
                      ))}
                    </div>
                  </td>
                  <td><span className={`system-status system-status--${post.status}`}>{statusLabel(post.status)}</span></td>
                  <td>{formatSystemDate(post.modifiedAt)}</td>
                  <td>{new Intl.NumberFormat('pt-BR').format(post.views)}</td>
                </tr>
              ))}
            </tbody>
          </table>

          <footer className="system-table-footer">
            {data.pagination.total} registros
          </footer>
        </section>
      )}
    </>
  );
}

function CategoriesView() {
  const [data, setData] = useState<Category[]>([]);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');

  useEffect(() => {
    systemFetch<CategoriesPayload>('/api/v1/categories.php?include_empty=1')
      .then((payload) => {
        setData(payload.data?.items ?? []);
        setState('ready');
      })
      .catch(() => setState('error'));
  }, []);

  return (
    <>
      <SystemPageHeading
        eyebrow="Taxonomia"
        title="Categorias"
        description="Editorias, municípios e identidade cromática do portal."
        actionLabel="Adicionar categoria"
      />

      {state === 'loading' && <SystemLoading />}
      {state === 'error' && <div className="system-notice system-notice--error">Não foi possível carregar as categorias.</div>}

      {state === 'ready' && (
        <section className="system-table-wrap">
          <table className="system-table">
            <thead>
              <tr>
                <th>Nome</th>
                <th>Slug</th>
                <th>Cor editorial</th>
                <th>Origem</th>
                <th>Posts</th>
              </tr>
            </thead>
            <tbody>
              {data.map((category) => (
                <tr key={category.id}>
                  <td className="system-table__title">
                    <strong>{category.name}</strong>
                    <div className="system-row-actions">
                      <span>Editar</span>
                      <a href={category.url} target="_blank" rel="noopener noreferrer">Ver</a>
                    </div>
                  </td>
                  <td><code>{category.slug}</code></td>
                  <td>
                    <span className="system-color-chip">
                      <i style={{ background: category.color }} />
                      {category.color}
                    </span>
                  </td>
                  <td>{category.colorSource === 'termmeta' ? 'Banco' : 'Paleta fallback'}</td>
                  <td>{category.publishedCount}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      )}
    </>
  );
}

function MediaView() {
  const [data, setData] = useState<MediaPayload['data']>();
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');

  useEffect(() => {
    systemFetch<MediaPayload>('/api/v1/system/media.php?per_page=36')
      .then((payload) => {
        if (!payload.data) throw new Error('media_empty');
        setData(payload.data);
        setState('ready');
      })
      .catch(() => setState('error'));
  }, []);

  return (
    <>
      <SystemPageHeading
        eyebrow="Acervo"
        title="Mídia"
        description="Biblioteca de imagens e arquivos importados do WordPress."
        actionLabel="Adicionar mídia"
      />

      {state === 'loading' && <SystemLoading />}
      {state === 'error' && <div className="system-notice system-notice--error">Não foi possível carregar a biblioteca.</div>}

      {state === 'ready' && data && (
        <>
          <div className="system-media-grid">
            {data.items.map((item) => (
              <article className="system-media-card" key={item.id}>
                <div className="system-media-card__preview">
                  {item.mimeType.startsWith('image/') ? (
                    <img src={item.url} alt={item.alt || item.title} loading="lazy" />
                  ) : (
                    <span>{item.mimeType || 'arquivo'}</span>
                  )}
                </div>
                <div>
                  <strong>{item.title}</strong>
                  <small>{formatSystemDate(item.createdAt)}</small>
                </div>
              </article>
            ))}
          </div>
          <div className="system-table-footer">{data.pagination.total} arquivos</div>
        </>
      )}
    </>
  );
}

function UsersView() {
  const [data, setData] = useState<UsersPayload['data']>();
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');

  useEffect(() => {
    systemFetch<UsersPayload>('/api/v1/system/users.php')
      .then((payload) => {
        if (!payload.data) throw new Error('users_empty');
        setData(payload.data);
        setState('ready');
      })
      .catch(() => setState('error'));
  }, []);

  return (
    <>
      <SystemPageHeading
        eyebrow="Acesso"
        title="Usuários"
        description="Usuários e perfis herdados do WordPress."
        actionLabel="Adicionar usuário"
      />

      {state === 'loading' && <SystemLoading />}
      {state === 'error' && <div className="system-notice system-notice--error">Não foi possível carregar os usuários.</div>}

      {state === 'ready' && data && (
        <section className="system-table-wrap">
          <table className="system-table">
            <thead>
              <tr>
                <th>Usuário</th>
                <th>Nome</th>
                <th>E-mail</th>
                <th>Função</th>
                <th>Cadastro</th>
              </tr>
            </thead>
            <tbody>
              {data.items.map((user) => (
                <tr key={user.id}>
                  <td>
                    <div className="system-user-cell">
                      <span className="system-avatar">{initials(user.displayName || user.login)}</span>
                      <strong>{user.login}</strong>
                    </div>
                  </td>
                  <td>{user.displayName}</td>
                  <td><a href={`mailto:${user.email}`}>{user.email}</a></td>
                  <td>{user.roles.map(roleLabel).join(', ') || 'Sem função'}</td>
                  <td>{formatSystemDate(user.registeredAt)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      )}
    </>
  );
}

function SettingsView() {
  const [data, setData] = useState<SettingsPayload['data']>();
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');

  useEffect(() => {
    systemFetch<SettingsPayload>('/api/v1/system/settings.php')
      .then((payload) => {
        if (!payload.data) throw new Error('settings_empty');
        setData(payload.data);
        setState('ready');
      })
      .catch(() => setState('error'));
  }, []);

  if (state === 'loading') return <SystemLoading />;
  if (state === 'error' || !data) {
    return <div className="system-notice system-notice--error">Não foi possível carregar as configurações.</div>;
  }

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
      <SystemPageHeading
        eyebrow="Site"
        title="Configurações"
        description="Configurações herdadas do WordPress. Nesta fase, somente leitura."
        actionLabel="Salvar alterações"
      />

      <section className="system-settings-form">
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

function SystemSectionView({ section }: { section: SystemSection }) {
  if (section === 'posts') return <PostsView />;
  if (section === 'categories') return <CategoriesView />;
  if (section === 'media') return <MediaView />;
  if (section === 'users') return <UsersView />;
  if (section === 'settings') return <SettingsView />;
  return <DashboardView />;
}

export function SystemPage() {
  const section = useMemo(() => resolveSystemSection(window.location.pathname), []);
  const [session, setSession] = useState<SessionPayload['data']>();
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');

  const loadSession = useCallback(() => {
    setState('loading');

    systemFetch<SessionPayload>('/api/v1/system/session.php')
      .then((payload) => {
        if (!payload.data) throw new Error('session_empty');
        setSession(payload.data);
        setState('ready');
      })
      .catch(() => setState('error'));
  }, []);

  useEffect(() => {
    document.title = 'Sistema | Nosso Jornal';

    let robots = document.head.querySelector<HTMLMetaElement>('meta[name="robots"]');
    if (!robots) {
      robots = document.createElement('meta');
      robots.name = 'robots';
      document.head.appendChild(robots);
    }
    robots.content = 'noindex,nofollow';

    loadSession();
  }, [loadSession]);

  async function logout() {
    if (!session?.csrfToken) return;

    try {
      await systemFetch('/api/v1/system/session.php', {
        method: 'DELETE',
        headers: {
          'X-NJ-CSRF': session.csrfToken,
        },
      });
    } finally {
      setSession({
        authenticated: false,
        user: null,
        csrfToken: '',
      });
    }
  }

  if (state === 'loading') {
    return (
      <main className="system-boot" aria-busy="true">
        <img src="/nosso-jornal-hulha-negra-bage.png" alt="Nosso Jornal" />
        <span>Carregando sistema…</span>
      </main>
    );
  }

  if (state === 'error') {
    return (
      <main className="system-boot">
        <strong>Não foi possível iniciar o sistema.</strong>
        <button type="button" onClick={loadSession}>Tentar novamente</button>
      </main>
    );
  }

  if (!session?.authenticated || !session.user) {
    return <SystemLogin onAuthenticated={setSession} />;
  }

  const item = SYSTEM_MENU.find((menuItem) => menuItem.key === section);
  const allowed = item ? session.user.permissions[item.permission] : true;

  return (
    <SystemShell session={session} section={section} onLogout={() => void logout()}>
      {allowed ? (
        <SystemSectionView section={section} />
      ) : (
        <div className="system-notice system-notice--error">
          Seu usuário não possui permissão para acessar esta área.
        </div>
      )}
    </SystemShell>
  );
}
