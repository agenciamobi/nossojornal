import { FormEvent, useCallback, useEffect, useMemo, useState } from 'react';
import { AdminRichEditor, type RichEditorMediaItem } from './AdminRichEditor';
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
    editPages: boolean;
    publishPages: boolean;
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
      workflow: {
        idea: number;
        reporting: number;
        writing: number;
        review: number;
        ready: number;
        scheduled: number;
        published: number;
      };
      overdue: number;
      urgentPautas: number;
    };
    recentPosts: AdminPost[];
    editorial: {
      overdue: Array<{
        id: number;
        title: string;
        status: string;
        stage: string;
        priority: string;
        deadline: string;
        deadlineAt: string;
        assignee: string;
        adminUrl: string;
      }>;
      nextDeadlines: Array<{
        id: number;
        title: string;
        status: string;
        stage: string;
        priority: string;
        deadline: string;
        deadlineAt: string;
        assignee: string;
        adminUrl: string;
      }>;
      review: Array<{
        id: number;
        title: string;
        status: string;
        stage: string;
        priority: string;
        deadline: string;
        assignee: string;
        adminUrl: string;
      }>;
      ready: Array<{
        id: number;
        title: string;
        status: string;
        stage: string;
        priority: string;
        deadline: string;
        assignee: string;
        adminUrl: string;
      }>;
      scheduled: Array<{
        id: number;
        title: string;
        status: string;
        stage: string;
        priority: string;
        deadline: string;
        scheduledAt: string;
        assignee: string;
        adminUrl: string;
      }>;
      urgentPautas: Array<{
        id: number;
        title: string;
        priority: string;
        stage: string;
        deadline: string;
        adminUrl: string;
      }>;
      agenda: Array<{
        id: number;
        title: string;
        kind: string;
        start: string;
        location: string;
        note: string;
        adminUrl: string;
      }>;
    };
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

