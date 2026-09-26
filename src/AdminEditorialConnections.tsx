import { FormEvent, useMemo, useState } from 'react';

export type EditorialRelatedStory = {
  id: number;
  title: string;
  status: string;
  modifiedAt: string;
  publicUrl: string | null;
};

export type EditorialSeries = {
  name: string;
  slug: string;
  order: number;
};

type PickerPayload = {
  ok: boolean;
  data?: {
    items: EditorialRelatedStory[];
    query: string;
  };
};

type Props = {
  postId: number;
  disabled?: boolean;
  tags: string[];
  tagSuggestions: Array<{
    id: number;
    name: string;
    slug: string;
    count: number;
  }>;
  related: EditorialRelatedStory[];
  series: EditorialSeries;
  onTagsChange: (tags: string[]) => void;
  onRelatedChange: (items: EditorialRelatedStory[]) => void;
  onSeriesChange: (series: EditorialSeries) => void;
};

function normalizeTag(value: string) {
  return value.replace(/\s+/gu, ' ').trim().slice(0, 80);
}

function slugify(value: string) {
  return value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/gu, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/gu, '-')
    .replace(/^-+|-+$/gu, '')
    .replace(/-{2,}/gu, '-')
    .slice(0, 180);
}

function statusLabel(status: string) {
  return {
    publish: 'Publicada',
    future: 'Agendada',
    draft: 'Rascunho',
    pending: 'Pendente',
    private: 'Privada',
  }[status] ?? status;
}

