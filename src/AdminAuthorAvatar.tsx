import { useState } from 'react';

type MediaImage = {
  id: number;
  title: string;
  url: string;
  alt: string;
  mimeType: string;
};
type AvatarImage = { url: string; alt?: string } | null;

type Props = {
  selectedId: number;
  initialAvatar: AvatarImage;
  csrfToken: string;
  canUpload: boolean;
  disabled?: boolean;
  onChange: (id: number) => void;
};

/** Uses the existing authenticated media endpoints; only attachment IDs are saved on users. */
export function AdminAuthorAvatar({
  selectedId,
  initialAvatar,
  csrfToken,
  canUpload,
  disabled = false,
  onChange,
}: Props) {
  const [open, setOpen] = useState(false);
  const [items, setItems] = useState<MediaImage[]>([]);
  const [query, setQuery] = useState('');
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [loading, setLoading] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState('');
  const [selectedPreview, setSelectedPreview] = useState<AvatarImage>(null);
  const avatar = selectedId === 0
    ? null
    : selectedPreview ?? initialAvatar;

  async function searchMedia(nextQuery = query, nextPage = 1) {
    setLoading(true);
    setError('');
    try {
      const response = await fetch(
        '/api/admin/media.php?per_page=36&page=' + nextPage + '&q=' + encodeURIComponent(nextQuery),
        { credentials: 'same-origin', headers: { Accept: 'application/json' } },
      );
      const payload = (await response.json()) as {
        ok: boolean;
        data?: { items: MediaImage[]; pagination: { totalPages: number } };
      };
      if (!response.ok || !payload.ok || !payload.data) throw new Error('media_unavailable');
      setItems(payload.data.items.filter((item) => item.mimeType.startsWith('image/')));
      setPage(nextPage);
      setTotalPages(payload.data.pagination.totalPages);
    } catch {
      setError('Não foi possível consultar a biblioteca de mídia.');
    } finally {
      setLoading(false);
    }
  }

  async function uploadPhoto(file: File) {
    if (!canUpload || uploading) return;
    setUploading(true);
    setError('');
    try {
      const form = new FormData();
      form.append('file', file);
      const response = await fetch('/api/admin/media-upload.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-CSRF-Token': csrfToken },
        body: form,
      });
      const payload = (await response.json()) as { ok: boolean; data?: { media: MediaImage } };
      if (!response.ok || !payload.ok || !payload.data?.media) throw new Error('upload_failed');
      const media = payload.data.media;
      setSelectedPreview({ url: media.url, alt: media.alt || media.title });
      onChange(media.id);
      setOpen(false);
    } catch {
      setError('Falha no envio. Use JPG, PNG, WebP ou GIF com até 12 MB.');
    } finally {
      setUploading(false);
    }
  }

  return (
    <div className="admin-author-avatar">
      <div className="admin-author-avatar__current">
        {avatar
          ? <img src={avatar.url} alt={avatar.alt || 'Foto atual do colunista'} />
          : <span aria-hidden="true">Sem foto</span>}
        <div>
          <strong>Foto pública</strong>
          <small>Aparece na página de colunistas e no perfil do autor.</small>
          <div className="admin-author-avatar__actions">
            <button type="button" disabled={disabled || !canUpload} onClick={() => {
              setOpen(true);
              setQuery('');
              void searchMedia('', 1);
            }}>
              {selectedId ? 'Trocar foto' : 'Escolher foto'}
            </button>
            {selectedId > 0 && (
              <button type="button" disabled={disabled} onClick={() => {
                setSelectedPreview(null);
                onChange(0);
              }}>Remover</button>
            )}
          </div>
        </div>
      </div>
      {!canUpload && <small>A seleção de fotos exige permissão para acessar a biblioteca de mídia.</small>}
      {open && (
        <div className="admin-author-avatar__overlay" role="dialog" aria-modal="true" aria-label="Escolher foto do colunista">
          <div className="admin-author-avatar__dialog">
            <header>
              <h2>Foto do colunista</h2>
              <button type="button" onClick={() => setOpen(false)} aria-label="Fechar seletor">Fechar</button>
            </header>
            <div className="admin-author-avatar__search">
              <label>
                <span>Buscar na biblioteca</span>
                <input type="search" value={query} onChange={(event) => setQuery(event.target.value)}
                  onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                      event.preventDefault();
                      void searchMedia(query.trim(), 1);
                    }
                  }} />
              </label>
              <button type="button" disabled={loading} onClick={() => void searchMedia(query.trim(), 1)}>Buscar</button>
              {canUpload && (
                <label className="admin-author-avatar__upload">
                  <span>{uploading ? 'Enviando…' : 'Enviar foto'}</span>
                  <input type="file" accept="image/jpeg,image/png,image/webp,image/gif"
                    disabled={uploading}
                    onChange={(event) => {
                      const file = event.target.files?.[0];
                      if (file) void uploadPhoto(file);
                      event.currentTarget.value = '';
                    }} />
                </label>
              )}
            </div>
            {error && <p role="alert" className="admin-field-error">{error}</p>}
            {loading ? <p>Consultando imagens…</p> : (
              <div className="admin-author-avatar__grid">
                {items.map((item) => (
                  <button type="button" key={item.id}
                    aria-label={'Usar ' + (item.alt || item.title)}
                    onClick={() => {
                      setSelectedPreview({ url: item.url, alt: item.alt || item.title });
                      onChange(item.id);
                      setOpen(false);
                    }}>
                    <img loading="lazy" src={item.url} alt={item.alt || item.title} />
                    <span>{item.title}</span>
                  </button>
                ))}
                {items.length === 0 && <p>Nenhuma imagem encontrada.</p>}
              </div>
            )}
            <footer>
              <button type="button" disabled={loading || page <= 1}
                onClick={() => void searchMedia(query, page - 1)}>Anterior</button>
              <span>Página {page} de {totalPages}</span>
              <button type="button" disabled={loading || page >= totalPages}
                onClick={() => void searchMedia(query, page + 1)}>Próxima</button>
            </footer>
          </div>
        </div>
      )}
    </div>
  );
}