type PagesPayload = {
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

type PageDetailPayload = {
  ok: boolean;
  data?: {
    page: {
      id: number;
      title: string;
      slug: string;
      slugLocked: boolean;
      excerpt: string;
      content: string;
      status: string;
      publishedAt: string;
      modifiedAt: string;
      author: { id: number; name: string };
      seo: {
        title: string;
        description: string;
      };
      publicUrl: string | null;
    };
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
    query?: string;
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

type PautaItem = {
  id: number;
  title: string;
  notes: string;
  stage: 'inbox' | 'selected' | 'research' | 'ready' | 'writing';
  priority: 'low' | 'normal' | 'high' | 'urgent';
  topic: string;
  sourceName: string;
  sourceUrl: string;
  deadline: string;
  assigneeId: number;
  assignee: string;
  createdAt: string;
  modifiedAt: string;
  draftPostId: number;
  draftAdminUrl: string | null;
};

type PautasPayload = {
  ok: boolean;
  data?: {
    owner?: {
      login: string;
      userId: number;
    };
    pipeline?: string[];
    sources?: Array<{
      name: string;
      category: string;
      feedUrl: string;
      kind: string;
      priority: number;
    }>;
    items: PautaItem[];
    assignees?: Array<{
      id: number;
      login: string;
      name: string;
    }>;
    draft?: {
      id: number;
      adminUrl: string;
    } | null;
    captured?: number;
    captureFailures?: number;
  };
};

type EditorialSourceContact = {
  id: number;
  name: string;
  organization: string;
  role: string;
  phone: string;
  whatsapp: string;
  email: string;
  city: string;
  topics: string[];
  url: string;
  notes: string;
  createdAt: string;
  modifiedAt: string;
};

type SourcesPayload = {
  ok: boolean;
  data?: {
    items: EditorialSourceContact[];
    query?: string;
  };
};

type EditorialSource = {
  name: string;
  organization: string;
  contact: string;
  url: string;
  note: string;
};

type EditorialChecklist = {
  headline: boolean;
  facts: boolean;
  names: boolean;
  dates: boolean;
  sources: boolean;
  imageRights: boolean;
  altText: boolean;
  links: boolean;
  category: boolean;
  seo: boolean;
  review: boolean;
};

type EditorialWorkflow = {
  stage: 'idea' | 'reporting' | 'writing' | 'review' | 'ready' | 'scheduled' | 'published';
  priority: 'low' | 'normal' | 'high' | 'urgent';
  deadline: string;
  assigneeId: number;
  notes: string;
  sources: EditorialSource[];
  checklist: EditorialChecklist;
  automaticChecks: {
    title: boolean;
    excerpt: boolean;
    featuredImage: boolean;
    category: boolean;
    seo: boolean;
  };
  home: {
    slot: 'automatic' | 'hero' | 'featured';
    rank: number;
    until: string;
  };
};

type EditorialWorkflowPayload = {
  ok: boolean;
  data?: {
    editorial: EditorialWorkflow;
    assignees: Array<{
      id: number;
      login: string;
      name: string;
      email: string;
    }>;
  };
};

type HomeLayoutItem = {
  id: number;
  title: string;
  slug: string;
  status: string;
  publishedAt: string;
  modifiedAt: string;
  author: string;
  imageUrl: string | null;
  publicUrl: string;
  home: {
    slot: 'automatic' | 'hero' | 'featured';
    rank: number;
    until: string;
    active: boolean;
  };
};

type HomeLayoutPayload = {
  ok: boolean;
  data?: {
    items: HomeLayoutItem[];
  };
};

type AgendaItem = {
  id: string;
  kind: 'deadline' | 'publication' | 'event';
  eventId?: number;
  eventKind?: 'coverage' | 'interview' | 'meeting' | 'deadline' | 'event';
  postId?: number;
  title: string;
  note?: string;
  start: string;
  end: string | null;
  location: string | null;
  priority: string;
  stage: string;
  status: string;
  assignee: string;
  adminUrl: string | null;
  publicUrl: string | null;
};

type AgendaPayload = {
  ok: boolean;
  data?: {
    items: AgendaItem[];
  };
};

type AdminView = 'dashboard' | 'homeLayout' | 'agenda' | 'sources' | 'posts' | 'post' | 'pages' | 'page' | 'categories' | 'category' | 'categoryNew' | 'media' | 'mediaItem' | 'comments' | 'users' | 'user' | 'settings' | 'pautas';

function resolveAdminView(pathname: string): AdminView {
  const clean = pathname.replace(/\/+$/, '');

  if (clean === '/sistema/capa') return 'homeLayout';
  if (clean === '/sistema/agenda') return 'agenda';
  if (clean === '/sistema/fontes') return 'sources';
  if (clean === '/sistema/noticias') return 'posts';
  if (/^\/sistema\/noticias\/\d+$/.test(clean)) return 'post';
  if (clean === '/sistema/paginas') return 'pages';
  if (/^\/sistema\/paginas\/\d+$/.test(clean)) return 'page';
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

async function loadAdminEditorMedia(query: string): Promise<RichEditorMediaItem[]> {
  const search = new URLSearchParams({
    page: '1',
    per_page: '60',
  });

  if (query.trim()) {
    search.set('q', query.trim());
  }

  const payload = await adminFetch<MediaPayload>('/api/admin/media.php?' + search.toString());

  if (!payload.ok || !payload.data) {
    throw new Error('media_invalid');
  }

  return payload.data.items
    .filter((item) => item.mimeType.startsWith('image/'))
    .map((item) => ({
      id: item.id,
      title: item.title,
      url: item.url,
      alt: item.alt,
    }));
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
  | 'home'
  | 'agenda'
  | 'sources'
  | 'news'
  | 'pages'
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

  if (name === 'home') {
    return (
      <svg {...common}>
        <path d="M3 10.5 12 3l9 7.5" />
        <path d="M5.5 9.5V21h13V9.5" />
        <path d="M9 21v-7h6v7" />
      </svg>
    );
  }

  if (name === 'agenda') {
    return (
      <svg {...common}>
        <rect x="3" y="5" width="18" height="16" rx="2" />
        <path d="M7 3v4M17 3v4M3 10h18" />
        <path d="M7 14h3M14 14h3M7 17h3" />
      </svg>
    );
  }

  if (name === 'sources') {
    return (
      <svg {...common}>
        <path d="M4 5h16v14H4z" />
        <circle cx="9" cy="10" r="2" />
        <path d="M6.5 16c.5-2 1.4-3 2.5-3s2 .9 2.5 3M14 9h3M14 12h3M14 15h2" />
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

  if (name === 'pages') {
    return (
      <svg {...common}>
        <path d="M6 3h8l4 4v14H6z" />
        <path d="M14 3v5h5M9 12h6M9 15h6M9 18h4" />
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
    ...(user.permissions.publishPosts && user.capabilities.includes('edit_others_posts')
      ? [{ key: 'homeLayout' as const, label: 'Capa do site', href: '/sistema/capa', icon: 'home' as const, group: 'content' as const }]
      : []),
    ...(user.permissions.editPosts
      ? [{ key: 'agenda' as const, label: 'Agenda', href: '/sistema/agenda', icon: 'agenda' as const, group: 'content' as const }]
      : []),
    ...(user.permissions.editPosts
      ? [{ key: 'posts' as const, label: 'Notícias', href: '/sistema/noticias', icon: 'news' as const, group: 'content' as const }]
      : []),
    ...(user.permissions.editPages
      ? [{ key: 'pages' as const, label: 'Páginas', href: '/sistema/paginas', icon: 'pages' as const, group: 'content' as const }]
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
    ...(user.permissions.editPosts
      ? [{ key: 'sources' as const, label: 'Fontes', href: '/sistema/fontes', icon: 'sources' as const, group: 'management' as const }]
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
                      || (view === 'page' && entry.key === 'pages')
                      || ((view === 'category' || view === 'categoryNew') && entry.key === 'categories')
                      || (view === 'mediaItem' && entry.key === 'media')
                      || (view === 'user' && entry.key === 'users')
                      ? 'admin-nav__item admin-nav__item--active'
                      : 'admin-nav__item'
                  }
                  aria-current={
                    view === entry.key
                      || (view === 'post' && entry.key === 'posts')
                      || (view === 'page' && entry.key === 'pages')
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

function AdminAccessDenied() {
  return (
    <div className="admin-error" role="alert">
      <strong>Acesso não permitido.</strong>
      <p>Seu usuário não possui permissão para administrar esta área.</p>
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
    { label: 'Em revisão', value: data.summary.workflow.review, href: '#revisao' },
    { label: 'Prontas', value: data.summary.workflow.ready, href: '#prontas' },
    { label: 'Prazos vencidos', value: data.summary.overdue, href: '#prazos', alert: data.summary.overdue > 0 },
    ...(user.login === 'agenciamobi'
      ? [{ label: 'Pautas prioritárias', value: data.summary.urgentPautas, href: '/sistema/pautas', alert: data.summary.urgentPautas > 0 }]
      : []),
  ];

  function editorialPriorityLabel(priority: string) {
    return ({
      urgent: 'Urgente',
      high: 'Alta',
      normal: 'Normal',
      low: 'Baixa',
    } as Record<string, string>)[priority] ?? priority;
  }

  function agendaKindLabel(kind: string) {
    return ({
      coverage: 'Cobertura',
      interview: 'Entrevista',
      meeting: 'Reunião',
      deadline: 'Prazo',
      event: 'Evento',
    } as Record<string, string>)[kind] ?? kind;
  }

  return (
    <>
      <div className="admin-dashboard-heading">
        <AdminPageHeader
          eyebrow="Redação"
          title="Painel"
          description="O que precisa da sua atenção agora."
        />

        <div className="admin-dashboard-quick-actions">
          {user.permissions.publishPosts && user.capabilities.includes('edit_others_posts') && (
            <a href="/sistema/capa">Organizar capa</a>
          )}
          <a href="/sistema/agenda">Abrir agenda</a>
          <a href="/sistema/noticias">Nova matéria</a>
          {user.login === 'agenciamobi' && <a href="/sistema/pautas">Mesa de Pautas</a>}
        </div>
      </div>

      <section className="admin-stats admin-stats--editorial" aria-label="Resumo editorial">
        {stats.map((stat) => (
          <a
            href={stat.href}
            className={'admin-stat' + (stat.alert ? ' admin-stat--alert' : '')}
            key={stat.label}
          >
            <strong>{stat.value}</strong>
            <span>{stat.label}</span>
          </a>
        ))}
      </section>

      {(data.editorial.overdue.length > 0 || data.editorial.nextDeadlines.length > 0) && (
        <section className="admin-editorial-desk" id="prazos">
          <div className="admin-widget__head">
            <div>
              <span>Prazo</span>
              <h2>Relógio da redação</h2>
            </div>
            <a href="/sistema/agenda">Ver agenda</a>
          </div>

          <div className="admin-deadline-columns">
            <div>
              <h3>Vencidos</h3>
              {data.editorial.overdue.length === 0 ? (
                <p className="admin-dashboard-empty">Nenhum prazo vencido.</p>
              ) : (
                data.editorial.overdue.map((item) => (
                  <a className="admin-deadline-row admin-deadline-row--overdue" href={item.adminUrl} key={item.id}>
                    <div>
                      <strong>{item.title}</strong>
                      <span>{item.assignee} • {editorialPriorityLabel(item.priority)}</span>
                    </div>
                    <time>{formatAdminDate(item.deadlineAt)}</time>
                  </a>
                ))
              )}
            </div>

            <div>
              <h3>Próximos 7 dias</h3>
              {data.editorial.nextDeadlines.length === 0 ? (
                <p className="admin-dashboard-empty">Nenhum prazo próximo.</p>
              ) : (
                data.editorial.nextDeadlines.map((item) => (
                  <a className="admin-deadline-row" href={item.adminUrl} key={item.id}>
                    <div>
                      <strong>{item.title}</strong>
                      <span>{item.assignee} • {editorialPriorityLabel(item.priority)}</span>
                    </div>
                    <time>{formatAdminDate(item.deadlineAt)}</time>
                  </a>
                ))
              )}
            </div>
          </div>
        </section>
      )}

      <div className="admin-dashboard-editorial-grid">
        <section className="admin-widget" id="revisao">
          <div className="admin-widget__head">
            <div>
              <span>Workflow</span>
              <h2>Em revisão</h2>
            </div>
            <strong className="admin-widget__count">{data.editorial.review.length}</strong>
          </div>

          <div className="admin-workflow-list">
            {data.editorial.review.map((item) => (
              <a href={item.adminUrl} key={item.id}>
                <span className={'admin-priority admin-priority--' + item.priority}>
                  {editorialPriorityLabel(item.priority)}
                </span>
                <strong>{item.title}</strong>
                <small>{item.assignee}</small>
              </a>
            ))}
            {data.editorial.review.length === 0 && (
              <p className="admin-dashboard-empty">Nenhuma matéria aguardando revisão.</p>
            )}
          </div>
        </section>

        <section className="admin-widget" id="prontas">
          <div className="admin-widget__head">
            <div>
              <span>Workflow</span>
              <h2>Prontas para publicar</h2>
            </div>
            <strong className="admin-widget__count">{data.editorial.ready.length}</strong>
          </div>

          <div className="admin-workflow-list">
            {data.editorial.ready.map((item) => (
              <a href={item.adminUrl} key={item.id}>
                <span className={'admin-priority admin-priority--' + item.priority}>
                  {editorialPriorityLabel(item.priority)}
                </span>
                <strong>{item.title}</strong>
                <small>{item.assignee}</small>
              </a>
            ))}
            {data.editorial.ready.length === 0 && (
              <p className="admin-dashboard-empty">Nenhuma matéria marcada como pronta.</p>
            )}
          </div>
        </section>

        <section className="admin-widget">
          <div className="admin-widget__head">
            <div>
              <span>Publicação</span>
              <h2>Agendadas</h2>
            </div>
            <a href="/sistema/agenda">Agenda</a>
          </div>

          <div className="admin-workflow-list">
            {data.editorial.scheduled.map((item) => (
              <a href={item.adminUrl} key={item.id}>
                <strong>{item.title}</strong>
                <small>{formatAdminDate(item.scheduledAt)}</small>
              </a>
            ))}
            {data.editorial.scheduled.length === 0 && (
              <p className="admin-dashboard-empty">Nenhuma publicação agendada.</p>
            )}
          </div>
        </section>

        <section className="admin-widget">
          <div className="admin-widget__head">
            <div>
              <span>Próximos dias</span>
              <h2>Coberturas e compromissos</h2>
            </div>
            <a href="/sistema/agenda">Ver todas</a>
          </div>

          <div className="admin-workflow-list">
            {data.editorial.agenda.map((item) => (
              <a href={item.adminUrl} key={item.id}>
                <span className="admin-agenda-kind">{agendaKindLabel(item.kind)}</span>
                <strong>{item.title}</strong>
                <small>
                  {formatAdminDate(item.start)}
                  {item.location ? ' • ' + item.location : ''}
                </small>
              </a>
            ))}
            {data.editorial.agenda.length === 0 && (
              <p className="admin-dashboard-empty">Nenhuma cobertura próxima.</p>
            )}
          </div>
        </section>
      </div>

      {user.login === 'agenciamobi' && data.editorial.urgentPautas.length > 0 && (
        <section className="admin-widget admin-dashboard-pautas">
          <div className="admin-widget__head">
            <div>
              <span>Mesa de Pautas</span>
              <h2>Prioridade alta</h2>
            </div>
            <a href="/sistema/pautas">Abrir mesa</a>
          </div>

          <div className="admin-workflow-list admin-workflow-list--horizontal">
            {data.editorial.urgentPautas.map((item) => (
              <a href={item.adminUrl} key={item.id}>
                <span className={'admin-priority admin-priority--' + item.priority}>
                  {editorialPriorityLabel(item.priority)}
                </span>
                <strong>{item.title}</strong>
                {item.deadline && <small>{formatAdminDate(item.deadline)}</small>}
              </a>
            ))}
          </div>
        </section>
      )}

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
              <span>Hoje</span>
              <h2>Fluxo editorial</h2>
            </div>
          </div>

          <dl className="admin-system-list">
            <div><dt>Em apuração</dt><dd>{data.summary.workflow.reporting}</dd></div>
            <div><dt>Em redação</dt><dd>{data.summary.workflow.writing}</dd></div>
            <div><dt>Em revisão</dt><dd>{data.summary.workflow.review}</dd></div>
            <div><dt>Prontas</dt><dd>{data.summary.workflow.ready}</dd></div>
            <div><dt>Agendadas</dt><dd>{data.summary.workflow.scheduled}</dd></div>
            {user.permissions.moderateComments && (
              <div><dt>Comentários pendentes</dt><dd>{data.summary.comments.pending}</dd></div>
            )}
          </dl>
        </aside>
      </div>
    </>
  );
}

function HomeLayoutView({ csrfToken }: { csrfToken: string }) {
  const [data, setData] = useState<HomeLayoutPayload['data']>();
  const [drafts, setDrafts] = useState<Record<number, HomeLayoutItem['home']>>({});
  const [savingId, setSavingId] = useState(0);
  const [message, setMessage] = useState<'idle' | 'saved' | 'error'>('idle');
  const [error, setError] = useState(false);

  useEffect(() => {
    void adminFetch<HomeLayoutPayload>('/api/admin/home-layout.php')
      .then((payload) => {
        if (!payload.ok || !payload.data) throw new Error('home_layout_invalid');
        setData(payload.data);
        setDrafts(Object.fromEntries(payload.data.items.map((item) => [item.id, item.home])));
      })
      .catch(() => setError(true));
  }, []);

  if (error) return <AdminError />;
  if (!data) return <AdminLoading />;

  const activeHero = data.items.find((item) => item.home.slot === 'hero' && item.home.active);
  const featured = data.items
    .filter((item) => item.home.slot === 'featured' && item.home.active)
    .sort((a, b) => a.home.rank - b.home.rank);

  function updateDraft(postId: number, patch: Partial<HomeLayoutItem['home']>) {
    setDrafts((current) => ({
      ...current,
      [postId]: {
        ...(current[postId] ?? { slot: 'automatic', rank: 0, until: '' }),
        ...patch,
      },
    }));
    setMessage('idle');
  }

  async function savePlacement(item: HomeLayoutItem) {
    const draft = drafts[item.id] ?? item.home;
    setSavingId(item.id);
    setMessage('idle');

    try {
      const payload = await adminFetch<HomeLayoutPayload>('/api/admin/home-layout.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: JSON.stringify({
          postId: item.id,
          slot: draft.slot,
          rank: draft.rank,
          until: draft.until,
        }),
      });

      if (!payload.ok || !payload.data) throw new Error('home_layout_save_invalid');

      setData(payload.data);
      setDrafts(Object.fromEntries(payload.data.items.map((row) => [row.id, row.home])));
      setMessage('saved');
    } catch {
      setMessage('error');
    } finally {
      setSavingId(0);
    }
  }

  return (
    <>
      <AdminPageHeader
        eyebrow="Edição de capa"
        title="Capa do site"
        description="Escolha a manchete principal e os destaques da página inicial."
      />

      {message === 'saved' && (
        <div className="admin-save-feedback admin-save-feedback--success" role="status">
          Capa atualizada.
        </div>
      )}
      {message === 'error' && (
        <div className="admin-save-feedback admin-save-feedback--error" role="alert">
          Não foi possível atualizar a capa. Tente novamente.
        </div>
      )}

      <section className="admin-home-preview">
        <div className="admin-home-preview__hero">
          <span>Manchete principal</span>
          {activeHero ? (
            <article>
              {activeHero.imageUrl && <img src={activeHero.imageUrl} alt="" />}
              <div>
                <strong>{activeHero.title}</strong>
                <a href={activeHero.publicUrl} target="_blank" rel="noopener noreferrer">Ver notícia ↗</a>
              </div>
            </article>
          ) : (
            <p>Seleção automática pela editoria Capa ou pela notícia mais recente.</p>
          )}
        </div>

        <div className="admin-home-preview__featured">
          <span>Destaques fixados</span>
          <strong>{featured.length}</strong>
          <small>Entram antes do fluxo automático de últimas notícias.</small>
        </div>
      </section>

      <section className="admin-home-list" aria-label="Composição da capa">
        {data.items.map((item) => {
          const draft = drafts[item.id] ?? item.home;
          const changed = JSON.stringify(draft) !== JSON.stringify(item.home);

          return (
            <article className="admin-home-item" key={item.id}>
              <a className="admin-home-item__image" href={'/sistema/noticias/' + item.id}>
                {item.imageUrl ? <img src={item.imageUrl} alt="" /> : <span>Sem imagem</span>}
              </a>

              <div className="admin-home-item__content">
                <span>{formatAdminDate(item.publishedAt)}</span>
                <h2><a href={'/sistema/noticias/' + item.id}>{item.title}</a></h2>
                <small>
                  {item.author}
                  {item.home.slot !== 'automatic' && !item.home.active ? ' • fixação expirada' : ''}
                </small>
              </div>

              <div className="admin-home-item__controls">
                <label>
                  <span>Posição</span>
                  <select
                    value={draft.slot}
                    onChange={(event) => updateDraft(item.id, {
                      slot: event.target.value as HomeLayoutItem['home']['slot'],
                    })}
                  >
                    <option value="automatic">Automático</option>
                    <option value="hero">Manchete principal</option>
                    <option value="featured">Destaque</option>
                  </select>
                </label>

                <label>
                  <span>Ordem</span>
                  <input
                    type="number"
                    min="0"
                    max="99"
                    value={draft.rank}
                    disabled={draft.slot === 'automatic'}
                    onChange={(event) => updateDraft(item.id, {
                      rank: Math.max(0, Math.min(99, Number(event.target.value) || 0)),
                    })}
                  />
                </label>

                <label>
                  <span>Fixar até</span>
                  <input
                    type="datetime-local"
                    value={draft.until}
                    disabled={draft.slot === 'automatic'}
                    onChange={(event) => updateDraft(item.id, { until: event.target.value })}
                  />
                </label>

                <button
                  type="button"
                  disabled={!changed || savingId === item.id}
                  onClick={() => void savePlacement(item)}
                >
                  {savingId === item.id ? 'Salvando…' : 'Salvar'}
                </button>
              </div>
            </article>
          );
        })}
      </section>
    </>
  );
}

function AgendaView({ csrfToken }: { csrfToken: string }) {
  const [data, setData] = useState<AgendaPayload['data']>();
  const [title, setTitle] = useState('');
  const [eventKind, setEventKind] = useState<'coverage' | 'interview' | 'meeting' | 'deadline' | 'event'>('coverage');
  const [start, setStart] = useState('');
  const [end, setEnd] = useState('');
  const [location, setLocation] = useState('');
  const [note, setNote] = useState('');
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<'idle' | 'saved' | 'error'>('idle');
  const [error, setError] = useState(false);

  useEffect(() => {
    void adminFetch<AgendaPayload>('/api/admin/agenda.php')
      .then((payload) => {
        if (!payload.ok || !payload.data) throw new Error('agenda_invalid');
        setData(payload.data);
      })
      .catch(() => setError(true));
  }, []);

  if (error) return <AdminError />;
  if (!data) return <AdminLoading />;

  async function createEvent() {
    if (!title.trim() || !start || saving) return;

    setSaving(true);
    setMessage('idle');

    try {
      const payload = await adminFetch<AgendaPayload>('/api/admin/agenda.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: JSON.stringify({
          action: 'save',
          title,
          eventKind,
          start,
          end,
          location,
          note,
        }),
      });

      if (!payload.ok || !payload.data) throw new Error('agenda_save_invalid');

      setData(payload.data);
      setTitle('');
      setStart('');
      setEnd('');
      setLocation('');
      setNote('');
      setEventKind('coverage');
      setMessage('saved');
    } catch {
      setMessage('error');
    } finally {
      setSaving(false);
    }
  }

  async function deleteEvent(eventId: number) {
    if (!window.confirm('Remover este compromisso da agenda?')) return;

    try {
      const payload = await adminFetch<AgendaPayload>('/api/admin/agenda.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: JSON.stringify({ action: 'delete', eventId }),
      });
      if (!payload.ok || !payload.data) throw new Error('agenda_delete_invalid');
      setData(payload.data);
    } catch {
      setMessage('error');
    }
  }

  const grouped = data.items.reduce<Record<string, AgendaItem[]>>((acc, item) => {
    const date = new Date(item.start);
    const key = Number.isNaN(date.getTime())
      ? item.start.slice(0, 10)
      : new Intl.DateTimeFormat('sv-SE', {
          timeZone: 'America/Sao_Paulo',
          year: 'numeric',
          month: '2-digit',
          day: '2-digit',
        }).format(date);

    (acc[key] ??= []).push(item);
    return acc;
  }, {});

  return (
    <>
      <AdminPageHeader
        eyebrow="Planejamento"
        title="Agenda editorial"
        description="Prazos, publicações agendadas, entrevistas, reuniões e coberturas."
      />

      {message === 'saved' && (
        <div className="admin-save-feedback admin-save-feedback--success" role="status">
          Compromisso adicionado à agenda.
        </div>
      )}
      {message === 'error' && (
        <div className="admin-save-feedback admin-save-feedback--error" role="alert">
          Não foi possível atualizar a agenda.
        </div>
      )}

      <div className="admin-agenda-layout">
        <section className="admin-agenda-timeline">
          {Object.entries(grouped).map(([day, items]) => {
            const date = new Date(day + 'T12:00:00');
            const dayLabel = Number.isNaN(date.getTime())
              ? day
              : new Intl.DateTimeFormat('pt-BR', {
                  weekday: 'long',
                  day: '2-digit',
                  month: 'long',
                  year: 'numeric',
                  timeZone: 'America/Sao_Paulo',
                }).format(date);

            return (
              <section className="admin-agenda-day" key={day}>
                <header>{dayLabel}</header>
                {items.map((item) => (
                  <article className={'admin-agenda-item admin-agenda-item--' + item.kind} key={item.id}>
                    <div className="admin-agenda-item__time">
                      {new Intl.DateTimeFormat('pt-BR', {
                        hour: '2-digit',
                        minute: '2-digit',
                        timeZone: 'America/Sao_Paulo',
                      }).format(new Date(item.start))}
                    </div>
                    <div className="admin-agenda-item__body">
                      <span>
                        {item.kind === 'publication'
                          ? 'Publicação'
                          : item.kind === 'deadline'
                            ? 'Prazo editorial'
                            : 'Cobertura'}
                      </span>
                      <h2>
                        {item.adminUrl ? <a href={item.adminUrl}>{item.title}</a> : item.title}
                      </h2>
                      <p>
                        {item.location && <>{item.location} • </>}
                        {item.assignee}
                      </p>
                      {item.note && <small>{item.note}</small>}
                    </div>
                    {item.kind === 'event' && item.eventId && (
                      <button
                        type="button"
                        className="admin-agenda-item__delete"
                        onClick={() => void deleteEvent(item.eventId!)}
                      >
                        Remover
                      </button>
                    )}
                  </article>
                ))}
              </section>
            );
          })}

          {data.items.length === 0 && (
            <div className="admin-empty-state">Nenhum compromisso editorial agendado.</div>
          )}
        </section>

        <aside className="admin-agenda-create">
          <div className="admin-editor-card">
            <div className="admin-editor-card__head">
              <span>Redação</span>
              <strong>Novo compromisso</strong>
            </div>
            <div className="admin-editor-card__body admin-editor-card__body--fields">
              <label className="admin-editor-field">
                <span>Título</span>
                <input value={title} onChange={(event) => setTitle(event.target.value)} />
              </label>

              <label className="admin-editor-field">
                <span>Tipo</span>
                <select value={eventKind} onChange={(event) => setEventKind(event.target.value as typeof eventKind)}>
                  <option value="coverage">Cobertura</option>
                  <option value="interview">Entrevista</option>
                  <option value="meeting">Reunião</option>
                  <option value="event">Evento</option>
                  <option value="deadline">Prazo</option>
                </select>
              </label>

              <label className="admin-editor-field">
                <span>Início</span>
                <input type="datetime-local" value={start} onChange={(event) => setStart(event.target.value)} />
              </label>

              <label className="admin-editor-field">
                <span>Fim</span>
                <input type="datetime-local" value={end} onChange={(event) => setEnd(event.target.value)} />
              </label>

              <label className="admin-editor-field">
                <span>Local</span>
                <input value={location} onChange={(event) => setLocation(event.target.value)} />
              </label>

              <label className="admin-editor-field">
                <span>Observações</span>
                <textarea rows={5} value={note} onChange={(event) => setNote(event.target.value)} />
              </label>

              <button
                type="button"
                className="admin-button--primary admin-agenda-create__button"
                disabled={!title.trim() || !start || saving}
                onClick={() => void createEvent()}
              >
                {saving ? 'Salvando…' : 'Adicionar à agenda'}
              </button>
            </div>
          </div>
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
  }, [page, query, status]);

  if (error) return <AdminError />;
  if (!data) return <AdminLoading />;

  const canCreateDraft = user.permissions.editPosts;

  function canManageTrash(post: AdminPost) {
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
  const [editorialData, setEditorialData] = useState<EditorialWorkflowPayload['data']>();
  const [editorial, setEditorial] = useState<EditorialWorkflow>();
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
  const [sourcePickerOpen, setSourcePickerOpen] = useState(false);
  const [sourceDirectory, setSourceDirectory] = useState<EditorialSourceContact[]>([]);
  const [sourceDirectoryQuery, setSourceDirectoryQuery] = useState('');
  const [sourceDirectoryLoading, setSourceDirectoryLoading] = useState(false);
  const [sourceDirectoryError, setSourceDirectoryError] = useState(false);
  const [imageState, setImageState] = useState<'idle' | 'working' | 'error'>('idle');
  const [saveState, setSaveState] = useState<'idle' | 'saving' | 'saved' | 'error'>('idle');
  const [statusState, setStatusState] = useState<'idle' | 'working' | 'saved' | 'error'>('idle');
  const [error, setError] = useState(false);

  useEffect(() => {
    if (!postId) {
      setError(true);
      return;
    }

    void Promise.all([
      adminFetch<PostDetailPayload>('/api/admin/post.php?id=' + postId),
      adminFetch<EditorialWorkflowPayload>('/api/admin/post-editorial.php?id=' + postId),
    ])
      .then(([postPayload, editorialPayload]) => {
        if (!postPayload.ok || !postPayload.data) throw new Error('post_invalid');
        if (!editorialPayload.ok || !editorialPayload.data) throw new Error('editorial_invalid');

        const post = postPayload.data.post;
        setData(postPayload.data);
        setTitle(post.title);
        setSlug(post.slug);
        setExcerpt(post.excerpt);
        setContent(post.content);
        setCategoryIds(post.categories.map((category) => category.id));
        setSeoTitle(post.seo.title);
        setSeoDescription(post.seo.description);
        setPrimaryCategoryId(post.seo.primaryCategoryId);

        setEditorialData(editorialPayload.data);
        setEditorial(editorialPayload.data.editorial);
      })
      .catch(() => setError(true));
  }, [postId]);

  if (error) return <AdminError />;
  if (!data || !editorialData || !editorial) return <AdminLoading />;

  const post = data.post;
  const selectedSet = new Set(categoryIds);
  const selectedCategories = data.categories.filter((category) => selectedSet.has(category.id));
  const originalCategoryIds = post.categories.map((category) => category.id).sort((a, b) => a - b);
  const normalizedCategoryIds = [...categoryIds].sort((a, b) => a - b);

  const ownsPost = post.author.id === user.id;
  const canEditOthers = user.capabilities.includes('edit_others_posts');
  const canEditPublished = user.capabilities.includes('edit_published_posts');
  const publishedLike = ['publish', 'future', 'private'].includes(post.status);

  const canEdit =
    user.permissions.editPosts
    && (ownsPost || canEditOthers)
    && (!publishedLike || canEditPublished)
    && post.status !== 'trash';

  const postChanged =
    title !== post.title
    || slug !== post.slug
    || excerpt !== post.excerpt
    || content !== post.content
    || seoTitle !== post.seo.title
    || seoDescription !== post.seo.description
    || primaryCategoryId !== post.seo.primaryCategoryId
    || JSON.stringify(normalizedCategoryIds) !== JSON.stringify(originalCategoryIds);

  const editorialChanged =
    JSON.stringify(editorial) !== JSON.stringify(editorialData.editorial);

  const changed = postChanged || editorialChanged;

  const canChangeStatus =
    user.permissions.editPosts
    && (ownsPost || canEditOthers)
    && (!publishedLike || canEditPublished)
    && post.status !== 'trash';

  function patchEditorial(patch: Partial<EditorialWorkflow>) {
    setEditorial((current) => current ? { ...current, ...patch } : current);
    setSaveState('idle');
  }

  function patchChecklist(key: keyof EditorialChecklist, value: boolean) {
    setEditorial((current) => current
      ? {
          ...current,
          checklist: {
            ...current.checklist,
            [key]: value,
          },
        }
      : current
    );
    setSaveState('idle');
  }

  function addSource() {
    setEditorial((current) => current
      ? {
          ...current,
          sources: [
            ...current.sources,
            { name: '', organization: '', contact: '', url: '', note: '' },
          ],
        }
      : current
    );
    setSaveState('idle');
  }

  async function searchSourceDirectory(query = sourceDirectoryQuery) {
    setSourceDirectoryLoading(true);
    setSourceDirectoryError(false);

    try {
      const search = new URLSearchParams();
      if (query.trim()) search.set('q', query.trim());

      const payload = await adminFetch<SourcesPayload>('/api/admin/sources.php?' + search.toString());
      if (!payload.ok || !payload.data) throw new Error('sources_invalid');

      setSourceDirectory(payload.data.items);
    } catch {
      setSourceDirectoryError(true);
    } finally {
      setSourceDirectoryLoading(false);
    }
  }

  async function openSourceDirectory() {
    if (!canEdit) return;
    setSourcePickerOpen(true);

    if (sourceDirectory.length === 0) {
      await searchSourceDirectory('');
    }
  }

  function addDirectorySource(item: EditorialSourceContact) {
    const contact = item.whatsapp || item.phone || item.email;
    const organization = [item.role, item.organization].filter(Boolean).join(' • ');

    setEditorial((current) => current
      ? {
          ...current,
          sources: [
            ...current.sources,
            {
              name: item.name,
              organization,
              contact,
              url: item.url,
              note: item.notes,
            },
          ],
        }
      : current
    );
    setSourcePickerOpen(false);
    setSaveState('idle');
  }

  function updateSource(index: number, patch: Partial<EditorialSource>) {
    setEditorial((current) => current
      ? {
          ...current,
          sources: current.sources.map((source, sourceIndex) =>
            sourceIndex === index ? { ...source, ...patch } : source
          ),
        }
      : current
    );
    setSaveState('idle');
  }

  function removeSource(index: number) {
    setEditorial((current) => current
      ? {
          ...current,
          sources: current.sources.filter((_, sourceIndex) => sourceIndex !== index),
        }
      : current
    );
    setSaveState('idle');
  }

  async function saveEditorialState(): Promise<EditorialWorkflowPayload['data']> {
    const payload = await adminFetch<EditorialWorkflowPayload>('/api/admin/post-editorial.php', {
      method: 'POST',
      headers: { 'X-CSRF-Token': csrfToken },
      body: JSON.stringify({
        postId: post.id,
        stage: editorial.stage,
        priority: editorial.priority,
        deadline: editorial.deadline,
        assigneeId: editorial.assigneeId,
        notes: editorial.notes,
        sources: editorial.sources,
        checklist: editorial.checklist,
        homeSlot: editorial.home.slot,
        homeRank: editorial.home.rank,
        homeUntil: editorial.home.until,
      }),
    });

    if (!payload.ok || !payload.data) {
      throw new Error('editorial_save_invalid_response');
    }

    return payload.data;
  }

  async function refreshEditorialState() {
    const payload = await adminFetch<EditorialWorkflowPayload>(
      '/api/admin/post-editorial.php?id=' + post.id,
    );

    if (payload.ok && payload.data) {
      setEditorialData(payload.data);
      setEditorial(payload.data.editorial);
    }
  }

  async function savePost() {
    if (!canEdit || !changed || saveState === 'saving') return;

    setSaveState('saving');

    try {
      if (postChanged) {
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
      }

      if (editorialChanged) {
        const savedEditorial = await saveEditorialState();
        if (savedEditorial) {
          setEditorialData(savedEditorial);
          setEditorial(savedEditorial.editorial);
        }
      } else if (postChanged) {
        await refreshEditorialState();
      }

      setSaveState('saved');
    } catch {
      setSaveState('error');
    }
  }

  async function changeStatus(action: 'publish' | 'draft' | 'schedule') {
    if (!canChangeStatus || statusState === 'working' || changed) return;
    if ((action === 'publish' || action === 'schedule') && !user.permissions.publishPosts) return;

    if (action === 'publish' || action === 'schedule') {
      const manualPending = Object.values(editorial.checklist).filter((value) => !value).length;
      const automaticPending = Object.values(editorial.automaticChecks).filter((value) => !value).length;
      const pending = manualPending + automaticPending;

      if (
        pending > 0
        && !window.confirm(
          'Ainda existem ' + pending + ' verificações editoriais pendentes. Deseja continuar mesmo assim?',
        )
      ) {
        return;
      }
    }

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
      await refreshEditorialState();
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
      await refreshEditorialState();
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

  const stageLabels: Record<EditorialWorkflow['stage'], string> = {
    idea: 'Ideia',
    reporting: 'Apuração',
    writing: 'Redação',
    review: 'Revisão',
    ready: 'Pronta',
    scheduled: 'Agendada',
    published: 'Publicada',
  };

  const priorityLabels: Record<EditorialWorkflow['priority'], string> = {
    low: 'Baixa',
    normal: 'Normal',
    high: 'Alta',
    urgent: 'Urgente',
  };

  const checklistLabels: Array<[keyof EditorialChecklist, string]> = [
    ['headline', 'Título revisado'],
    ['facts', 'Fatos conferidos'],
    ['names', 'Nomes e cargos conferidos'],
    ['dates', 'Datas e números conferidos'],
    ['sources', 'Fontes identificadas'],
    ['imageRights', 'Crédito e direitos de imagem'],
    ['altText', 'Texto alternativo conferido'],
    ['links', 'Links verificados'],
    ['category', 'Editoria definida'],
    ['seo', 'SEO revisado'],
    ['review', 'Revisão final concluída'],
  ];

  const automaticCheckLabels: Array<[keyof EditorialWorkflow['automaticChecks'], string]> = [
    ['title', 'Título preenchido'],
    ['excerpt', 'Resumo preenchido'],
    ['featuredImage', 'Imagem destacada'],
    ['category', 'Categoria definida'],
    ['seo', 'Metadados de busca'],
  ];

  const checklistDone = Object.values(editorial.checklist).filter(Boolean).length;
  const checklistTotal = Object.keys(editorial.checklist).length;

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
              disabled={publicationActionDisabled || !user.permissions.publishPosts}
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

          <AdminRichEditor
            label="Conteúdo da notícia"
            value={content}
            disabled={!canEdit}
            minHeight={620}
            loadMedia={loadAdminEditorMedia}
            canSave={canEdit && changed && saveState !== 'saving'}
            onSave={() => void savePost()}
            onChange={(html) => {
              setContent(html);
              setSaveState('idle');
            }}
          />

          <section className="admin-editor-card admin-editorial-notebook">
            <div className="admin-editor-card__head">
              <span>Apuração</span>
              <strong>Caderno da matéria</strong>
            </div>

            <div className="admin-editor-card__body admin-editor-card__body--fields">
              <label className="admin-editor-field">
                <span>Anotações privadas</span>
                <textarea
                  rows={8}
                  value={editorial.notes}
                  disabled={!canEdit}
                  placeholder="Perguntas em aberto, dados para conferir, contexto, trechos de entrevista…"
                  onChange={(event) => patchEditorial({ notes: event.target.value })}
                />
              </label>

              <div className="admin-editorial-sources">
                <div className="admin-editorial-sources__head">
                  <div>
                    <span>Fontes consultadas</span>
                    <small>Informação interna da redação. Não aparece na matéria.</small>
                  </div>
                  <div className="admin-editorial-sources__actions">
                    <button type="button" disabled={!canEdit} onClick={() => void openSourceDirectory()}>
                      Adicionar da Central
                    </button>
                    <button type="button" disabled={!canEdit} onClick={addSource}>
                      + Fonte avulsa
                    </button>
                  </div>
                </div>

                {editorial.sources.length === 0 && (
                  <p className="admin-editorial-sources__empty">Nenhuma fonte registrada.</p>
                )}

                {editorial.sources.map((source, index) => (
                  <article className="admin-editorial-source" key={index}>
                    <div className="admin-editorial-source__grid">
                      <label>
                        <span>Nome</span>
                        <input
                          value={source.name}
                          disabled={!canEdit}
                          onChange={(event) => updateSource(index, { name: event.target.value })}
                        />
                      </label>
                      <label>
                        <span>Organização / função</span>
                        <input
                          value={source.organization}
                          disabled={!canEdit}
                          onChange={(event) => updateSource(index, { organization: event.target.value })}
                        />
                      </label>
                      <label>
                        <span>Contato</span>
                        <input
                          value={source.contact}
                          disabled={!canEdit}
                          onChange={(event) => updateSource(index, { contact: event.target.value })}
                        />
                      </label>
                      <label>
                        <span>Link / documento</span>
                        <input
                          type="url"
                          value={source.url}
                          disabled={!canEdit}
                          onChange={(event) => updateSource(index, { url: event.target.value })}
                        />
                      </label>
                    </div>
                    <label className="admin-editorial-source__note">
                      <span>Observação</span>
                      <textarea
                        rows={2}
                        value={source.note}
                        disabled={!canEdit}
                        onChange={(event) => updateSource(index, { note: event.target.value })}
                      />
                    </label>
                    <button
                      type="button"
                      className="admin-editorial-source__remove"
                      disabled={!canEdit}
                      onClick={() => removeSource(index)}
                    >
                      Remover fonte
                    </button>
                  </article>
                ))}
              </div>
            </div>
          </section>

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
              <div><dt>Autor</dt><dd>{post.author.name}</dd></div>
              <div><dt>Publicação</dt><dd>{formatAdminDate(post.publishedAt)}</dd></div>
              <div><dt>Atualização</dt><dd>{formatAdminDate(post.modifiedAt)}</dd></div>
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
              <span>Redação</span>
              <strong>Workflow editorial</strong>
            </div>

            <div className="admin-editorial-workflow">
              <label>
                <span>Etapa</span>
                <select
                  value={editorial.stage}
                  disabled={!canEdit}
                  onChange={(event) => patchEditorial({
                    stage: event.target.value as EditorialWorkflow['stage'],
                  })}
                >
                  {Object.entries(stageLabels).map(([value, label]) => (
                    <option value={value} key={value}>{label}</option>
                  ))}
                </select>
              </label>

              <label>
                <span>Prioridade</span>
                <select
                  value={editorial.priority}
                  disabled={!canEdit}
                  onChange={(event) => patchEditorial({
                    priority: event.target.value as EditorialWorkflow['priority'],
                  })}
                >
                  {Object.entries(priorityLabels).map(([value, label]) => (
                    <option value={value} key={value}>{label}</option>
                  ))}
                </select>
              </label>

              <label>
                <span>Responsável</span>
                <select
                  value={editorial.assigneeId || ''}
                  disabled={!canEdit}
                  onChange={(event) => patchEditorial({
                    assigneeId: Number(event.target.value) || 0,
                  })}
                >
                  <option value="">Autor da matéria</option>
                  {editorialData.assignees.map((assignee) => (
                    <option value={assignee.id} key={assignee.id}>{assignee.name}</option>
                  ))}
                </select>
              </label>

              <label>
                <span>Prazo interno</span>
                <input
                  type="datetime-local"
                  value={editorial.deadline}
                  disabled={!canEdit}
                  onChange={(event) => patchEditorial({ deadline: event.target.value })}
                />
              </label>
            </div>
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
                    <option value={category.id} key={category.id}>{category.name}</option>
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

          <section className="admin-editor-card">
            <div className="admin-editor-card__head">
              <span>Antes de publicar</span>
              <strong>{checklistDone}/{checklistTotal} conferidos</strong>
            </div>

            <div className="admin-editorial-checks admin-editorial-checks--automatic">
              {automaticCheckLabels.map(([key, label]) => (
                <div className={editorial.automaticChecks[key] ? 'done' : ''} key={key}>
                  <span>{editorial.automaticChecks[key] ? '✓' : '○'}</span>
                  <strong>{label}</strong>
                </div>
              ))}
            </div>

            <div className="admin-editorial-checks">
              {checklistLabels.map(([key, label]) => (
                <label key={key}>
                  <input
                    type="checkbox"
                    checked={editorial.checklist[key]}
                    disabled={!canEdit}
                    onChange={(event) => patchChecklist(key, event.target.checked)}
                  />
                  <span>{label}</span>
                </label>
              ))}
            </div>
          </section>

          {user.permissions.publishPosts && user.capabilities.includes('edit_others_posts') && (
            <section className="admin-editor-card">
              <div className="admin-editor-card__head">
                <span>Página inicial</span>
                <strong>Capa do site</strong>
              </div>

              <div className="admin-editorial-workflow">
                <label>
                  <span>Posição</span>
                  <select
                    value={editorial.home.slot}
                    disabled={!canEdit}
                    onChange={(event) => patchEditorial({
                      home: {
                        ...editorial.home,
                        slot: event.target.value as EditorialWorkflow['home']['slot'],
                      },
                    })}
                  >
                    <option value="automatic">Automática</option>
                    <option value="hero">Manchete principal</option>
                    <option value="featured">Destaque</option>
                  </select>
                </label>

                {editorial.home.slot !== 'automatic' && (
                  <>
                    <label>
                      <span>Ordem</span>
                      <input
                        type="number"
                        min="0"
                        max="99"
                        value={editorial.home.rank}
                        disabled={!canEdit}
                        onChange={(event) => patchEditorial({
                          home: {
                            ...editorial.home,
                            rank: Math.max(0, Math.min(99, Number(event.target.value) || 0)),
                          },
                        })}
                      />
                    </label>
                    <label>
                      <span>Fixar até</span>
                      <input
                        type="datetime-local"
                        value={editorial.home.until}
                        disabled={!canEdit}
                        onChange={(event) => patchEditorial({
                          home: {
                            ...editorial.home,
                            until: event.target.value,
                          },
                        })}
                      />
                    </label>
                  </>
                )}

                <a className="admin-editorial-home-link" href="/sistema/capa">
                  Organizar toda a capa →
                </a>
              </div>
            </section>
          )}
        </aside>
      </div>

      {sourcePickerOpen && (
        <div className="admin-source-picker" role="dialog" aria-modal="true" aria-label="Adicionar fonte da Central">
          <div className="admin-source-picker__panel">
            <header>
              <div>
                <span>Central de Fontes</span>
                <h2>Adicionar fonte à apuração</h2>
              </div>
              <button type="button" onClick={() => setSourcePickerOpen(false)} aria-label="Fechar">×</button>
            </header>

            <form
              className="admin-source-picker__search"
              onSubmit={(event) => {
                event.preventDefault();
                void searchSourceDirectory();
              }}
            >
              <input
                type="search"
                value={sourceDirectoryQuery}
                placeholder="Buscar nome, órgão, cidade ou assunto"
                onChange={(event) => setSourceDirectoryQuery(event.target.value)}
              />
              <button type="submit" disabled={sourceDirectoryLoading}>
                {sourceDirectoryLoading ? 'Buscando…' : 'Buscar'}
              </button>
            </form>

            {sourceDirectoryError && (
              <div className="admin-source-picker__error">
                Não foi possível carregar a Central de Fontes.
              </div>
            )}

            <div className="admin-source-picker__grid">
              {sourceDirectory.map((item) => (
                <button type="button" key={item.id} onClick={() => addDirectorySource(item)}>
                  <span className="admin-source-picker__avatar">{initials(item.name)}</span>
                  <span className="admin-source-picker__body">
                    <strong>{item.name}</strong>
                    <small>{[item.role, item.organization, item.city].filter(Boolean).join(' • ') || 'Sem vínculo informado'}</small>
                    {item.topics.length > 0 && <em>{item.topics.slice(0, 4).join(' · ')}</em>}
                  </span>
                </button>
              ))}

              {!sourceDirectoryLoading && sourceDirectory.length === 0 && (
                <p>Nenhuma fonte encontrada.</p>
              )}
            </div>

            <footer>
              <a href="/sistema/fontes">Gerenciar Central de Fontes →</a>
            </footer>
          </div>
        </div>
      )}

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

function PagesView() {
  const params = useMemo(() => new URLSearchParams(window.location.search), []);
  const status = params.get('status') ?? 'all';
  const query = params.get('q') ?? '';
  const page = Math.max(1, Number.parseInt(params.get('page') ?? '1', 10) || 1);

  const [data, setData] = useState<PagesPayload['data']>();
  const [error, setError] = useState(false);

  useEffect(() => {
    const search = new URLSearchParams({ status, page: String(page) });
    if (query) search.set('q', query);

    void adminFetch<PagesPayload>('/api/admin/pages.php?' + search.toString())
      .then((payload) => {
        if (!payload.ok || !payload.data) throw new Error('pages_invalid');
        setData(payload.data);
      })
      .catch(() => setError(true));
  }, [page, query, status]);

  if (error) return <AdminError />;
  if (!data) return <AdminLoading />;

  return (
    <>
      <AdminPageHeader
        eyebrow="Conteúdo institucional"
        title="Páginas"
        description="Edite as páginas fixas usadas pelo portal."
      />

      <form className="admin-toolbar" method="get" action="/sistema/paginas">
        <div className="admin-filter-tabs" aria-label="Filtrar páginas por status">
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
              href={'/sistema/paginas?status=' + value}
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
            placeholder="Buscar páginas"
            aria-label="Buscar páginas"
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
              <th>Status</th>
              <th>Atualização</th>
            </tr>
          </thead>
          <tbody>
            {data.items.map((item) => (
              <tr key={item.id}>
                <td className="admin-table__primary">
                  <strong>
                    <a href={'/sistema/paginas/' + item.id}>{item.title}</a>
                  </strong>
                  <div className="admin-row-actions">
                    <a href={'/sistema/paginas/' + item.id}>Editar</a>
                    {item.publicUrl && item.status === 'publish' && (
                      <a href={item.publicUrl} target="_blank" rel="noopener noreferrer">Ver ↗</a>
                    )}
                  </div>
                </td>
                <td>{typeof item.author === 'string' ? item.author : item.author.name}</td>
                <td>
                  <span className={'admin-status admin-status--' + item.status}>
                    {statusLabel(item.status)}
                  </span>
                </td>
                <td>{formatAdminDate(item.modifiedAt)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <AdminPagination
        page={data.pagination.page}
        totalPages={data.pagination.totalPages}
        base="/sistema/paginas"
        params={{ status, q: query }}
      />
    </>
  );
}

function PageEditorView({
  user,
  csrfToken,
}: {
  user: AdminUser;
  csrfToken: string;
}) {
  const match = window.location.pathname.match(/^\/sistema\/paginas\/(\d+)\/?$/);
  const pageId = match ? Number.parseInt(match[1], 10) : 0;

  const [data, setData] = useState<PageDetailPayload['data']>();
  const [title, setTitle] = useState('');
  const [slug, setSlug] = useState('');
  const [excerpt, setExcerpt] = useState('');
  const [content, setContent] = useState('');
  const [seoTitle, setSeoTitle] = useState('');
  const [seoDescription, setSeoDescription] = useState('');
  const [saveState, setSaveState] = useState<'idle' | 'saving' | 'saved' | 'error'>('idle');
  const [error, setError] = useState(false);

  useEffect(() => {
    if (!pageId) {
      setError(true);
      return;
    }

    void adminFetch<PageDetailPayload>('/api/admin/page-item.php?id=' + pageId)
      .then((payload) => {
        if (!payload.ok || !payload.data) throw new Error('page_invalid');

        const item = payload.data.page;
        setData(payload.data);
        setTitle(item.title);
        setSlug(item.slug);
        setExcerpt(item.excerpt);
        setContent(item.content);
        setSeoTitle(item.seo.title);
        setSeoDescription(item.seo.description);
      })
      .catch(() => setError(true));
  }, [pageId]);

  if (error) return <AdminError />;
  if (!data) return <AdminLoading />;

  const item = data.page;
  const ownsPage = item.author.id === user.id;
  const canEditOthers = user.capabilities.includes('edit_others_pages');
  const canEditPublished = user.capabilities.includes('edit_published_pages');
  const publishedLike = ['publish', 'future', 'private'].includes(item.status);

  const canEdit =
    user.permissions.editPages
    && (ownsPage || canEditOthers)
    && (!publishedLike || canEditPublished)
    && item.status !== 'trash';

  const changed =
    title !== item.title
    || slug !== item.slug
    || excerpt !== item.excerpt
    || content !== item.content
    || seoTitle !== item.seo.title
    || seoDescription !== item.seo.description;

  async function savePage() {
    if (!canEdit || !changed || saveState === 'saving') return;

    setSaveState('saving');

    try {
      const payload = await adminFetch<{
        ok: boolean;
        data?: {
          page: {
            id: number;
            title: string;
            slug: string;
            slugLocked: boolean;
            excerpt: string;
            content: string;
            status: string;
            seo: {
              title: string;
              description: string;
            };
            modifiedAt: string;
            publicUrl: string | null;
          };
        };
      }>('/api/admin/page-save.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: JSON.stringify({
          pageId: item.id,
          title,
          slug,
          excerpt,
          content,
          seoTitle,
          seoDescription,
        }),
      });

      if (!payload.ok || !payload.data) {
        throw new Error('page_save_invalid_response');
      }

      const saved = payload.data.page;
      setData((current) => current
        ? {
            ...current,
            page: {
              ...current.page,
              ...saved,
              author: current.page.author,
              publishedAt: current.page.publishedAt,
            },
          }
        : current
      );
      setTitle(saved.title);
      setSlug(saved.slug);
      setExcerpt(saved.excerpt);
      setContent(saved.content);
      setSeoTitle(saved.seo.title);
      setSeoDescription(saved.seo.description);
      setSaveState('saved');
    } catch {
      setSaveState('error');
    }
  }

  return (
    <>
      <header className="admin-editor-header">
        <div>
          <a href="/sistema/paginas" className="admin-editor-header__back">← Páginas</a>
          <div className="admin-editor-header__title">
            <span className={'admin-status admin-status--' + item.status}>
              {statusLabel(item.status)}
            </span>
            <h1>Editar página</h1>
          </div>
          <p>Última alteração {formatAdminDate(item.modifiedAt)}</p>
        </div>

        <div className="admin-editor-header__actions">
          {item.publicUrl && item.status === 'publish' && (
            <a href={item.publicUrl} target="_blank" rel="noopener noreferrer">Ver no site ↗</a>
          )}
          <button
            type="button"
            className="admin-button--primary"
            disabled={!canEdit || !changed || saveState === 'saving'}
            onClick={() => void savePage()}
          >
            {saveState === 'saving' ? 'Salvando…' : 'Salvar'}
          </button>
        </div>
      </header>

      {saveState === 'saved' && (
        <div className="admin-save-feedback admin-save-feedback--success" role="status">
          Página atualizada.
        </div>
      )}

      {saveState === 'error' && (
        <div className="admin-save-feedback admin-save-feedback--error" role="alert">
          Não foi possível salvar a página. Tente novamente.
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
            <span>Endereço</span>
            <input
              value={slug}
              readOnly={!canEdit || item.slugLocked}
              onChange={(event) => {
                setSlug(event.target.value);
                setSaveState('idle');
              }}
            />
            {item.slugLocked && (
              <small className="admin-field-help">
                Este endereço é usado por uma rota fixa do portal.
              </small>
            )}
          </label>

          <label className="admin-editor-field">
            <span>Resumo</span>
            <textarea
              value={excerpt}
              readOnly={!canEdit}
              rows={4}
              onChange={(event) => {
                setExcerpt(event.target.value);
                setSaveState('idle');
              }}
            />
          </label>

          <AdminRichEditor
            label="Conteúdo da página"
            value={content}
            disabled={!canEdit}
            minHeight={520}
            loadMedia={loadAdminEditorMedia}
            canSave={canEdit && changed && saveState !== 'saving'}
            onSave={() => void savePage()}
            onChange={(html) => {
              setContent(html);
              setSaveState('idle');
            }}
          />

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
              <span>Página</span>
              <strong>{statusLabel(item.status)}</strong>
            </div>

            <dl className="admin-editor-meta">
              <div><dt>Autor</dt><dd>{item.author.name}</dd></div>
              <div><dt>Publicação</dt><dd>{formatAdminDate(item.publishedAt)}</dd></div>
              <div><dt>Atualização</dt><dd>{formatAdminDate(item.modifiedAt)}</dd></div>
            </dl>
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
  }, []);

  if (error) return <AdminError />;
  if (!data) return <AdminLoading />;

  const canCreate = true;

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
  }, [categoryId]);

  if (error) return <AdminError />;
  if (!data) return <AdminLoading />;

  const category = data.category;
  const canEdit = true;

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

function CommentsView({ csrfToken }: { csrfToken: string }) {
  const params = useMemo(() => new URLSearchParams(window.location.search), []);
  const status = params.get('status') ?? 'all';
  const query = params.get('q') ?? '';
  const page = Math.max(1, Number.parseInt(params.get('page') ?? '1', 10) || 1);

  const [data, setData] = useState<CommentsPayload['data']>();
  const [actionId, setActionId] = useState(0);
  const [actionError, setActionError] = useState(false);
  const [error, setError] = useState(false);

  useEffect(() => {
    const search = new URLSearchParams({ status, page: String(page) });
    if (query) search.set('q', query);

    void adminFetch<CommentsPayload>('/api/admin/comments.php?' + search.toString())
      .then((payload) => {
        if (!payload.ok || !payload.data) throw new Error('comments_invalid');
        setData(payload.data);
      })
      .catch(() => setError(true));
  }, [page, query, status]);

  if (error) return <AdminError />;
  if (!data) return <AdminLoading />;

  async function changeStatus(
    commentId: number,
    action: 'approve' | 'pending' | 'spam' | 'trash' | 'restore',
  ) {
    if (actionId > 0) return;

    setActionId(commentId);
    setActionError(false);

    try {
      const payload = await adminFetch<{
        ok: boolean;
        data?: {
          comment: {
            id: number;
            status: 'pending' | 'approved' | 'spam' | 'trash';
          };
        };
      }>('/api/admin/comment-status.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: JSON.stringify({ commentId, action }),
      });

      if (!payload.ok || !payload.data) {
        throw new Error('comment_status_invalid_response');
      }

      const nextStatus = payload.data.comment.status;

      setData((current) => {
        if (!current) return current;

        const previous = current.items.find((item) => item.id === commentId);
        if (!previous) return current;

        const counts = { ...current.counts };
        if (previous.status in counts) {
          counts[previous.status as keyof typeof counts] = Math.max(
            0,
            counts[previous.status as keyof typeof counts] - 1,
          );
        }
        if (nextStatus in counts) {
          counts[nextStatus as keyof typeof counts] += 1;
        }

        const keep = current.status === 'all'
          ? nextStatus === 'pending' || nextStatus === 'approved'
          : current.status === nextStatus;

        return {
          ...current,
          counts,
          items: keep
            ? current.items.map((item) => item.id === commentId
                ? { ...item, status: nextStatus }
                : item)
            : current.items.filter((item) => item.id !== commentId),
          pagination: keep
            ? current.pagination
            : {
                ...current.pagination,
                total: Math.max(0, current.pagination.total - 1),
              },
        };
      });
    } catch {
      setActionError(true);
    } finally {
      setActionId(0);
    }
  }

  const tabs: Array<[string, string, number | null]> = [
    ['all', 'Todos', null],
    ['pending', 'Pendentes', data.counts.pending],
    ['approved', 'Aprovados', data.counts.approved],
    ['spam', 'Spam', data.counts.spam],
    ['trash', 'Lixeira', data.counts.trash],
  ];

  return (
    <>
      <AdminPageHeader
        eyebrow="Comunidade"
        title="Comentários"
        description="Modere as conversas publicadas nas notícias."
      />

      {actionError && (
        <div className="admin-save-feedback admin-save-feedback--error" role="alert">
          Não foi possível atualizar o comentário. Tente novamente.
        </div>
      )}

      <form className="admin-toolbar" method="get" action="/sistema/comentarios">
        <div className="admin-filter-tabs" aria-label="Filtrar comentários por status">
          {tabs.map(([value, label, count]) => (
            <a
              key={value}
              className={status === value ? 'active' : ''}
              href={'/sistema/comentarios?status=' + value}
            >
              {label}{count === null ? '' : ' (' + count + ')'}
            </a>
          ))}
        </div>

        <div className="admin-search">
          <input
            type="search"
            name="q"
            defaultValue={query}
            placeholder="Buscar comentários"
            aria-label="Buscar comentários"
          />
          {status !== 'all' && <input type="hidden" name="status" value={status} />}
          <button type="submit">Buscar</button>
        </div>
      </form>

      <section className="admin-comments-list" aria-label="Comentários">
        {data.items.length === 0 && (
          <div className="admin-empty-state">
            Nenhum comentário encontrado.
          </div>
        )}

        {data.items.map((comment) => (
          <article className="admin-comment-card" key={comment.id}>
            <div className="admin-comment-card__avatar" aria-hidden="true">
              {initials(comment.author || 'Visitante')}
            </div>

            <div className="admin-comment-card__content">
              <header>
                <div>
                  <strong>{comment.author || 'Visitante'}</strong>
                  {comment.email && <a href={'mailto:' + comment.email}>{comment.email}</a>}
                </div>
                <span className={'admin-comment-status admin-comment-status--' + comment.status}>
                  {commentStatusLabel(comment.status)}
                </span>
              </header>

              <p>{comment.content}</p>

              <div className="admin-comment-card__meta">
                <span>{formatAdminDate(comment.createdAt)}</span>
                <span>em</span>
                <a href={'/sistema/noticias/' + comment.postId}>{comment.postTitle}</a>
                {comment.postUrl && (
                  <a href={comment.postUrl} target="_blank" rel="noopener noreferrer">Ver notícia ↗</a>
                )}
              </div>

              <div className="admin-comment-actions">
                {comment.status !== 'approved' && comment.status !== 'trash' && (
                  <button
                    type="button"
                    disabled={actionId === comment.id}
                    onClick={() => void changeStatus(comment.id, 'approve')}
                  >
                    Aprovar
                  </button>
                )}

                {comment.status === 'approved' && (
                  <button
                    type="button"
                    disabled={actionId === comment.id}
                    onClick={() => void changeStatus(comment.id, 'pending')}
                  >
                    Marcar como pendente
                  </button>
                )}

                {comment.status !== 'spam' && comment.status !== 'trash' && (
                  <button
                    type="button"
                    disabled={actionId === comment.id}
                    onClick={() => void changeStatus(comment.id, 'spam')}
                  >
                    Spam
                  </button>
                )}

                {comment.status === 'spam' && (
                  <button
                    type="button"
                    disabled={actionId === comment.id}
                    onClick={() => void changeStatus(comment.id, 'pending')}
                  >
                    Não é spam
                  </button>
                )}

                {comment.status !== 'trash' ? (
                  <button
                    type="button"
                    className="danger"
                    disabled={actionId === comment.id}
                    onClick={() => void changeStatus(comment.id, 'trash')}
                  >
                    Lixeira
                  </button>
                ) : (
                  <button
                    type="button"
                    disabled={actionId === comment.id}
                    onClick={() => void changeStatus(comment.id, 'restore')}
                  >
                    Restaurar
                  </button>
                )}
              </div>
            </div>
          </article>
        ))}
      </section>

      <AdminPagination
        page={data.pagination.page}
        totalPages={data.pagination.totalPages}
        base="/sistema/comentarios"
        params={{ status, q: query }}
      />
    </>
  );
}

function SourcesView({ csrfToken }: { csrfToken: string }) {
  const params = useMemo(() => new URLSearchParams(window.location.search), []);
  const query = params.get('q') ?? '';

  const [data, setData] = useState<SourcesPayload['data']>();
  const [editingId, setEditingId] = useState(0);
  const [name, setName] = useState('');
  const [organization, setOrganization] = useState('');
  const [role, setRole] = useState('');
  const [phone, setPhone] = useState('');
  const [whatsapp, setWhatsapp] = useState('');
  const [email, setEmail] = useState('');
  const [city, setCity] = useState('');
  const [topics, setTopics] = useState('');
  const [url, setUrl] = useState('');
  const [notes, setNotes] = useState('');
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<'idle' | 'saved' | 'error'>('idle');
  const [error, setError] = useState(false);

  useEffect(() => {
    const search = new URLSearchParams();
    if (query) search.set('q', query);

    void adminFetch<SourcesPayload>('/api/admin/sources.php?' + search.toString())
      .then((payload) => {
        if (!payload.ok || !payload.data) throw new Error('sources_invalid');
        setData(payload.data);
      })
      .catch(() => setError(true));
  }, [query]);

  if (error) return <AdminError />;
  if (!data) return <AdminLoading />;

  function resetForm() {
    setEditingId(0);
    setName('');
    setOrganization('');
    setRole('');
    setPhone('');
    setWhatsapp('');
    setEmail('');
    setCity('');
    setTopics('');
    setUrl('');
    setNotes('');
    setMessage('idle');
  }

  function editSource(item: EditorialSourceContact) {
    setEditingId(item.id);
    setName(item.name);
    setOrganization(item.organization);
    setRole(item.role);
    setPhone(item.phone);
    setWhatsapp(item.whatsapp);
    setEmail(item.email);
    setCity(item.city);
    setTopics(item.topics.join(', '));
    setUrl(item.url);
    setNotes(item.notes);
    setMessage('idle');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  async function mutateSource(action: 'save' | 'trash') {
    if (saving) return;
    if (action === 'save' && !name.trim()) return;

    setSaving(true);
    setMessage('idle');

    try {
      const payload = await adminFetch<SourcesPayload>('/api/admin/sources.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: JSON.stringify(
          action === 'save'
            ? {
                action,
                sourceId: editingId,
                name,
                organization,
                role,
                phone,
                whatsapp,
                email,
                city,
                topics: topics
                  .split(/[,;\n]+/)
                  .map((value) => value.trim())
                  .filter(Boolean),
                url,
                notes,
              }
            : {
                action,
                sourceId: editingId,
              },
        ),
      });

      if (!payload.ok || !payload.data) throw new Error('source_mutation_invalid');

      setData((current) => current
        ? {
            ...current,
            items: payload.data!.items,
          }
        : payload.data
      );
      resetForm();
      setMessage('saved');
    } catch {
      setMessage('error');
    } finally {
      setSaving(false);
    }
  }

  return (
    <>
      <AdminPageHeader
        eyebrow="Apuração"
        title="Fontes"
        description="Contatos, especialistas, órgãos e pessoas consultadas pela redação."
      />

      {message === 'saved' && (
        <div className="admin-save-feedback admin-save-feedback--success" role="status">
          Central de Fontes atualizada.
        </div>
      )}
      {message === 'error' && (
        <div className="admin-save-feedback admin-save-feedback--error" role="alert">
          Não foi possível salvar a fonte. Verifique os dados e tente novamente.
        </div>
      )}

      <div className="admin-sources-layout">
        <aside className="admin-source-editor">
          <section className="admin-editor-card">
            <div className="admin-editor-card__head">
              <span>{editingId ? 'Editar' : 'Nova'}</span>
              <strong>{editingId ? 'Fonte #' + editingId : 'Cadastrar fonte'}</strong>
            </div>

            <div className="admin-editor-card__body admin-editor-card__body--fields">
              <label className="admin-editor-field">
                <span>Nome</span>
                <input value={name} onChange={(event) => setName(event.target.value)} />
              </label>

              <label className="admin-editor-field">
                <span>Organização</span>
                <input value={organization} onChange={(event) => setOrganization(event.target.value)} />
              </label>

              <label className="admin-editor-field">
                <span>Cargo / função</span>
                <input value={role} onChange={(event) => setRole(event.target.value)} />
              </label>

              <div className="admin-source-editor__row">
                <label className="admin-editor-field">
                  <span>Telefone</span>
                  <input value={phone} onChange={(event) => setPhone(event.target.value)} />
                </label>
                <label className="admin-editor-field">
                  <span>WhatsApp</span>
                  <input value={whatsapp} onChange={(event) => setWhatsapp(event.target.value)} />
                </label>
              </div>

              <label className="admin-editor-field">
                <span>E-mail</span>
                <input type="email" value={email} onChange={(event) => setEmail(event.target.value)} />
              </label>

              <label className="admin-editor-field">
                <span>Cidade</span>
                <input value={city} onChange={(event) => setCity(event.target.value)} />
              </label>

              <label className="admin-editor-field">
                <span>Assuntos</span>
                <input
                  value={topics}
                  placeholder="política, economia, saúde…"
                  onChange={(event) => setTopics(event.target.value)}
                />
              </label>

              <label className="admin-editor-field">
                <span>Site / perfil oficial</span>
                <input type="url" value={url} onChange={(event) => setUrl(event.target.value)} />
              </label>

              <label className="admin-editor-field">
                <span>Observações privadas</span>
                <textarea rows={6} value={notes} onChange={(event) => setNotes(event.target.value)} />
              </label>

              <div className="admin-source-editor__actions">
                <button
                  type="button"
                  className="admin-button--primary"
                  disabled={!name.trim() || saving}
                  onClick={() => void mutateSource('save')}
                >
                  {saving ? 'Salvando…' : editingId ? 'Salvar fonte' : 'Cadastrar fonte'}
                </button>

                {editingId > 0 && (
                  <>
                    <button type="button" disabled={saving} onClick={resetForm}>
                      Cancelar
                    </button>
                    <button
                      type="button"
                      className="danger"
                      disabled={saving}
                      onClick={() => {
                        if (window.confirm('Arquivar esta fonte?')) {
                          void mutateSource('trash');
                        }
                      }}
                    >
                      Arquivar
                    </button>
                  </>
                )}
              </div>
            </div>
          </section>
        </aside>

        <section className="admin-sources-list">
          <form className="admin-toolbar admin-toolbar--sources" method="get" action="/sistema/fontes">
            <div className="admin-search">
              <input
                type="search"
                name="q"
                defaultValue={query}
                placeholder="Buscar nome, órgão, cidade ou assunto"
                aria-label="Buscar fontes"
              />
              <button type="submit">Buscar</button>
            </div>
          </form>

          <div className="admin-source-cards">
            {data.items.map((item) => (
              <article className="admin-source-card" key={item.id}>
                <button type="button" className="admin-source-card__main" onClick={() => editSource(item)}>
                  <div className="admin-source-card__avatar">{initials(item.name)}</div>
                  <div>
                    <h2>{item.name}</h2>
                    <p>
                      {[item.role, item.organization, item.city].filter(Boolean).join(' • ') || 'Sem vínculo informado'}
                    </p>
                    {item.topics.length > 0 && (
                      <div className="admin-source-card__topics">
                        {item.topics.slice(0, 5).map((topic) => <span key={topic}>{topic}</span>)}
                      </div>
                    )}
                  </div>
                </button>

                <div className="admin-source-card__contacts">
                  {item.whatsapp && (
                    <a href={'https://wa.me/' + item.whatsapp.replace(/\D+/g, '')} target="_blank" rel="noopener noreferrer">
                      WhatsApp
                    </a>
                  )}
                  {item.phone && <a href={'tel:' + item.phone.replace(/[^+\d]/g, '')}>Telefone</a>}
                  {item.email && <a href={'mailto:' + item.email}>E-mail</a>}
                  {item.url && <a href={item.url} target="_blank" rel="noopener noreferrer">Site ↗</a>}
                </div>
              </article>
            ))}

            {data.items.length === 0 && (
              <div className="admin-empty-state">Nenhuma fonte encontrada.</div>
            )}
          </div>
        </section>
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

function UserEditorView({
  user,
  csrfToken,
}: {
  user: AdminUser;
  csrfToken: string;
}) {
  const match = window.location.pathname.match(/^\/sistema\/usuarios\/(\d+)\/?$/);
  const userId = match ? Number.parseInt(match[1], 10) : 0;

  const [data, setData] = useState<UserDetailPayload['data']>();
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
  }, [userId]);

  if (error) return <AdminError />;
  if (!data) return <AdminLoading />;

  const profileCanSave = user.permissions.editUsers;
  const roleCanSave = user.permissions.editUsers && data.canChangeRole;
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
  const query = params.get('q') ?? '';

  const [data, setData] = useState<MediaPayload['data']>();
  const [uploadState, setUploadState] = useState<'idle' | 'uploading' | 'saved' | 'error'>('idle');
  const [error, setError] = useState(false);

  useEffect(() => {
    const search = new URLSearchParams({
      page: String(page),
      per_page: '36',
    });
    if (query) search.set('q', query);

    void adminFetch<MediaPayload>('/api/admin/media.php?' + search.toString())
      .then((payload) => {
        if (!payload.ok || !payload.data) throw new Error('media_invalid');
        setData(payload.data);
      })
      .catch(() => setError(true));
  }, [page, query]);

  if (error) return <AdminError />;
  if (!data) return <AdminLoading />;

  const canUpload = true;

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

      <form className="admin-toolbar admin-toolbar--media" method="get" action="/sistema/midia">
        <div className="admin-search">
          <input
            type="search"
            name="q"
            defaultValue={query}
            placeholder="Buscar por título, arquivo ou texto alternativo"
            aria-label="Buscar mídia"
          />
          <button type="submit">Buscar</button>
        </div>
      </form>

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
        params={{ q: query }}
      />
    </>
  );
}

function MediaItemView({ csrfToken }: { csrfToken: string }) {
  const match = window.location.pathname.match(/^\/sistema\/midia\/(\d+)\/?$/);
  const mediaId = match ? Number.parseInt(match[1], 10) : 0;

  const [data, setData] = useState<MediaDetailPayload['data']>();
  const [title, setTitle] = useState('');
  const [alt, setAlt] = useState('');
  const [caption, setCaption] = useState('');
  const [description, setDescription] = useState('');
  const [saveState, setSaveState] = useState<'idle' | 'saving' | 'saved' | 'error'>('idle');
  const [error, setError] = useState(false);

  useEffect(() => {
    if (!mediaId) {
      setError(true);
      return;
    }

    void adminFetch<MediaDetailPayload>('/api/admin/media-item.php?id=' + mediaId)
      .then((payload) => {
        if (!payload.ok || !payload.data) throw new Error('media_item_invalid');

        setData(payload.data);
        setTitle(payload.data.media.title);
        setAlt(payload.data.media.alt);
        setCaption(payload.data.media.caption);
        setDescription(payload.data.media.description);
      })
      .catch(() => setError(true));
  }, [mediaId]);

  if (error) return <AdminError />;
  if (!data) return <AdminLoading />;

  const media = data.media;
  const canEdit = true;
  const changed =
    title !== media.title
    || alt !== media.alt
    || caption !== media.caption
    || description !== media.description;

  async function saveMedia() {
    if (!canEdit || !changed || saveState === 'saving') return;

    setSaveState('saving');

    try {
      const payload = await adminFetch<{
        ok: boolean;
        data?: {
          media: {
            id: number;
            title: string;
            alt: string;
            caption: string;
            description: string;
            modifiedAt: string;
          };
        };
      }>('/api/admin/media-save.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: JSON.stringify({
          mediaId: media.id,
          title,
          alt,
          caption,
          description,
        }),
      });

      if (!payload.ok || !payload.data) {
        throw new Error('media_save_invalid_response');
      }

      const saved = payload.data.media;
      setData((current) => current
        ? {
            ...current,
            media: {
              ...current.media,
              title: saved.title,
              alt: saved.alt,
              caption: saved.caption,
              description: saved.description,
              modifiedAt: saved.modifiedAt,
            },
          }
        : current
      );
      setTitle(saved.title);
      setAlt(saved.alt);
      setCaption(saved.caption);
      setDescription(saved.description);
      setSaveState('saved');
    } catch {
      setSaveState('error');
    }
  }

  return (
    <>
      <header className="admin-editor-header">
        <div>
          <a href="/sistema/midia" className="admin-editor-header__back">← Mídia</a>
          <div className="admin-editor-header__title">
            <h1>Editar mídia</h1>
          </div>
          <p>Adicionada em {formatAdminDate(media.createdAt)}</p>
        </div>

        <div className="admin-editor-header__actions">
          <a href={media.url} target="_blank" rel="noopener noreferrer">Abrir arquivo ↗</a>
          <button
            type="button"
            className="admin-button--primary"
            disabled={!canEdit || !changed || saveState === 'saving'}
            onClick={() => void saveMedia()}
          >
            {saveState === 'saving' ? 'Salvando…' : 'Salvar'}
          </button>
        </div>
      </header>

      {saveState === 'saved' && (
        <div className="admin-save-feedback admin-save-feedback--success" role="status">
          Informações da mídia atualizadas.
        </div>
      )}

      {saveState === 'error' && (
        <div className="admin-save-feedback admin-save-feedback--error" role="alert">
          Não foi possível salvar a mídia. Tente novamente.
        </div>
      )}

      <div className="admin-media-editor">
        <section className="admin-editor-main">
          <figure className="admin-media-editor__preview">
            {media.mimeType.startsWith('image/') ? (
              <img src={media.url} alt={alt || title} />
            ) : (
              <div>{media.mimeType || 'Arquivo'}</div>
            )}
          </figure>

          <section className="admin-editor-card">
            <div className="admin-editor-card__head">
              <span>Arquivo</span>
              <strong>Informações</strong>
            </div>

            <div className="admin-editor-card__body admin-editor-card__body--fields">
              <label className="admin-editor-field">
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
                <span>Texto alternativo</span>
                <input
                  value={alt}
                  readOnly={!canEdit}
                  placeholder="Descreva a imagem para acessibilidade"
                  onChange={(event) => {
                    setAlt(event.target.value);
                    setSaveState('idle');
                  }}
                />
              </label>

              <label className="admin-editor-field">
                <span>Legenda</span>
                <textarea
                  value={caption}
                  readOnly={!canEdit}
                  rows={3}
                  onChange={(event) => {
                    setCaption(event.target.value);
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
            </div>
          </section>
        </section>

        <aside className="admin-editor-sidebar">
          <section className="admin-editor-card">
            <div className="admin-editor-card__head">
              <span>Detalhes</span>
              <strong>Arquivo</strong>
            </div>

            <dl className="admin-editor-meta">
              <div><dt>Tipo</dt><dd>{media.mimeType || '—'}</dd></div>
              <div><dt>Atualizado</dt><dd>{formatAdminDate(media.modifiedAt)}</dd></div>
              <div><dt>ID</dt><dd>#{media.id}</dd></div>
              <div><dt>Arquivo</dt><dd>{media.attachedFile || '—'}</dd></div>
            </dl>
          </section>

          <section className="admin-editor-card">
            <div className="admin-editor-card__head">
              <span>Uso</span>
              <strong>Notícias relacionadas</strong>
            </div>

            <div className="admin-media-usage">
              {media.usedBy.length === 0 ? (
                <p>Esta imagem não está definida como destaque de nenhuma notícia.</p>
              ) : (
                media.usedBy.map((post) => (
                  <article key={post.id}>
                    <a href={post.adminUrl}>{post.title}</a>
                    <span>{statusLabel(post.status)}</span>
                  </article>
                ))
              )}
            </div>
          </section>
        </aside>
      </div>
    </>
  );
}

function SettingsView({ csrfToken }: { csrfToken: string }) {
  const [data, setData] = useState<SettingsPayload['data']>();
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
  }, []);

  if (error) return <AdminError />;
  if (!data) return <AdminLoading />;

  const canEdit = true;

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

function PautasView({ csrfToken }: { csrfToken: string }) {
  const [data, setData] = useState<PautasPayload['data']>();
  const [editingId, setEditingId] = useState(0);
  const [title, setTitle] = useState('');
  const [notes, setNotes] = useState('');
  const [stage, setStage] = useState<PautaItem['stage']>('inbox');
  const [priority, setPriority] = useState<PautaItem['priority']>('normal');
  const [topic, setTopic] = useState('');
  const [sourceName, setSourceName] = useState('');
  const [sourceUrl, setSourceUrl] = useState('');
  const [deadline, setDeadline] = useState('');
  const [assigneeId, setAssigneeId] = useState(0);
  const [saving, setSaving] = useState(false);
  const [capturing, setCapturing] = useState('');
  const [captureResult, setCaptureResult] = useState('');
  const [message, setMessage] = useState<'idle' | 'saved' | 'error'>('idle');
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

  const stageLabels: Record<PautaItem['stage'], string> = {
    inbox: 'Entrada',
    selected: 'Selecionada',
    research: 'Apuração',
    ready: 'Pronta',
    writing: 'Em redação',
  };

  const priorityLabels: Record<PautaItem['priority'], string> = {
    low: 'Baixa',
    normal: 'Normal',
    high: 'Alta',
    urgent: 'Urgente',
  };

  function resetForm() {
    setEditingId(0);
    setTitle('');
    setNotes('');
    setStage('inbox');
    setPriority('normal');
    setTopic('');
    setSourceName('');
    setSourceUrl('');
    setDeadline('');
    setAssigneeId(0);
    setMessage('idle');
  }

  function editPauta(item: PautaItem) {
    setEditingId(item.id);
    setTitle(item.title);
    setNotes(item.notes);
    setStage(item.stage);
    setPriority(item.priority);
    setTopic(item.topic);
    setSourceName(item.sourceName);
    setSourceUrl(item.sourceUrl);
    setDeadline(item.deadline);
    setAssigneeId(item.assigneeId);
    setMessage('idle');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  async function mutatePauta(
    action: 'save' | 'trash' | 'to_draft',
    pautaId = editingId,
  ) {
    if (saving) return;
    if (action === 'save' && !title.trim()) return;

    setSaving(true);
    setMessage('idle');

    try {
      const payload = await adminFetch<PautasPayload>('/api/admin/pautas.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: JSON.stringify(
          action === 'save'
            ? {
                action,
                pautaId,
                title,
                notes,
                stage,
                priority,
                topic,
                sourceName,
                sourceUrl,
                deadline,
                assigneeId,
              }
            : { action, pautaId },
        ),
      });

      if (!payload.ok || !payload.data) throw new Error('pauta_mutation_invalid');

      setData((current) => current
        ? {
            ...current,
            ...payload.data,
            owner: payload.data?.owner ?? current.owner,
            pipeline: payload.data?.pipeline ?? current.pipeline,
            sources: payload.data?.sources ?? current.sources,
            assignees: payload.data?.assignees ?? current.assignees,
          }
        : payload.data
      );

      if (action === 'to_draft' && payload.data.draft?.adminUrl) {
        window.location.href = payload.data.draft.adminUrl;
        return;
      }

      resetForm();
      setMessage('saved');
    } catch {
      setMessage('error');
    } finally {
      setSaving(false);
    }
  }

  async function captureOneFeed(feedUrl: string) {
    const payload = await adminFetch<PautasPayload>('/api/admin/pautas.php', {
      method: 'POST',
      headers: { 'X-CSRF-Token': csrfToken },
      body: JSON.stringify({
        action: 'capture',
        feedUrl,
      }),
    });

    if (!payload.ok || !payload.data) {
      throw new Error('pauta_capture_invalid');
    }

    return payload.data;
  }

  async function captureFeed(feedUrl = '') {
    if (capturing) return;

    setCapturing(feedUrl || 'all');
    setCaptureResult('');
    setMessage('idle');

    try {
      if (feedUrl) {
        const next = await captureOneFeed(feedUrl);
        const captured = next.captured ?? 0;

        setData((current) => current
          ? {
              ...current,
              ...next,
              owner: next.owner ?? current.owner,
              pipeline: next.pipeline ?? current.pipeline,
              sources: next.sources ?? current.sources,
              assignees: next.assignees ?? current.assignees,
            }
          : next
        );

        setCaptureResult(
          captured > 0
            ? captured + ' pauta' + (captured === 1 ? '' : 's') + ' nova' + (captured === 1 ? '' : 's') + ' adicionada' + (captured === 1 ? '' : 's') + '.'
            : 'Nenhuma pauta nova encontrada nesse feed.',
        );
        return;
      }

      const sources = data.sources ?? [];
      let cursor = 0;
      let captured = 0;
      let failures = 0;

      async function worker() {
        while (cursor < sources.length) {
          const source = sources[cursor];
          cursor += 1;

          try {
            const result = await captureOneFeed(source.feedUrl);
            captured += result.captured ?? 0;
          } catch {
            failures += 1;
          }
        }
      }

      const workers = Math.min(4, Math.max(1, sources.length));
      await Promise.all(Array.from({ length: workers }, () => worker()));

      const refreshed = await adminFetch<PautasPayload>('/api/admin/pautas.php');
      if (!refreshed.ok || !refreshed.data) {
        throw new Error('pautas_refresh_invalid');
      }

      setData(refreshed.data);
      setCaptureResult(
        captured > 0
          ? captured + ' pauta' + (captured === 1 ? '' : 's') + ' nova' + (captured === 1 ? '' : 's') + ' adicionada' + (captured === 1 ? '' : 's') + '.'
            + (failures > 0 ? ' ' + failures + ' feed' + (failures === 1 ? '' : 's') + ' não responderam.' : '')
          : failures > 0
            ? 'Nenhuma pauta nova. ' + failures + ' feed' + (failures === 1 ? '' : 's') + ' não responderam.'
            : 'Nenhuma pauta nova encontrada.',
      );
    } catch {
      setMessage('error');
    } finally {
      setCapturing('');
    }
  }

  const stages = Object.keys(stageLabels) as PautaItem['stage'][];
  const counts = Object.fromEntries(
    stages.map((stageKey) => [
      stageKey,
      data.items.filter((item) => item.stage === stageKey).length,
    ]),
  ) as Record<PautaItem['stage'], number>;

  return (
    <>
      <AdminPageHeader
        eyebrow="Planejamento editorial"
        title="Mesa de Pautas"
        description="Organize ideias, fontes e apurações antes de elas virarem notícia."
      />

      {message === 'saved' && (
        <div className="admin-save-feedback admin-save-feedback--success" role="status">
          Mesa de Pautas atualizada.
        </div>
      )}
      {message === 'error' && (
        <div className="admin-save-feedback admin-save-feedback--error" role="alert">
          Não foi possível atualizar a pauta.
        </div>
      )}

      <section className="admin-pautas-summary" aria-label="Resumo da Mesa de Pautas">
        <div>
          <span>Pautas abertas</span>
          <strong>{data.items.length}</strong>
        </div>
        <div>
          <span>Em apuração</span>
          <strong>{counts.research}</strong>
        </div>
        <div>
          <span>Prontas</span>
          <strong>{counts.ready}</strong>
        </div>
        <div>
          <span>Em redação</span>
          <strong>{counts.writing}</strong>
        </div>
      </section>

      <div className="admin-pautas-workspace">
        <aside className="admin-pauta-editor">
          <section className="admin-editor-card">
            <div className="admin-editor-card__head">
              <span>{editingId ? 'Editar' : 'Nova'}</span>
              <strong>{editingId ? 'Pauta #' + editingId : 'Criar pauta'}</strong>
            </div>

            <div className="admin-editor-card__body admin-editor-card__body--fields">
              <label className="admin-editor-field">
                <span>Título / ideia</span>
                <input
                  value={title}
                  autoFocus={!editingId}
                  onChange={(event) => setTitle(event.target.value)}
                />
              </label>

              <div className="admin-pauta-editor__row">
                <label className="admin-editor-field">
                  <span>Etapa</span>
                  <select value={stage} onChange={(event) => setStage(event.target.value as PautaItem['stage'])}>
                    {Object.entries(stageLabels).map(([value, label]) => (
                      <option value={value} key={value}>{label}</option>
                    ))}
                  </select>
                </label>

                <label className="admin-editor-field">
                  <span>Prioridade</span>
                  <select value={priority} onChange={(event) => setPriority(event.target.value as PautaItem['priority'])}>
                    {Object.entries(priorityLabels).map(([value, label]) => (
                      <option value={value} key={value}>{label}</option>
                    ))}
                  </select>
                </label>
              </div>

              <label className="admin-editor-field">
                <span>Tema</span>
                <input
                  value={topic}
                  placeholder="Pelotas, IA, ciência, universo…"
                  onChange={(event) => setTopic(event.target.value)}
                />
              </label>

              <label className="admin-editor-field">
                <span>Responsável</span>
                <select value={assigneeId || ''} onChange={(event) => setAssigneeId(Number(event.target.value) || 0)}>
                  <option value="">Eu / não definido</option>
                  {(data.assignees ?? []).map((assignee) => (
                    <option value={assignee.id} key={assignee.id}>{assignee.name}</option>
                  ))}
                </select>
              </label>

              <label className="admin-editor-field">
                <span>Prazo</span>
                <input type="datetime-local" value={deadline} onChange={(event) => setDeadline(event.target.value)} />
              </label>

              <label className="admin-editor-field">
                <span>Origem</span>
                <input
                  value={sourceName}
                  placeholder="Pessoa, órgão, site ou feed"
                  onChange={(event) => setSourceName(event.target.value)}
                />
              </label>

              <label className="admin-editor-field">
                <span>Link de referência</span>
                <input type="url" value={sourceUrl} onChange={(event) => setSourceUrl(event.target.value)} />
              </label>

              <label className="admin-editor-field">
                <span>Anotações</span>
                <textarea
                  rows={7}
                  value={notes}
                  placeholder="O que sabemos, o que falta apurar, perguntas, contexto…"
                  onChange={(event) => setNotes(event.target.value)}
                />
              </label>

              <div className="admin-pauta-editor__actions">
                <button
                  type="button"
                  className="admin-button--primary"
                  disabled={!title.trim() || saving}
                  onClick={() => void mutatePauta('save')}
                >
                  {saving ? 'Salvando…' : editingId ? 'Salvar pauta' : 'Adicionar pauta'}
                </button>

                {editingId > 0 && (
                  <>
                    <button
                      type="button"
                      disabled={saving}
                      onClick={() => void mutatePauta('to_draft')}
                    >
                      Produzir matéria
                    </button>
                    <button type="button" disabled={saving} onClick={resetForm}>
                      Cancelar
                    </button>
                    <button
                      type="button"
                      className="danger"
                      disabled={saving}
                      onClick={() => {
                        if (window.confirm('Arquivar esta pauta?')) {
                          void mutatePauta('trash');
                        }
                      }}
                    >
                      Arquivar
                    </button>
                  </>
                )}
              </div>
            </div>
          </section>
        </aside>

        <section className="admin-pauta-board" aria-label="Quadro de pautas">
          {stages.map((stageKey) => (
            <section className="admin-pauta-column" key={stageKey}>
              <header>
                <span>{stageLabels[stageKey]}</span>
                <strong>{counts[stageKey]}</strong>
              </header>

              <div>
                {data.items
                  .filter((item) => item.stage === stageKey)
                  .map((item) => (
                    <article
                      className={
                        'admin-pauta-card admin-pauta-card--' + item.priority
                        + (editingId === item.id ? ' admin-pauta-card--active' : '')
                      }
                      key={item.id}
                    >
                      <button type="button" onClick={() => editPauta(item)}>
                        <div className="admin-pauta-card__meta">
                          <span>{priorityLabels[item.priority]}</span>
                          {item.topic && <span>{item.topic}</span>}
                        </div>
                        <h3>{item.title}</h3>
                        {item.sourceName && <p>{item.sourceName}</p>}
                        <footer>
                          <span>{item.assignee}</span>
                          <span>{item.deadline ? formatAdminDate(item.deadline) : 'Sem prazo'}</span>
                        </footer>
                      </button>

                      {item.draftAdminUrl && (
                        <a href={item.draftAdminUrl}>Abrir matéria →</a>
                      )}
                    </article>
                  ))}

                {counts[stageKey] === 0 && (
                  <p className="admin-pauta-column__empty">Nenhuma pauta.</p>
                )}
              </div>
            </section>
          ))}
        </section>
      </div>

      <section className="admin-widget admin-pautas-radar">
        <div className="admin-widget__head">
          <div>
            <span>Radar</span>
            <h2>Fontes monitoradas</h2>
          </div>
          <button
            type="button"
            className="admin-radar-capture"
            disabled={Boolean(capturing)}
            onClick={() => void captureFeed()}
          >
            {capturing === 'all' ? 'Capturando…' : 'Capturar agora'}
          </button>
        </div>

        {captureResult && (
          <div className="admin-radar-result" role="status">{captureResult}</div>
        )}

        <div className="admin-pautas-sources">
          {(data.sources ?? []).map((source) => (
            <article key={source.feedUrl}>
              <div>
                <span className="admin-pautas-source__category">{source.category}</span>
                <h3>{source.name}</h3>
                <p>{source.kind} • prioridade editorial {source.priority}</p>
              </div>

              <div className="admin-pautas-source__actions">
                <button
                  type="button"
                  disabled={Boolean(capturing)}
                  onClick={() => void captureFeed(source.feedUrl)}
                >
                  {capturing === source.feedUrl ? 'Capturando…' : 'Capturar'}
                </button>
                <a href={source.feedUrl} target="_blank" rel="noopener noreferrer">
                  RSS ↗
                </a>
              </div>
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
          {view === 'homeLayout' && (
            user.permissions.publishPosts && user.capabilities.includes('edit_others_posts')
              ? <HomeLayoutView csrfToken={csrfToken} />
              : <AdminAccessDenied />
          )}
          {view === 'agenda' && (
            user.permissions.editPosts
              ? <AgendaView csrfToken={csrfToken} />
              : <AdminAccessDenied />
          )}
          {view === 'posts' && <PostsView user={user} csrfToken={csrfToken} />}
          {view === 'post' && <PostEditorView user={user} csrfToken={csrfToken} />}
          {view === 'pages' && (
            user.permissions.editPages
              ? <PagesView />
              : <AdminAccessDenied />
          )}
          {view === 'page' && (
            user.permissions.editPages
              ? <PageEditorView user={user} csrfToken={csrfToken} />
              : <AdminAccessDenied />
          )}
          {view === 'categories' && <CategoriesView />}
          {view === 'categoryNew' && <NewCategoryView csrfToken={csrfToken} />}
          {view === 'category' && <CategoryEditorView csrfToken={csrfToken} />}
          {view === 'media' && <MediaView csrfToken={csrfToken} />}
          {view === 'mediaItem' && <MediaItemView csrfToken={csrfToken} />}
          {view === 'comments' && (
            user.permissions.moderateComments
              ? <CommentsView csrfToken={csrfToken} />
              : <AdminAccessDenied />
          )}
          {view === 'sources' && (
            user.permissions.editPosts
              ? <SourcesView csrfToken={csrfToken} />
              : <AdminAccessDenied />
          )}
          {view === 'users' && <UsersView />}
          {view === 'user' && <UserEditorView user={user} csrfToken={csrfToken} />}
          {view === 'settings' && <SettingsView csrfToken={csrfToken} />}
          {view === 'pautas' && (
            user.login === 'agenciamobi' && user.permissions.managePautas
              ? <PautasView csrfToken={csrfToken} />
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