export function AdminEditorialConnections({
  postId,
  disabled = false,
  tags,
  tagSuggestions,
  related,
  series,
  onTagsChange,
  onRelatedChange,
  onSeriesChange,
}: Props) {
  const [tagDraft, setTagDraft] = useState('');
  const [query, setQuery] = useState('');
  const [picker, setPicker] = useState<EditorialRelatedStory[]>([]);
  const [pickerState, setPickerState] = useState<'idle' | 'loading' | 'error'>('idle');

  const selectedTags = useMemo(
    () => new Set(tags.map((tag) => tag.toLocaleLowerCase('pt-BR'))),
    [tags],
  );

  const usefulSuggestions = tagSuggestions
    .filter((tag) => !selectedTags.has(tag.name.toLocaleLowerCase('pt-BR')))
    .slice(0, 12);

  function addTags(values: string[]) {
    const next = [...tags];
    const existing = new Set(next.map((item) => item.toLocaleLowerCase('pt-BR')));

    for (const value of values) {
      const normalized = normalizeTag(value);
      if (!normalized) continue;
      const key = normalized.toLocaleLowerCase('pt-BR');
      if (existing.has(key)) continue;
      if (next.length >= 20) break;
      next.push(normalized);
      existing.add(key);
    }

    onTagsChange(next);
  }

  function submitTag(event: FormEvent) {
    event.preventDefault();
    if (disabled) return;
    addTags(tagDraft.split(','));
    setTagDraft('');
  }

  async function searchRelated(event?: FormEvent) {
    event?.preventDefault();
    if (disabled) return;

    setPickerState('loading');

    try {
      const params = new URLSearchParams({
        exclude: String(postId),
      });
      if (query.trim()) params.set('q', query.trim());

      const response = await fetch('/api/admin/post-picker.php?' + params.toString(), {
        headers: { Accept: 'application/json' },
      });
      if (!response.ok) throw new Error('related_picker_failed');

      const payload = await response.json() as PickerPayload;
      if (!payload.ok || !payload.data) throw new Error('related_picker_invalid');

      const selected = new Set(related.map((item) => item.id));
      setPicker(payload.data.items.filter((item) => !selected.has(item.id)));
      setPickerState('idle');
    } catch {
      setPickerState('error');
    }
  }

  function addRelated(item: EditorialRelatedStory) {
    if (disabled || related.some((current) => current.id === item.id) || related.length >= 8) return;
    onRelatedChange([...related, item]);
    setPicker((current) => current.filter((candidate) => candidate.id !== item.id));
  }

  function removeRelated(id: number) {
    if (disabled) return;
    onRelatedChange(related.filter((item) => item.id !== id));
  }

  function moveRelated(index: number, direction: -1 | 1) {
    const target = index + direction;
    if (disabled || target < 0 || target >= related.length) return;

    const next = [...related];
    [next[index], next[target]] = [next[target], next[index]];
    onRelatedChange(next);
  }

  return (
    <section className="admin-editor-card admin-editorial-connections">
      <div className="admin-editor-card__head">
        <span>Organização editorial</span>
        <strong>Tags, relacionadas e dossiê</strong>
      </div>

      <div className="admin-editorial-connections__section">
        <div className="admin-editorial-connections__heading">
          <div>
            <strong>Tags</strong>
            <span>Taxonomia nativa do acervo. Até 20 por matéria.</span>
          </div>
          <span>{tags.length}/20</span>
        </div>

        {tags.length > 0 && (
          <div className="admin-editorial-tags">
            {tags.map((tag) => (
              <button
                type="button"
                disabled={disabled}
                title={'Remover ' + tag}
                onClick={() => onTagsChange(tags.filter((item) => item !== tag))}
                key={tag}
              >
                <span>{tag}</span>
                <b aria-hidden="true">×</b>
              </button>
            ))}
          </div>
        )}

        <form className="admin-editorial-tag-form" onSubmit={submitTag}>
          <input
            value={tagDraft}
            disabled={disabled || tags.length >= 20}
            placeholder="Adicionar tags, separadas por vírgula"
            onChange={(event) => setTagDraft(event.target.value)}
          />
          <button type="submit" disabled={disabled || !tagDraft.trim() || tags.length >= 20}>
            Adicionar
          </button>
        </form>

        {usefulSuggestions.length > 0 && (
          <div className="admin-editorial-tag-suggestions">
            <span>Usadas no acervo</span>
            <div>
              {usefulSuggestions.map((tag) => (
                <button
                  type="button"
                  disabled={disabled || tags.length >= 20}
                  onClick={() => addTags([tag.name])}
                  key={tag.id}
                >
                  + {tag.name}
                  {tag.count > 0 && <small>{tag.count}</small>}
                </button>
              ))}
            </div>
          </div>
        )}
      </div>

      <div className="admin-editorial-connections__section">
        <div className="admin-editorial-connections__heading">
          <div>
            <strong>Matérias relacionadas</strong>
            <span>A ordem escolhida aqui tem prioridade sobre relações automáticas.</span>
          </div>
          <span>{related.length}/8</span>
        </div>

        {related.length > 0 && (
          <ol className="admin-related-editor">
            {related.map((item, index) => (
              <li key={item.id}>
                <div>
                  <strong>{item.title}</strong>
                  <span>#{item.id} • {statusLabel(item.status)}</span>
                </div>
                <div className="admin-related-editor__actions">
                  <button
                    type="button"
                    disabled={disabled || index === 0}
                    title="Subir"
                    onClick={() => moveRelated(index, -1)}
                  >
                    ↑
                  </button>
                  <button
                    type="button"
                    disabled={disabled || index === related.length - 1}
                    title="Descer"
                    onClick={() => moveRelated(index, 1)}
                  >
                    ↓
                  </button>
                  <button
                    type="button"
                    disabled={disabled}
                    className="danger"
                    onClick={() => removeRelated(item.id)}
                  >
                    Remover
                  </button>
                </div>
              </li>
            ))}
          </ol>
        )}

        {related.length < 8 && (
          <>
            <form className="admin-related-search" onSubmit={searchRelated}>
              <input
                type="search"
                value={query}
                disabled={disabled}
                placeholder="Buscar matéria por título"
                onChange={(event) => setQuery(event.target.value)}
              />
              <button type="submit" disabled={disabled || pickerState === 'loading'}>
                {pickerState === 'loading' ? 'Buscando…' : 'Buscar'}
              </button>
            </form>

            {pickerState === 'error' && (
              <p className="admin-editorial-connections__error">
                Não foi possível buscar matérias.
              </p>
            )}

            {picker.length > 0 && (
              <div className="admin-related-results">
                {picker.map((item) => (
                  <button type="button" onClick={() => addRelated(item)} key={item.id}>
                    <span>
                      <strong>{item.title}</strong>
                      <small>#{item.id} • {statusLabel(item.status)}</small>
                    </span>
                    <b>Adicionar</b>
                  </button>
                ))}
              </div>
            )}
          </>
        )}
      </div>

      <div className="admin-editorial-connections__section">
        <div className="admin-editorial-connections__heading">
          <div>
            <strong>Dossiê / série</strong>
            <span>Cria uma sequência navegável de matérias sobre o mesmo assunto.</span>
          </div>
        </div>

        <div className="admin-editorial-series-grid">
          <label className="admin-editor-field">
            <span>Nome do dossiê</span>
            <input
              value={series.name}
              disabled={disabled}
              maxLength={180}
              placeholder="Ex.: Corrida espacial 2026"
              onChange={(event) => {
                const name = event.target.value;
                onSeriesChange({
                  ...series,
                  name,
                  slug: series.slug || slugify(name),
                });
              }}
            />
          </label>

          <label className="admin-editor-field">
            <span>Slug</span>
            <input
              value={series.slug}
              disabled={disabled || !series.name}
              placeholder="corrida-espacial-2026"
              onChange={(event) => onSeriesChange({
                ...series,
                slug: slugify(event.target.value),
              })}
            />
          </label>

          <label className="admin-editor-field">
            <span>Ordem</span>
            <input
              type="number"
              min="0"
              max="999"
              value={series.order}
              disabled={disabled || !series.name}
              onChange={(event) => onSeriesChange({
                ...series,
                order: Math.max(0, Math.min(999, Number(event.target.value) || 0)),
              })}
            />
          </label>
        </div>

        {series.slug && (
          <a
            className="admin-editorial-series-preview"
            href={'/dossie/' + series.slug}
            target="_blank"
            rel="noopener noreferrer"
          >
            /dossie/{series.slug} ↗
          </a>
        )}
      </div>
    </section>
  );
}
