import { FormEvent, useEffect, useMemo, useState } from 'react';

type TagItem = {
  id: number;
  taxonomyId: number;
  name: string;
  slug: string;
  description: string;
  count: number;
};

type TagsPayload = {
  ok: boolean;
  data?: {
    items: TagItem[];
    count: number;
    total: number;
    query: string;
  };
  error?: { code?: string };
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

  const payload = (await response.json()) as T & { error?: { code?: string } };
  if (!response.ok) {
    throw new Error(payload.error?.code ?? 'admin_http_' + response.status);
  }

  return payload;
}

function slugify(value: string) {
  return value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
}

export function AdminTags({ csrfToken }: { csrfToken: string }) {
  const params = useMemo(() => new URLSearchParams(window.location.search), []);
  const query = params.get('q') ?? '';

  const [data, setData] = useState<TagsPayload['data']>();
  const [error, setError] = useState(false);
  const [name, setName] = useState('');
  const [slug, setSlug] = useState('');
  const [slugTouched, setSlugTouched] = useState(false);
  const [description, setDescription] = useState('');
  const [busy, setBusy] = useState(false);
  const [feedback, setFeedback] = useState('');
  const [editingId, setEditingId] = useState(0);
  const [editName, setEditName] = useState('');
  const [editSlug, setEditSlug] = useState('');
  const [editDescription, setEditDescription] = useState('');

  useEffect(() => {
    const search = new URLSearchParams();
    if (query) search.set('q', query);

    void request<TagsPayload>('/api/admin/tags.php?' + search.toString())
      .then((payload) => {
        if (!payload.ok || !payload.data) throw new Error('tags_invalid');
        setData(payload.data);
      })
      .catch(() => setError(true));
  }, [query]);

  function beginEdit(tag: TagItem) {
    setEditingId(tag.id);
    setEditName(tag.name);
    setEditSlug(tag.slug);
    setEditDescription(tag.description);
    setFeedback('');
  }

  async function createTag(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy || !name.trim()) return;

    setBusy(true);
    setFeedback('');

    try {
      const payload = await request<{
        ok: boolean;
        data?: { tag: TagItem };
      }>('/api/admin/tag-create.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: JSON.stringify({
          name: name.trim(),
          slug: slugify(slug || name),
          description: description.trim(),
        }),
      });

      if (!payload.ok || !payload.data?.tag) {
        throw new Error('tag_create_invalid');
      }

      window.location.href = '/sistema/tags';
    } catch (cause) {
      const code = cause instanceof Error ? cause.message : '';
      setFeedback(code === 'tag_slug_exists'
        ? 'Já existe um termo usando este slug.'
        : 'Não foi possível adicionar a tag.');
    } finally {
      setBusy(false);
    }
  }

  async function saveTag(tag: TagItem) {
    if (busy || editingId !== tag.id || !editName.trim()) return;

    setBusy(true);
    setFeedback('');

    try {
      const payload = await request<{
        ok: boolean;
        data?: { tag: TagItem };
      }>('/api/admin/tag-save.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: JSON.stringify({
          tagId: tag.id,
          name: editName.trim(),
          slug: slugify(editSlug || editName),
          description: editDescription.trim(),
        }),
      });

      if (!payload.ok || !payload.data?.tag) {
        throw new Error('tag_save_invalid');
      }

      const saved = payload.data.tag;
      setData((current) => current
        ? {
            ...current,
            items: current.items.map((item) => item.id === tag.id
              ? { ...item, ...saved }
              : item),
          }
        : current);
      setEditingId(0);
      setFeedback('Tag atualizada.');
    } catch (cause) {
      const code = cause instanceof Error ? cause.message : '';
      setFeedback(code === 'tag_slug_exists'
        ? 'Já existe um termo usando este slug.'
        : 'Não foi possível atualizar a tag.');
    } finally {
      setBusy(false);
    }
  }

  async function deleteTag(tag: TagItem) {
    if (busy || !window.confirm('Excluir a tag “' + tag.name + '”? Ela será removida das notícias associadas.')) {
      return;
    }

    setBusy(true);
    setFeedback('');

    try {
      const payload = await request<{
        ok: boolean;
        data?: { tag: { id: number; deleted: boolean } };
      }>('/api/admin/tag-delete.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: JSON.stringify({ tagId: tag.id }),
      });

      if (!payload.ok || !payload.data?.tag.deleted) {
        throw new Error('tag_delete_invalid');
      }

      setData((current) => current
        ? {
            ...current,
            items: current.items.filter((item) => item.id !== tag.id),
            count: Math.max(0, current.count - 1),
            total: Math.max(0, current.total - 1),
          }
        : current);
      setFeedback('Tag excluída.');
    } catch {
      setFeedback('Não foi possível excluir a tag.');
    } finally {
      setBusy(false);
    }
  }

  if (error) {
    return (
      <div className="admin-error" role="alert">
        <strong>Não foi possível carregar as tags.</strong>
        <p>Atualize a página e tente novamente.</p>
      </div>
    );
  }

  if (!data) {
    return (
      <div className="admin-loading" aria-busy="true">
        <span /><span /><span />
      </div>
    );
  }

  return (
    <section className="admin-wp-screen">
      <div className="admin-wp-title-row">
        <h1>Tags</h1>
      </div>

      {feedback && (
        <div className="admin-wp-notice" role="status">{feedback}</div>
      )}

      <div className="admin-wp-taxonomy-layout">
        <aside className="admin-wp-taxonomy-create">
          <h2>Adicionar tag</h2>
          <form onSubmit={(event) => void createTag(event)}>
            <label>
              <span>Nome</span>
              <input
                value={name}
                maxLength={200}
                onChange={(event) => {
                  const value = event.target.value;
                  setName(value);
                  if (!slugTouched) setSlug(slugify(value));
                }}
              />
              <small>O nome aparece no site.</small>
            </label>

            <label>
              <span>Slug</span>
              <input
                value={slug}
                maxLength={200}
                onChange={(event) => {
                  setSlugTouched(true);
                  setSlug(event.target.value);
                }}
                onBlur={() => setSlug((value) => slugify(value))}
              />
              <small>Versão amigável do nome usada em URLs e integrações.</small>
            </label>

            <label>
              <span>Descrição</span>
              <textarea
                rows={5}
                value={description}
                onChange={(event) => setDescription(event.target.value)}
              />
              <small>Opcional. Mantida como metadado editorial.</small>
            </label>

            <button className="admin-wp-primary-button" type="submit" disabled={busy || !name.trim()}>
              {busy ? 'Adicionando…' : 'Adicionar tag'}
            </button>
          </form>
        </aside>

        <div className="admin-wp-taxonomy-list">
          <div className="admin-wp-list-toolbar">
            <span>{data.total.toLocaleString('pt-BR')} itens</span>

            <form method="get" action="/sistema/tags">
              <input
                type="search"
                name="q"
                defaultValue={query}
                aria-label="Pesquisar tags"
              />
              <button type="submit">Pesquisar tags</button>
            </form>
          </div>

          <div className="admin-wp-table-wrap">
            <table className="admin-wp-table">
              <thead>
                <tr>
                  <th>Nome</th>
                  <th>Descrição</th>
                  <th>Slug</th>
                  <th>Contagem</th>
                </tr>
              </thead>
              <tbody>
                {data.items.map((tag) => (
                  <tr key={tag.id}>
                    <td className="admin-wp-primary-column">
                      {editingId === tag.id ? (
                        <div className="admin-wp-quick-edit">
                          <label>
                            <span>Nome</span>
                            <input value={editName} onChange={(event) => setEditName(event.target.value)} />
                          </label>
                          <label>
                            <span>Slug</span>
                            <input value={editSlug} onChange={(event) => setEditSlug(event.target.value)} />
                          </label>
                          <label className="admin-wp-quick-edit__wide">
                            <span>Descrição</span>
                            <textarea rows={3} value={editDescription} onChange={(event) => setEditDescription(event.target.value)} />
                          </label>
                          <div className="admin-wp-quick-edit__actions">
                            <button type="button" onClick={() => void saveTag(tag)} disabled={busy}>Atualizar tag</button>
                            <button type="button" className="is-secondary" onClick={() => setEditingId(0)} disabled={busy}>Cancelar</button>
                          </div>
                        </div>
                      ) : (
                        <>
                          <strong>{tag.name}</strong>
                          <div className="admin-row-actions">
                            <button type="button" onClick={() => beginEdit(tag)}>Edição rápida</button>
                            <button type="button" className="admin-row-action-danger" onClick={() => void deleteTag(tag)}>Excluir</button>
                            <span>#{tag.id}</span>
                          </div>
                        </>
                      )}
                    </td>
                    <td>{tag.description || '—'}</td>
                    <td><code>{tag.slug}</code></td>
                    <td>{tag.count.toLocaleString('pt-BR')}</td>
                  </tr>
                ))}
              </tbody>
            </table>

            {data.items.length === 0 && (
              <div className="admin-empty-state">
                Nenhuma tag encontrada.
                {query && <a href="/sistema/tags"> Mostrar todas</a>}
              </div>
            )}
          </div>
        </div>
      </div>
    </section>
  );
}
