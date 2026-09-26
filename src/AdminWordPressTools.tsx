import { FormEvent, useEffect, useMemo, useState } from 'react';
import './admin-wordpress-tools.css';

type WordPressCapabilitiesPayload = {
  ok: boolean;
  data?: {
    engine: {
      source: string;
      tablePrefix: string;
    };
    postTypes: Array<{
      postType: string;
      total: number;
      statuses: Record<string, number>;
    }>;
    taxonomies: Array<{
      taxonomy: string;
      terms: number;
      relationships: number;
    }>;
    metaKeys: Array<{
      key: string;
      count: number;
    }>;
    counts: {
      users: number;
      comments: number;
      revisions: number;
      attachments: number;
      navMenus: number;
      navMenuItems: number;
    };
    comments: Record<string, number>;
    options: {
      stickyPosts: boolean;
      activePlugins: boolean;
      navMenuOptions: boolean;
      permalinkStructure: boolean;
      timezone: boolean;
    };
    health: {
      orphanRelationships: number;
      orphanTaxonomyTerms: number;
    };
    opportunities: Array<{
      key: string;
      available: boolean;
      label: string;
      description: string;
    }>;
  };
};

type RedirectItem = {
  id: number;
  source: string;
  destination: string;
  statusCode: number;
  enabled: boolean;
  note: string;
  createdAt: string;
  modifiedAt: string;
};

type RedirectsPayload = {
  ok: boolean;
  data?: {
    items: RedirectItem[];
    allowedStatusCodes: number[];
  };
};

async function request<T>(url: string, init?: RequestInit): Promise<T> {
  const response = await fetch(url, {
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      ...(init?.body ? { 'Content-Type': 'application/json' } : {}),
      ...(init?.headers ?? {}),
    },
    ...init,
  });

  const payload = await response.json() as T & { error?: { code?: string } };
  if (!response.ok) {
    throw new Error(payload.error?.code ?? 'request_failed');
  }

  return payload;
}

function number(value: number) {
  return new Intl.NumberFormat('pt-BR').format(value);
}

function date(value: string) {
  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) return value;

  return new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short',
    timeZone: 'America/Sao_Paulo',
  }).format(parsed);
}

const POST_TYPE_LABELS: Record<string, string> = {
  post: 'Notícias',
  page: 'Páginas',
  attachment: 'Mídia',
  revision: 'Revisões',
  nav_menu_item: 'Itens de menu',
  nj_activity: 'Atividade editorial',
  nj_correction: 'Correções',
  nj_editorial_comment: 'Comentários editoriais',
  nj_pauta: 'Pautas',
  nj_agenda: 'Agenda',
  nj_redirect: 'Redirecionamentos',
};

function postTypeLabel(value: string) {
  return POST_TYPE_LABELS[value] ?? value;
}

export function AdminWordPressTools({ csrfToken }: { csrfToken: string }) {
  const [capabilities, setCapabilities] = useState<WordPressCapabilitiesPayload['data']>();
  const [redirects, setRedirects] = useState<RedirectItem[]>([]);
  const [allowedStatusCodes, setAllowedStatusCodes] = useState([301, 302, 307, 308, 410]);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [saveState, setSaveState] = useState<'idle' | 'saving' | 'saved' | 'error'>('idle');
  const [editingId, setEditingId] = useState(0);
  const [source, setSource] = useState('');
  const [destination, setDestination] = useState('');
  const [statusCode, setStatusCode] = useState(301);
  const [enabled, setEnabled] = useState(true);
  const [note, setNote] = useState('');

  async function load() {
    setState('loading');

    try {
      const [capabilityPayload, redirectPayload] = await Promise.all([
        request<WordPressCapabilitiesPayload>('/api/admin/wp-capabilities.php'),
        request<RedirectsPayload>('/api/admin/redirects.php'),
      ]);

      if (!capabilityPayload.ok || !capabilityPayload.data) {
        throw new Error('capabilities_invalid');
      }

      if (!redirectPayload.ok || !redirectPayload.data) {
        throw new Error('redirects_invalid');
      }

      setCapabilities(capabilityPayload.data);
      setRedirects(redirectPayload.data.items);
      setAllowedStatusCodes(redirectPayload.data.allowedStatusCodes);
      setState('ready');
    } catch {
      setState('error');
    }
  }

  useEffect(() => {
    void load();
  }, []);

  const postTypes = useMemo(
    () => [...(capabilities?.postTypes ?? [])].sort((a, b) => b.total - a.total),
    [capabilities?.postTypes],
  );

  function clearForm() {
    setEditingId(0);
    setSource('');
    setDestination('');
    setStatusCode(301);
    setEnabled(true);
    setNote('');
  }

  function edit(item: RedirectItem) {
    setEditingId(item.id);
    setSource(item.source);
    setDestination(item.destination);
    setStatusCode(item.statusCode);
    setEnabled(item.enabled);
    setNote(item.note);
    setSaveState('idle');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  async function save(event: FormEvent) {
    event.preventDefault();
    if (saveState === 'saving' || !source.trim()) return;
    if (statusCode !== 410 && !destination.trim()) return;

    setSaveState('saving');

    try {
      const payload = await request<RedirectsPayload>('/api/admin/redirects.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: JSON.stringify({
          action: 'save',
          id: editingId,
          source,
          destination,
          statusCode,
          enabled,
          note,
        }),
      });

      if (!payload.ok || !payload.data) throw new Error('redirect_save_invalid');

      setRedirects(payload.data.items);
      setAllowedStatusCodes(payload.data.allowedStatusCodes);
      clearForm();
      setSaveState('saved');
    } catch {
      setSaveState('error');
    }
  }

  async function remove(item: RedirectItem) {
    if (!window.confirm('Mover esta regra de redirecionamento para a lixeira?')) return;

    try {
      const payload = await request<RedirectsPayload>('/api/admin/redirects.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: JSON.stringify({ action: 'delete', id: item.id }),
      });

      if (!payload.ok || !payload.data) throw new Error('redirect_delete_invalid');
      setRedirects(payload.data.items);
      if (editingId === item.id) clearForm();
    } catch {
      setSaveState('error');
    }
  }

  if (state === 'loading') {
    return <div className="admin-wp-loading">Lendo o acervo WordPress…</div>;
  }

  if (state === 'error' || !capabilities) {
    return (
      <div className="admin-error" role="alert">
        <strong>Não foi possível mapear o banco WordPress.</strong>
        <p>Atualize a página e tente novamente.</p>
      </div>
    );
  }

  const healthy =
    capabilities.health.orphanRelationships === 0
    && capabilities.health.orphanTaxonomyTerms === 0;

  return (
    <div className="admin-wp-tools">
      <header className="admin-wp-hero">
        <div>
          <span>Acervo WordPress</span>
          <h1>O banco é parte do produto</h1>
          <p>
            Inventário vivo de conteúdo, taxonomias, revisões, mídia, menus e metadados
            que já existem no legado e podem ser reutilizados pelo novo portal.
          </p>
        </div>
        <div className={healthy ? 'admin-wp-health admin-wp-health--ok' : 'admin-wp-health'}>
          <strong>{healthy ? 'Estrutura íntegra' : 'Revisar integridade'}</strong>
          <span>
            {number(capabilities.health.orphanRelationships)} relações órfãs ·{' '}
            {number(capabilities.health.orphanTaxonomyTerms)} termos órfãos
          </span>
        </div>
      </header>

      <section className="admin-wp-metrics" aria-label="Resumo do banco WordPress">
        <article><span>Usuários</span><strong>{number(capabilities.counts.users)}</strong></article>
        <article><span>Mídias</span><strong>{number(capabilities.counts.attachments)}</strong></article>
        <article><span>Revisões</span><strong>{number(capabilities.counts.revisions)}</strong></article>
        <article><span>Comentários</span><strong>{number(capabilities.counts.comments)}</strong></article>
        <article><span>Menus</span><strong>{number(capabilities.counts.navMenus)}</strong></article>
        <article><span>Itens de menu</span><strong>{number(capabilities.counts.navMenuItems)}</strong></article>
      </section>

      <div className="admin-wp-grid">
        <section className="admin-wp-card">
          <header>
            <span>Conteúdo</span>
            <strong>Tipos de post existentes</strong>
          </header>
          <div className="admin-wp-table">
            {postTypes.map((item) => (
              <div className="admin-wp-table__row" key={item.postType}>
                <div>
                  <strong>{postTypeLabel(item.postType)}</strong>
                  <small>{item.postType}</small>
                </div>
                <span>{number(item.total)}</span>
                <small>
                  {Object.entries(item.statuses)
                    .map(([status, total]) => status + ': ' + number(total))
                    .join(' · ')}
                </small>
              </div>
            ))}
          </div>
        </section>

        <section className="admin-wp-card">
          <header>
            <span>Organização</span>
            <strong>Taxonomias</strong>
          </header>
          <div className="admin-wp-table">
            {capabilities.taxonomies.map((item) => (
              <div className="admin-wp-table__row" key={item.taxonomy}>
                <div>
                  <strong>{item.taxonomy}</strong>
                  <small>{number(item.relationships)} relações</small>
                </div>
                <span>{number(item.terms)}</span>
                <small>termos</small>
              </div>
            ))}
          </div>
        </section>
      </div>

      <section className="admin-wp-card">
        <header>
          <span>Possibilidades</span>
          <strong>Recursos nativos que podemos transformar em produto</strong>
        </header>
        <div className="admin-wp-opportunities">
          {capabilities.opportunities.map((item) => (
            <article key={item.key} className={item.available ? 'is-available' : ''}>
              <span>{item.available ? 'Disponível no acervo' : 'Estrutura suportada'}</span>
              <strong>{item.label}</strong>
              <p>{item.description}</p>
            </article>
          ))}
        </div>
      </section>

      <section className="admin-wp-card">
        <header>
          <span>Metadados</span>
          <strong>Chaves mais usadas</strong>
        </header>
        <div className="admin-wp-meta-cloud">
          {capabilities.metaKeys.map((item) => (
            <span key={item.key}>
              {item.key}
              <small>{number(item.count)}</small>
            </span>
          ))}
        </div>
      </section>

      <section className="admin-wp-card admin-wp-redirects">
        <header>
          <span>Migração e SEO</span>
          <strong>Redirecionamentos</strong>
          <p>
            Regras armazenadas no próprio wp_posts/wp_postmeta. A resolução acontece
            no servidor antes do SPA, preservando URLs antigas e mudanças de slug.
          </p>
        </header>

        <form className="admin-wp-redirect-form" onSubmit={save}>
          <label>
            <span>Origem</span>
            <input
              value={source}
              placeholder="/noticia-antiga"
              onChange={(event) => setSource(event.target.value)}
              required
            />
          </label>

          <label>
            <span>Destino</span>
            <input
              value={destination}
              disabled={statusCode === 410}
              placeholder="/noticia/nova-url"
              onChange={(event) => setDestination(event.target.value)}
              required={statusCode !== 410}
            />
          </label>

          <label>
            <span>Status HTTP</span>
            <select
              value={statusCode}
              onChange={(event) => setStatusCode(Number(event.target.value))}
            >
              {allowedStatusCodes.map((code) => (
                <option value={code} key={code}>
                  {code}{code === 410 ? ' · removido' : code === 301 || code === 308 ? ' · permanente' : ' · temporário'}
                </option>
              ))}
            </select>
          </label>

          <label className="admin-wp-redirect-form__wide">
            <span>Nota interna</span>
            <input
              value={note}
              maxLength={1000}
              placeholder="Motivo da regra, migração, slug anterior…"
              onChange={(event) => setNote(event.target.value)}
            />
          </label>

          <label className="admin-wp-toggle">
            <input
              type="checkbox"
              checked={enabled}
              onChange={(event) => setEnabled(event.target.checked)}
            />
            <span>Regra ativa</span>
          </label>

          <div className="admin-wp-redirect-form__actions">
            {editingId > 0 && (
              <button type="button" onClick={clearForm}>
                Cancelar edição
              </button>
            )}
            <button type="submit" className="admin-button--primary" disabled={saveState === 'saving'}>
              {saveState === 'saving'
                ? 'Salvando…'
                : editingId > 0
                  ? 'Atualizar regra'
                  : 'Adicionar regra'}
            </button>
          </div>
        </form>

        {saveState === 'saved' && (
          <p className="admin-wp-feedback admin-wp-feedback--ok">Regra salva.</p>
        )}
        {saveState === 'error' && (
          <p className="admin-wp-feedback admin-wp-feedback--error">
            Não foi possível atualizar a regra. Confira origem, destino e possíveis duplicidades.
          </p>
        )}

        <div className="admin-wp-redirect-list">
          {redirects.length === 0 ? (
            <p className="admin-wp-empty">Nenhum redirecionamento cadastrado ainda.</p>
          ) : (
            redirects.map((item) => (
              <article key={item.id} className={!item.enabled ? 'is-disabled' : ''}>
                <div className="admin-wp-redirect-list__route">
                  <span>{item.statusCode}</span>
                  <div>
                    <strong>{item.source}</strong>
                    <small>
                      {item.statusCode === 410 ? 'Conteúdo removido' : '→ ' + item.destination}
                    </small>
                  </div>
                </div>
                <div className="admin-wp-redirect-list__meta">
                  <span>{item.enabled ? 'Ativo' : 'Inativo'}</span>
                  <small>{item.note || 'Sem nota'} · atualizado {date(item.modifiedAt)}</small>
                </div>
                <div className="admin-wp-redirect-list__actions">
                  <button type="button" onClick={() => edit(item)}>Editar</button>
                  <button type="button" className="danger" onClick={() => void remove(item)}>Excluir</button>
                </div>
              </article>
            ))
          )}
        </div>
      </section>
    </div>
  );
}
