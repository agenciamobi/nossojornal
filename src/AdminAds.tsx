import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import './admin-ads.css';

type FormatPreset = {
  width: number;
  height: number;
  label: string;
  device: 'desktop' | 'mobile' | 'all';
};

type Advertiser = {
  id: number;
  name: string;
  slug: string;
  contactName: string;
  email: string;
  phone: string;
  websiteUrl: string;
  status: 'active' | 'inactive';
};

type Campaign = {
  id: number;
  advertiserId: number;
  advertiserName: string;
  name: string;
  status: 'draft' | 'active' | 'paused' | 'ended';
  startsAt: string | null;
  endsAt: string | null;
  priority: number;
  notes: string;
};

type AdSlotRecord = {
  id: number;
  code: string;
  name: string;
  location: string;
  description: string;
  allowedSizes: string[];
  fallbackStrategy: 'hide' | 'header_message';
  enabled: boolean;
};

type Creative = {
  id: number;
  campaignId: number;
  campaignName: string;
  name: string;
  kind: 'image' | 'html5';
  width: number;
  height: number;
  imageUrl: string;
  clickUrl: string;
  altText: string;
  html: string;
  css: string;
  status: 'draft' | 'active' | 'paused';
};

type Placement = {
  id: number;
  campaignId: number;
  campaignName: string;
  creativeId: number;
  creativeName: string;
  slotId: number;
  slotName: string;
  slotCode: string;
  device: 'all' | 'desktop' | 'mobile';
  status: 'active' | 'paused';
  startsAt: string | null;
  endsAt: string | null;
  priority: number;
};

type AdsData = {
  advertisers: Advertiser[];
  campaigns: Campaign[];
  slots: AdSlotRecord[];
  creatives: Creative[];
  placements: Placement[];
  formats: FormatPreset[];
};

type AdsPayload = {
  ok: boolean;
  data?: AdsData;
  error?: { code?: string };
};

type AdvertiserDraft = Advertiser;
type CampaignDraft = Omit<Campaign, 'advertiserName' | 'startsAt' | 'endsAt'> & {
  startsAt: string;
  endsAt: string;
};
type SlotDraft = AdSlotRecord;
type CreativeDraft = Omit<Creative, 'campaignName'>;
type PlacementDraft = Omit<
  Placement,
  'campaignName' | 'creativeName' | 'slotName' | 'slotCode' | 'startsAt' | 'endsAt'
> & {
  startsAt: string;
  endsAt: string;
};

const EMPTY_ADVERTISER: AdvertiserDraft = {
  id: 0,
  name: '',
  slug: '',
  contactName: '',
  email: '',
  phone: '',
  websiteUrl: '',
  status: 'active' as const,
};

const EMPTY_CAMPAIGN: CampaignDraft = {
  id: 0,
  advertiserId: 0,
  name: '',
  status: 'draft' as const,
  startsAt: '',
  endsAt: '',
  priority: 100,
  notes: '',
};

const EMPTY_SLOT: SlotDraft = {
  id: 0,
  code: '',
  name: '',
  location: '',
  description: '',
  allowedSizes: [] as string[],
  fallbackStrategy: 'hide' as const,
  enabled: true,
};

const EMPTY_CREATIVE: CreativeDraft = {
  id: 0,
  campaignId: 0,
  name: '',
  kind: 'image' as const,
  width: 970,
  height: 90,
  imageUrl: '',
  clickUrl: '',
  altText: '',
  html: '',
  css: '',
  status: 'draft' as const,
};

const EMPTY_PLACEMENT: PlacementDraft = {
  id: 0,
  campaignId: 0,
  creativeId: 0,
  slotId: 0,
  device: 'all' as const,
  status: 'active' as const,
  startsAt: '',
  endsAt: '',
  priority: 100,
};

function dateTimeInput(value: string | null) {
  if (!value) return '';
  return value.replace(' ', 'T').slice(0, 16);
}

function previewDocument(html: string, css: string, width: number) {
  return `<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=${width},initial-scale=1">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src https: data:; style-src 'unsafe-inline'; font-src https: data:;">
<style>
html,body{margin:0;width:100%;height:100%;overflow:hidden;background:transparent}
*,*::before,*::after{box-sizing:border-box}
${css}
</style>
</head>
<body>${html}</body>
</html>`;
}

function CreativePreview({
  creative,
}: {
  creative: Pick<Creative, 'kind' | 'width' | 'height' | 'imageUrl' | 'altText' | 'name' | 'html' | 'css'>;
}) {
  return (
    <div className="ads-preview">
      <div
        className="ads-preview__canvas"
        style={{ aspectRatio: creative.width + ' / ' + creative.height }}
      >
        {creative.kind === 'image' ? (
          creative.imageUrl ? (
            <img src={creative.imageUrl} alt={creative.altText || creative.name} />
          ) : (
            <span>Informe a URL da imagem para visualizar.</span>
          )
        ) : creative.html ? (
          <iframe
            title={'Prévia de ' + (creative.name || 'criativo')}
            sandbox=""
            srcDoc={previewDocument(creative.html, creative.css, creative.width)}
          />
        ) : (
          <span>Abra o editor visual para criar o banner HTML5.</span>
        )}
      </div>
      <small>{creative.width} × {creative.height}px</small>
    </div>
  );
}

function Html5Builder({
  creative,
  onChange,
}: {
  creative: CreativeDraft;
  onChange: (next: CreativeDraft) => void;
}) {
  const rootRef = useRef<HTMLDivElement | null>(null);
  const editorRef = useRef<{
    destroy: () => void;
    getHtml: () => string;
    getCss: () => string;
    on: (event: string, callback: () => void) => void;
    BlockManager: { add: (id: string, options: Record<string, unknown>) => void };
  } | null>(null);

  useEffect(() => {
    if (!rootRef.current || editorRef.current) return;

    let cancelled = false;

    const styleId = 'nj-grapesjs-styles';
    if (!document.getElementById(styleId)) {
      const link = document.createElement('link');
      link.id = styleId;
      link.rel = 'stylesheet';
      link.href = 'https://cdn.jsdelivr.net/npm/grapesjs@0.23.6/dist/css/grapes.min.css';
      link.crossOrigin = 'anonymous';
      document.head.appendChild(link);
    }

    const moduleUrl = 'https://cdn.jsdelivr.net/npm/grapesjs@0.23.6/+esm';

    void import(/* @vite-ignore */ moduleUrl).then((module) => {
      if (cancelled || !rootRef.current) return;

      const initialHtml = creative.html || `
        <div class="nj-ad">
          <div class="nj-ad__eyebrow">Publicidade</div>
          <h1>Sua campanha aqui</h1>
          <p>Use o editor visual para montar o banner.</p>
          <span class="nj-ad__cta">Saiba mais</span>
        </div>
      `;
      const initialCss = creative.css || `
        .nj-ad{width:100%;height:100%;padding:18px 26px;display:flex;flex-direction:column;justify-content:center;overflow:hidden;background:linear-gradient(120deg,#0A3284,#6597F8);color:white;font-family:Arial,sans-serif}
        .nj-ad__eyebrow{font-size:10px;font-weight:800;letter-spacing:.14em;text-transform:uppercase;opacity:.76}
        .nj-ad h1{margin:5px 0 3px;font-size:28px;line-height:1}
        .nj-ad p{margin:0;font-size:13px;opacity:.88}
        .nj-ad__cta{margin-top:9px;width:max-content;padding:6px 10px;border-radius:999px;background:white;color:#0A3284;font-size:11px;font-weight:800}
      `;

      const editor = module.default.init({
        container: rootRef.current,
        height: '520px',
        width: 'auto',
        storageManager: false,
        noticeOnUnload: false,
        fromElement: false,
        components: initialHtml,
        style: initialCss,
        canvas: {
          styles: [],
          scripts: [],
        },
        deviceManager: {
          devices: [
            {
              id: 'banner',
              name: creative.width + '×' + creative.height,
              width: creative.width + 'px',
              widthMedia: creative.width + 'px',
            },
          ],
        },
      });

      editor.BlockManager.add('ad-heading', {
        label: 'Título',
        category: 'Conteúdo',
        content: '<h2 style="margin:0">Título do anúncio</h2>',
      });
      editor.BlockManager.add('ad-text', {
        label: 'Texto',
        category: 'Conteúdo',
        content: '<p style="margin:0">Texto do anúncio</p>',
      });
      editor.BlockManager.add('ad-cta', {
        label: 'CTA',
        category: 'Conteúdo',
        content: '<span class="nj-ad-cta">Saiba mais</span>',
      });
      editor.BlockManager.add('ad-image', {
        label: 'Imagem',
        category: 'Mídia',
        content: { type: 'image' },
      });
      editor.BlockManager.add('ad-box', {
        label: 'Bloco',
        category: 'Layout',
        content: '<div style="padding:12px"></div>',
      });

      const sync = () => {
        onChange({
          ...creative,
          html: editor.getHtml(),
          css: editor.getCss(),
        });
      };

      editor.on('update', sync);
      editorRef.current = editor;
      sync();
    });

    return () => {
      cancelled = true;
      editorRef.current?.destroy();
      editorRef.current = null;
    };
  }, []);

  return (
    <div className="ads-builder">
      <div className="ads-builder__notice">
        <strong>HTML5 + CSS3 isolado</strong>
        <span>Animações por CSS são permitidas. JavaScript, iframes e formulários são bloqueados.</span>
      </div>
      <div className="ads-builder__editor" ref={rootRef} />
    </div>
  );
}

async function adminRequest(csrfToken: string, body?: Record<string, unknown>) {
  const response = await fetch('/api/admin/ads.php', {
    method: body ? 'POST' : 'GET',
    headers: body
      ? { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken }
      : { Accept: 'application/json' },
    body: body ? JSON.stringify(body) : undefined,
  });

  const payload = (await response.json()) as AdsPayload;
  if (!response.ok || !payload.ok || !payload.data) {
    throw new Error(payload.error?.code || 'ads_request_failed');
  }
  return payload.data;
}


type AdMediaItem = {
  id: number;
  title: string;
  mimeType: string;
  url: string;
  alt: string;
  width: number | null;
  height: number | null;
};
type AdMediaResponse = {
  ok: boolean;
  data?: { items: AdMediaItem[]; pagination: { page: number; totalPages: number; total: number } };
};
function AdMediaPicker({
  size, onSelect, onClose, csrfToken,
}: {
  csrfToken?: string;
  size: string;
  onSelect: (item: AdMediaItem) => void;
  onClose: () => void;
}) {
  const [input, setInput] = useState('');
  const [query, setQuery] = useState('');
  const [page, setPage] = useState(1);
  const [items, setItems] = useState<AdMediaItem[]>([]);
  const [totalPages, setTotalPages] = useState(1);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [uploading, setUploading] = useState(false);
  const [uploadError, setUploadError] = useState('');

  useEffect(() => {
    const controller = new AbortController();
    setState('loading');
    const params = new URLSearchParams({ page: String(page), per_page: '24', q: query });
    void fetch('/api/admin/media.php?' + params.toString(), {
      headers: { Accept: 'application/json' }, signal: controller.signal,
    }).then(async (response) => {
      if (!response.ok) throw new Error('media_http_' + response.status);
      return response.json() as Promise<AdMediaResponse>;
    }).then((payload) => {
      if (!payload.ok || !payload.data) throw new Error('media_invalid');
      setItems(payload.data.items.filter((item) => item.mimeType.startsWith('image/')));
      setTotalPages(payload.data.pagination.totalPages);
      setState('ready');
    }).catch((error) => {
      if (error instanceof DOMException && error.name === 'AbortError') return;
      setState('error');
    });
    return () => controller.abort();
  }, [page, query]);

  return (
    <section className="ads-media-picker" aria-label="Selecionar imagem da biblioteca">
      <div className="ads-media-picker__head">
        <div><strong>Biblioteca de Mídias</strong><small>Selecione uma imagem. Formato desejado: {size}</small></div>
        <button type="button" onClick={onClose}>Fechar</button>
      </div>
      <div className="ads-media-picker__search">
        <input value={input} placeholder="Pesquisar imagens" aria-label="Pesquisar imagens"
          onChange={(event) => setInput(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === 'Enter') { event.preventDefault(); setPage(1); setQuery(input.trim()); }
          }} />
        <button type="button" onClick={() => { setPage(1); setQuery(input.trim()); }}>Buscar</button>
      </div>
      {csrfToken && (
        <label className="ads-media-picker__upload">
          <span>{uploading ? 'Enviando imagem…' : '+ Enviar nova imagem (JPG, PNG, WebP ou GIF)'}</span>
          <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" disabled={uploading}
            onChange={(event) => {
              const file = event.currentTarget.files?.[0];
              if (!file) return;
              if (file.size > 12 * 1024 * 1024) {
                setUploadError('A imagem deve ter até 12 MB.');
                event.currentTarget.value = '';
                return;
              }
              const upload = new FormData();
              upload.append('file', file);
              setUploadError('');
              setUploading(true);
              void fetch('/api/admin/media-upload.php', {
                method: 'POST', headers: { 'X-CSRF-Token': csrfToken }, body: upload,
              }).then(async (response) => {
                const payload = await response.json() as {
                  ok: boolean; data?: { media: AdMediaItem }; error?: { code?: string };
                };
                if (!response.ok || !payload.ok || !payload.data?.media) {
                  throw new Error(payload.error?.code ?? 'media_upload_failed');
                }
                onSelect(payload.data.media);
              }).catch((reason) => {
                setUploadError('Não foi possível enviar a imagem: '
                  + (reason instanceof Error ? reason.message : 'media_upload_failed'));
              }).finally(() => setUploading(false));
              event.currentTarget.value = '';
            }} />
        </label>
      )}
      {uploadError && <p role="alert">{uploadError}</p>}
      {state === 'loading' && <p role="status">Carregando imagens…</p>}
      {state === 'error' && <p role="alert">Não foi possível consultar a biblioteca. Feche e abra novamente.</p>}
      {state === 'ready' && (
        <>
          {items.length === 0 && <p>Nenhuma imagem nesta página. Tente outra busca.</p>}
          <div className="ads-media-picker__grid">
            {items.map((item) => (
              <button type="button" key={item.id} onClick={() => onSelect(item)} title={'Usar ' + item.title}>
                <img loading="lazy" src={item.url} alt={item.alt || item.title} />
                <strong>{item.title}</strong>
                <span>{item.width && item.height ? item.width + '×' + item.height : 'Dimensões não disponíveis'}</span>
              </button>
            ))}
          </div>
          <div className="ads-media-picker__pagination">
            <button type="button" disabled={page <= 1} onClick={() => setPage((n) => n - 1)}>Anterior</button>
            <span>Página {page} de {totalPages}</span>
            <button type="button" disabled={page >= totalPages} onClick={() => setPage((n) => n + 1)}>Próxima</button>
          </div>
        </>
      )}
    </section>
  );
}

type QuickBannerDraft = {
  advertiserId: number;
  newAdvertiserName: string;
  name: string;
  media: AdMediaItem | null;
  slotId: number;
  device: 'desktop' | 'mobile' | 'all';
  size: string;
  clickUrl: string;
  startsAt: string;
  endsAt: string;
  priority: number;
};

const quickAdError: Record<string, string> = {
  quick_banner_required_fields: 'Informe o anunciante, o título, a imagem e o local do anúncio.',
  quick_banner_invalid_format_device: 'O formato escolhido não é compatível com o dispositivo.',
  quick_banner_slot_incompatible: 'O espaço foi alterado. Selecione outro formato ou local.',
  quick_banner_advertiser_inactive: 'Esse anunciante está inativo. Ative-o em Configurações avançadas ou escolha outro.',
  quick_banner_image_required: 'A mídia escolhida não é uma imagem válida da Biblioteca.',
  quick_banner_image_unavailable: 'Não foi possível localizar o arquivo original da imagem selecionada.',
  quick_banner_image_ratio: 'A proporção da imagem difere muito do espaço. Envie um banner no formato indicado.',
  advertiser_already_exists: 'Esse anunciante já existe. Escolha-o na lista.',
  invalid_ad_url: 'O endereço de destino deve começar com https:// ou http:// e ser válido.',
  invalid_campaign_window: 'A data final deve ser posterior à data inicial.',
};

function QuickBannerForm({
  csrfToken, data, onSaved, onAdvanced,
}: {
  csrfToken: string;
  data: AdsData;
  onSaved: (snapshot: AdsData) => void;
  onAdvanced: () => void;
}) {
  const defaultAdvertiser = data.advertisers.find((item) => item.status === 'active')?.id ?? -1;
  const defaultSlot = data.slots.find((item) => item.enabled && item.code === 'header')?.id
    ?? data.slots.find((item) => item.enabled)?.id ?? 0;
  const [draft, setDraft] = useState<QuickBannerDraft>({
    advertiserId: defaultAdvertiser,
    newAdvertiserName: '', name: '', media: null,
    slotId: defaultSlot, device: 'desktop', size: '468x60',
    clickUrl: '', startsAt: '', endsAt: '', priority: 100,
  });
  // Keep the compact masthead format selected when changing placement/device.
  const preferredFormat = (slot: AdSlotRecord | undefined, device: QuickBannerDraft['device']) => {
    const matches = data.formats.filter((item) =>
      slot?.allowedSizes.includes(item.width + 'x' + item.height)
      && (item.device === 'all' || item.device === device));
    const preferred = slot?.code === 'header'
      ? device === 'mobile' ? '300x100' : '468x60'
      : '';
    const found = matches.find((item) => item.width + 'x' + item.height === preferred) ?? matches[0];
    return found ? found.width + 'x' + found.height : '';
  };
  const [pickerOpen, setPickerOpen] = useState(false);
  const [advancedOpen, setAdvancedOpen] = useState(false);
  const [status, setStatus] = useState<'idle' | 'saving' | 'success' | 'error'>('idle');
  const [error, setError] = useState('');
  const [updatingPlacement, setUpdatingPlacement] = useState<number | null>(null);
  const selectedSlot = data.slots.find((item) => item.id === draft.slotId);
  const allowedFormats = data.formats.filter((format) =>
    selectedSlot?.allowedSizes.includes(format.width + 'x' + format.height)
      && (format.device === 'all' || format.device === draft.device),
  );
  const validSize = allowedFormats.some((item) => item.width + 'x' + item.height === draft.size);
  const selectedSize = validSize ? draft.size : (allowedFormats[0] ? allowedFormats[0].width + 'x' + allowedFormats[0].height : '');
  const [width, height] = selectedSize.split('x').map(Number);
  const sourceAspect = draft.media?.width && draft.media.height
    ? draft.media.width / draft.media.height : null;
  const targetAspect = width > 0 && height > 0 ? width / height : null;
  const aspectGap = sourceAspect && targetAspect
    ? Math.max(sourceAspect / targetAspect, targetAspect / sourceAspect) : 1;
  const severeRatioMismatch = aspectGap > 3;
  const moderateRatioMismatch = aspectGap > 1.35;
  const issues = [
    !selectedSlot && 'Não existe posição habilitada.',
    allowedFormats.length === 0 && 'Esta posição não aceita formatos para o dispositivo escolhido.',
    !draft.name.trim() && 'Informe o título do anúncio.',
    draft.advertiserId === -1 && !draft.newAdvertiserName.trim() && 'Informe o nome do anunciante.',
    !draft.media && 'Selecione uma imagem da Biblioteca de Mídias.',
    severeRatioMismatch && 'Essa imagem tem proporção muito diferente. Escolha uma arte horizontal próxima de ' + selectedSize + ' para que o banner fique visível.',
  ].filter((item): item is string => typeof item === 'string');
  const activeAds = data.placements.map((item) => {
    const campaign = data.campaigns.find((c) => c.id === item.campaignId);
    const creative = data.creatives.find((c) => c.id === item.creativeId);
    const advertiser = data.advertisers.find((a) => a.id === campaign?.advertiserId);
    const slot = data.slots.find((record) => record.id === item.slotId);
    const now = Date.now();
    const inTime = (from: string | null, to: string | null) =>
      (!from || new Date(from.replace(' ', 'T')).getTime() <= now)
        && (!to || new Date(to.replace(' ', 'T')).getTime() >= now);
    const eligible = item.status === 'active' && campaign?.status === 'active'
      && creative?.status === 'active' && advertiser?.status === 'active'
      && slot?.enabled && inTime(campaign.startsAt, campaign.endsAt)
      && inTime(item.startsAt, item.endsAt)
      && slot.allowedSizes.includes((creative?.width ?? 0) + 'x' + (creative?.height ?? 0));
    return { ...item, eligible: Boolean(eligible), advertiser: advertiser?.name || 'Anunciante indisponível' };
  });

  const competingTests = activeAds.filter((ad) =>
    ad.eligible && ad.slotId === draft.slotId
    && (ad.device === 'all' || draft.device === 'all' || ad.device === draft.device)
    && ad.creativeName.toLocaleLowerCase('pt-BR').includes('teste') && ad.priority >= 500
  );
  async function togglePlacement(ad: typeof activeAds[number]) {
    if (updatingPlacement !== null) return;
    setUpdatingPlacement(ad.id);
    try {
      const snapshot = await adminRequest(csrfToken, {
        entity: 'placement', id: ad.id, campaignId: ad.campaignId,
        creativeId: ad.creativeId, slotId: ad.slotId, device: ad.device,
        status: ad.status === 'active' ? 'paused' : 'active',
        priority: ad.priority, startsAt: ad.startsAt || '', endsAt: ad.endsAt || '',
      });
      onSaved(snapshot);
    } catch (reason) {
      setError('Não foi possível mudar essa veiculação: '
        + (reason instanceof Error ? reason.message : 'ads_update_failed'));
      setStatus('error');
    } finally {
      setUpdatingPlacement(null);
    }
  }

  async function publish(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (issues.length || !selectedSize || status === 'saving') return;
    setStatus('saving');
    setError('');
    try {
      const snapshot = await adminRequest(csrfToken, {
        entity: 'quick_banner',
        advertiserId: draft.advertiserId > 0 ? draft.advertiserId : 0,
        newAdvertiserName: draft.advertiserId === -1 ? draft.newAdvertiserName.trim() : '',
        name: draft.name.trim(), mediaId: draft.media?.id,
        slotId: draft.slotId, device: draft.device,
        width, height, clickUrl: draft.clickUrl.trim(),
        startsAt: draft.startsAt, endsAt: draft.endsAt, priority: draft.priority,
      });
      onSaved(snapshot);
      const newAdvertiser = snapshot.advertisers.find((item) => item.name === draft.newAdvertiserName.trim());
      setDraft((current) => ({
        ...current,
        advertiserId: current.advertiserId === -1 ? (newAdvertiser?.id ?? -1) : current.advertiserId,
        newAdvertiserName: '', name: '', media: null, clickUrl: '',
      }));
      setPickerOpen(false);
      setStatus('success');
    } catch (reason) {
      const code = reason instanceof Error ? reason.message : 'ads_save_failed';
      setError(quickAdError[code] ?? 'Não foi possível publicar. Código: ' + code);
      setStatus('error');
    }
  }

  return (
    <div className="ads-quick">
      <header className="ads-quick__intro">
        <div><strong>Publicação rápida</strong><p>Um formulário para criar o anunciante, a campanha e a veiculação automaticamente.</p></div>
        <a href="/" target="_blank" rel="noreferrer">Ver o portal ↗</a>
      </header>
      <form className="ads-quick__form" onSubmit={(event) => void publish(event)}>
        <div className="ads-quick__fields">
          <label className="admin-editor-field"><span>1. Anunciante</span>
            <select value={draft.advertiserId} onChange={(event) => setDraft({ ...draft, advertiserId: Number(event.target.value) })}>
              {data.advertisers.filter((item) => item.status === 'active').map((item) => (
                <option value={item.id} key={item.id}>{item.name}</option>
              ))}
              <option value={-1}>+ Novo anunciante</option>
            </select>
          </label>
          {draft.advertiserId === -1 && (
            <label className="admin-editor-field"><span>Nome do novo anunciante</span>
              <input value={draft.newAdvertiserName} maxLength={190} required
                onChange={(event) => setDraft({ ...draft, newAdvertiserName: event.target.value })} />
            </label>
          )}
          <label className="admin-editor-field"><span>2. Nome da campanha / banner</span>
            <input value={draft.name} maxLength={190} required placeholder="Ex.: Promoção de outubro"
              onChange={(event) => setDraft({ ...draft, name: event.target.value })} />
          </label>
          <div className="admin-editor-field">
            <span>3. Imagem</span>
            {draft.media ? (
              <div className="ads-quick__media">
                <img src={draft.media.url} alt={draft.media.alt || draft.media.title} />
                <div><strong>{draft.media.title}</strong><small>{draft.media.width && draft.media.height
                  ? draft.media.width + '×' + draft.media.height + ' pixels' : 'Dimensões não informadas'}</small>
                  <button type="button" onClick={() => setPickerOpen(true)}>Trocar imagem</button>
                </div>
              </div>
            ) : (
              <button className="ads-quick__media-button" type="button"
                onClick={() => setPickerOpen(true)}>Selecionar ou enviar imagem</button>
            )}
            {pickerOpen && <AdMediaPicker size={selectedSize || 'a definir'} csrfToken={csrfToken}
              onClose={() => setPickerOpen(false)} onSelect={(item) => {
                setDraft((current) => ({ ...current, media: item }));
                setPickerOpen(false);
              }} />}
          </div>
          <div className="ads-field-grid">
            <label className="admin-editor-field"><span>4. Onde aparece?</span>
              <select value={draft.slotId} onChange={(event) => {
                const id = Number(event.target.value);
                const next = data.slots.find((item) => item.id === id);
                setDraft({ ...draft, slotId: id, size: preferredFormat(next, draft.device) });
              }}>
                {data.slots.filter((item) => item.enabled).map((item) => (
                  <option key={item.id} value={item.id}>{item.name}</option>
                ))}
              </select>
            </label>
            <label className="admin-editor-field"><span>Dispositivo</span>
              <select value={draft.device} onChange={(event) => {
                const device = event.target.value as QuickBannerDraft['device'];
                setDraft({ ...draft, device, size: preferredFormat(selectedSlot, device) });
              }}>
                <option value="desktop">Computador</option>
                <option value="mobile">Celular</option>
                <option value="all">Ambos (formato responsivo)</option>
              </select>
            </label>
          </div>
          <label className="admin-editor-field"><span>Formato</span>
            <select value={selectedSize} disabled={!allowedFormats.length}
              onChange={(event) => setDraft({ ...draft, size: event.target.value })}>
              {allowedFormats.map((item) => (
                <option key={item.width + 'x' + item.height} value={item.width + 'x' + item.height}>
                  {item.width}×{item.height} · {item.label}
                </option>
              ))}
            </select>
          </label>
          {selectedSlot?.code === 'header' && draft.device === 'desktop' && (
            <p className="ads-quick__help">
              Para o cabeçalho, envie a arte pronta em 468×60 pixels, sem margens ou
              fundo branco externos. Outros formatos devem usar posições próprias.
            </p>
          )}
          <label className="admin-editor-field"><span>5. Link ao clicar (opcional)</span>
            <input type="url" placeholder="https://site-do-anunciante.com.br" value={draft.clickUrl}
              onChange={(event) => setDraft({ ...draft, clickUrl: event.target.value })} />
          </label>
          <details className="ads-quick__advanced" open={advancedOpen} onToggle={(event) => setAdvancedOpen(event.currentTarget.open)}>
            <summary>Agendamento e prioridade (opcional)</summary>
            <div className="ads-field-grid">
              <label className="admin-editor-field"><span>Início</span>
                <input type="datetime-local" value={draft.startsAt}
                  onChange={(event) => setDraft({ ...draft, startsAt: event.target.value })} /></label>
              <label className="admin-editor-field"><span>Fim</span>
                <input type="datetime-local" value={draft.endsAt}
                  onChange={(event) => setDraft({ ...draft, endsAt: event.target.value })} /></label>
            </div>
            <label className="admin-editor-field"><span>Prioridade (padrão: 100)</span>
              <input type="number" min={0} max={1000} value={draft.priority}
                onChange={(event) => setDraft({ ...draft, priority: Number(event.target.value) })} />
            </label>
          </details>
          {draft.media && moderateRatioMismatch && (
            <p className="ads-quick__help" role="alert">
              A imagem escolhida tem {draft.media.width}×{draft.media.height} pixels,
              mas este banner tem {selectedSize}. {severeRatioMismatch
                ? 'Ela ficaria pequena e com grandes áreas vazias. Escolha ou envie uma arte na proporção indicada.'
                : 'Ela será exibida inteira, podendo deixar pequenas bordas vazias.'}
            </p>
          )}
          {competingTests.length > 0 && (
            <p className="ads-quick__help">Há {competingTests.length} banner(s) de teste com prioridade alta
              neste espaço. Eles podem aparecer com mais frequência que o novo banner.
              É possível pausá-los em “Banners cadastrados”, logo abaixo.</p>
          )}
          {issues.length > 0 && <p className="ads-quick__help">{issues[0]}</p>}
          {status === 'error' && <p role="alert" className="ads-quick__error">{error}</p>}
          {status === 'success' && <p role="status" className="ads-quick__success">
            Banner criado e ativado. Ele passa a concorrer pela exibição conforme prioridade e dispositivo.
            {draft.startsAt ? ' Respeitando o início agendado.' : ''}
          </p>}
          <button className="admin-button--primary ads-quick__submit" type="submit"
            disabled={issues.length > 0 || !selectedSize || status === 'saving'}>
            {status === 'saving' ? 'Publicando…' : 'Publicar banner'}
          </button>
        </div>
        <aside className="ads-quick__preview">
          <span>Prévia do espaço · {selectedSlot?.name ?? 'Selecione um espaço'}</span>
          {draft.media && selectedSize ? (
            <div className="ads-quick__preview-image" style={{ aspectRatio: width + '/' + height }}>
              <img src={draft.media.url} alt={draft.media.alt || draft.name || 'Imagem selecionada'} />
            </div>
          ) : <div className="ads-quick__placeholder">Selecione a imagem para visualizar o banner.</div>}
          <small>{selectedSize || 'Sem formato'} · {draft.device === 'desktop' ? 'Computador'
            : draft.device === 'mobile' ? 'Celular' : 'Ambos'}.
            A imagem será ajustada sem deformação.</small>
        </aside>
      </form>
      <section className="ads-quick__registered">
        <header><div><h2>Banners cadastrados</h2><p>Veja onde estão e se atendem às condições de exibição.</p></div>
          <button type="button" onClick={onAdvanced}>Gerenciar veiculações</button>
        </header>
        {activeAds.length === 0 ? <p>Nenhuma veiculação ainda. Publique o primeiro banner acima.</p> : (
          <div className="ads-quick__records">
            {activeAds.map((ad) => <div key={ad.id}>
              <div><strong>{ad.creativeName}</strong>
                <span>{ad.advertiser} · {ad.slotName} · {ad.device} · Prioridade {ad.priority}</span>
              </div>
              <div className="ads-quick__record-actions">
                <small className={ad.eligible ? 'is-ready' : ''}>
                  {ad.status === 'paused' ? 'Pausado' : ad.eligible ? 'Elegível pela configuração' : 'Verificar configuração'}
                </small>
                <button type="button" disabled={updatingPlacement !== null}
                  onClick={() => void togglePlacement(ad)}>
                  {updatingPlacement === ad.id ? 'Salvando…' : ad.status === 'active' ? 'Pausar' : 'Ativar'}
                </button>
              </div>
            </div>)}
          </div>
        )}
      </section>
    </div>
  );
}

type AdReportRow = { impressions: number; clicks: number };
type AdReportData = {
  from: string; to: string;
  summary: AdReportRow;
  advertisers: Array<AdReportRow & { id: number; name: string }>;
  campaigns: Array<AdReportRow & { id: number; name: string; status: string; advertiserName: string }>;
  slots: Array<AdReportRow & { code: string; name: string }>;
  daily: Array<AdReportRow & { day: string }>;
};
type AdReportResponse = { ok: boolean; data?: AdReportData; error?: { code?: string } };
const countAds = (value: number) => new Intl.NumberFormat('pt-BR').format(value);
const ctrAds = ({ impressions, clicks }: AdReportRow) => (
  impressions ? (100 * clicks / impressions).toLocaleString('pt-BR', { maximumFractionDigits: 2 }) + '%' : '0%'
);
function localISODate(dayOffset: number) {
  const day = new Date();
  day.setDate(day.getDate() + dayOffset);
  const parts = [day.getFullYear(), String(day.getMonth() + 1).padStart(2, '0'), String(day.getDate()).padStart(2, '0')];
  return parts.join('-');
}
function AdsReports() {
  const [draft, setDraft] = useState({ from: localISODate(-29), to: localISODate(0) });
  const [range, setRange] = useState(draft);
  const [data, setData] = useState<AdReportData | null>(null);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [error, setError] = useState('');
  useEffect(() => {
    const controller = new AbortController();
    setState('loading');
    const params = new URLSearchParams(range);
    void fetch('/api/admin/ads-report.php?' + params.toString(), {
      headers: { Accept: 'application/json' }, signal: controller.signal,
    }).then(async (response) => {
      const payload = await response.json() as AdReportResponse;
      if (!response.ok || !payload.ok || !payload.data) throw new Error(payload.error?.code || 'report_unavailable');
      return payload.data;
    }).then((report) => {
      setData(report);
      setState('ready');
    }).catch((reason) => {
      if (reason instanceof DOMException && reason.name === 'AbortError') return;
      setError(reason instanceof Error ? reason.message : 'report_unavailable');
      setState('error');
    });
    return () => controller.abort();
  }, [range]);
  const maxImpressions = Math.max(1, ...(data?.daily.map((row) => row.impressions) ?? []));
  return (
    <section className="ads-report" aria-label="Relatório de publicidade">
      <form className="ads-report__filters" onSubmit={(event) => { event.preventDefault(); setRange({ ...draft }); }}>
        <label>De <input type="date" value={draft.from} max={draft.to} onChange={(event) => setDraft({ ...draft, from: event.target.value })} /></label>
        <label>Até <input type="date" value={draft.to} min={draft.from} onChange={(event) => setDraft({ ...draft, to: event.target.value })} /></label>
        <button type="submit" disabled={!draft.from || !draft.to || draft.from > draft.to}>Atualizar relatório</button>
        <span>Até 92 dias por consulta</span>
      </form>
      {state === 'loading' && <p role="status">Carregando dados reais de veiculação…</p>}
      {state === 'error' && <p role="alert">Relatório indisponível: {error}. Verifique a migração ads-v2.</p>}
      {state === 'ready' && data && (
        <>
          <p className="ads-report__note">Impressões contabilizadas após visibilidade na tela; cliques únicos por exibição. Não inclui dados anteriores à ativação deste recurso.</p>
          <div className="ads-report__kpis">
            <article><strong>{countAds(data.summary.impressions)}</strong><span>Impressões visíveis</span></article>
            <article><strong>{countAds(data.summary.clicks)}</strong><span>Cliques registrados</span></article>
            <article><strong>{ctrAds(data.summary)}</strong><span>Taxa de cliques (CTR)</span></article>
          </div>
          <h2>Desempenho por anunciante</h2>
          <div className="ads-report__scroll"><table><thead><tr><th>Anunciante</th><th>Impressões</th><th>Cliques</th><th>CTR</th></tr></thead><tbody>
            {data.advertisers.map((row) => <tr key={row.id}><th>{row.name}</th><td>{countAds(row.impressions)}</td><td>{countAds(row.clicks)}</td><td>{ctrAds(row)}</td></tr>)}
          </tbody></table></div>
          <h2>Campanhas</h2>
          <div className="ads-report__scroll"><table><thead><tr><th>Campanha</th><th>Anunciante</th><th>Impressões</th><th>Cliques</th><th>CTR</th></tr></thead><tbody>
            {data.campaigns.map((row) => <tr key={row.id}><th>{row.name}</th><td>{row.advertiserName}</td><td>{countAds(row.impressions)}</td><td>{countAds(row.clicks)}</td><td>{ctrAds(row)}</td></tr>)}
          </tbody></table></div>
          <h2>Posições</h2>
          <div className="ads-report__scroll"><table><thead><tr><th>Posição</th><th>Impressões</th><th>Cliques</th><th>CTR</th></tr></thead><tbody>
            {data.slots.map((row) => <tr key={row.code}><th>{row.name}</th><td>{countAds(row.impressions)}</td><td>{countAds(row.clicks)}</td><td>{ctrAds(row)}</td></tr>)}
          </tbody></table></div>
          <h2>Evolução diária</h2>
          {data.daily.length === 0 ? <p>Sem anúncios visualizados neste período.</p> : (
            <div className="ads-report__timeline">
              {data.daily.map((row) => <div key={row.day} className="ads-report__day">
                <span>{row.day.split('-').reverse().join('/')}</span>
                <div><i style={{ width: (row.impressions * 100 / maxImpressions) + '%' }} /></div>
                <strong>{countAds(row.impressions)}</strong>
                <small>{countAds(row.clicks)} cliques</small>
              </div>)}
            </div>
          )}
        </>
      )}
    </section>
  );
}

export function AdminAds({ csrfToken }: { csrfToken: string }) {
  const [data, setData] = useState<AdsData | null>(null);
  const [tab, setTab] = useState<'quick' | 'advertisers' | 'campaigns' | 'creatives' | 'slots' | 'placements' | 'reports'>('quick');
  const [state, setState] = useState<'loading' | 'idle' | 'saving' | 'error'>('loading');
  const [errorCode, setErrorCode] = useState('');
  const [builderOpen, setBuilderOpen] = useState(false);
  const [mediaOpen, setMediaOpen] = useState(false);

  const [advertiser, setAdvertiser] = useState<AdvertiserDraft>({ ...EMPTY_ADVERTISER });
  const [campaign, setCampaign] = useState<CampaignDraft>({ ...EMPTY_CAMPAIGN });
  const [slot, setSlot] = useState<SlotDraft>({ ...EMPTY_SLOT });
  const [creative, setCreative] = useState<CreativeDraft>({ ...EMPTY_CREATIVE });
  const [placement, setPlacement] = useState<PlacementDraft>({ ...EMPTY_PLACEMENT });

  useEffect(() => {
    void adminRequest(csrfToken)
      .then((snapshot) => {
        setData(snapshot);
        setState('idle');
      })
      .catch((error) => {
        setErrorCode(error instanceof Error ? error.message : 'ads_load_failed');
        setState('error');
      });
  }, [csrfToken]);

  const activeCampaignCreatives = useMemo(
    () => data?.creatives.filter((item) => item.campaignId === placement.campaignId) ?? [],
    [data, placement.campaignId],
  );
  const selectedCreative = activeCampaignCreatives.find((item) => item.id === placement.creativeId);
  const selectedCampaign = data?.campaigns.find((item) => item.id === placement.campaignId);
  const selectedAdvertiser = data?.advertisers.find((item) => item.id === selectedCampaign?.advertiserId);
  const selectedSlot = data?.slots.find((item) => item.id === placement.slotId);
  const creativeSize = selectedCreative ? selectedCreative.width + 'x' + selectedCreative.height : '';
  const compatibleSlots = (data?.slots ?? []).filter(
    (item) => item.enabled && creativeSize !== '' && item.allowedSizes.includes(creativeSize),
  );
  const canSavePlacement = Boolean(
    selectedCampaign && selectedCreative && selectedSlot?.enabled
      && selectedCreative.campaignId === selectedCampaign.id
      && selectedSlot.allowedSizes.includes(creativeSize) && state !== 'saving',
  );

  // An active database record is not necessarily an ad currently eligible for delivery.
  // Report scheduling and parent-status problems before an administrator activates it.
  const deliveryIssues: string[] = [];
  if (placement.campaignId && selectedCampaign) {
    if (selectedCampaign.status !== 'active') deliveryIssues.push('Ative a campanha para entregar o anúncio.');
    if (selectedAdvertiser?.status !== 'active') deliveryIssues.push('O anunciante precisa estar ativo.');
  }
  if (selectedCreative?.status !== 'active') {
    if (selectedCreative) deliveryIssues.push('O criativo ainda não está ativo.');
  }
  if (selectedSlot && !selectedSlot.enabled) deliveryIssues.push('A posição está desabilitada.');
  if (selectedCreative && selectedSlot && !selectedSlot.allowedSizes.includes(creativeSize)) {
    deliveryIssues.push('O formato do criativo não é aceito nesta posição.');
  }
  if (placement.status === 'paused') deliveryIssues.push('Esta veiculação está pausada.');
  const now = Date.now();
  for (const [start, end] of [
    [selectedCampaign?.startsAt, selectedCampaign?.endsAt],
    [placement.startsAt, placement.endsAt],
  ]) {
    const startTime = start ? new Date(start.replace(' ', 'T')).getTime() : NaN;
    const endTime = end ? new Date(end.replace(' ', 'T')).getTime() : NaN;
    if (Number.isFinite(startTime) && startTime > now) {
      deliveryIssues.push('O período de exibição ainda não começou.');
    }
    if (Number.isFinite(endTime) && endTime < now) {
      deliveryIssues.push('O período de exibição já terminou.');
    }
  }

  async function save(body: Record<string, unknown>, reset: () => void) {
    setState('saving');
    setErrorCode('');

    try {
      const snapshot = await adminRequest(csrfToken, body);
      setData(snapshot);
      reset();
      setBuilderOpen(false);
      setMediaOpen(false);
      setState('idle');
    } catch (error) {
      setErrorCode(error instanceof Error ? error.message : 'ads_save_failed');
      setState('error');
    }
  }

  if (state === 'loading') {
    return <div className="admin-loading" aria-busy="true"><span /><span /><span /></div>;
  }

  if (!data) {
    return (
      <div className="admin-save-feedback admin-save-feedback--error" role="alert">
        Não foi possível carregar o gerenciador de publicidade ({errorCode || 'ads_load_failed'}).
        <button type="button" onClick={() => {
          setState('loading');
          setErrorCode('');
          void adminRequest(csrfToken).then((snapshot) => {
            setData(snapshot);
            setState('idle');
          }).catch((error) => {
            setErrorCode(error instanceof Error ? error.message : 'ads_load_failed');
            setState('error');
          });
        }}>Tentar novamente</button>
      </div>
    );
  }

  return (
    <div className="ads-admin">
      <header className="admin-page-header">
        <span>Receita</span>
        <h1>Publicidade</h1>
        <p>Gerencie anunciantes, campanhas, posições e criativos próprios do portal.</p>
      </header>

      <div className="ads-summary">
        <article><strong>{data.advertisers.length}</strong><span>Anunciantes</span></article>
        <article><strong>{data.campaigns.filter((item) => item.status === 'active').length}</strong><span>Campanhas ativas</span></article>
        <article><strong>{data.creatives.length}</strong><span>Criativos</span></article>
        <article><strong>{data.placements.length}</strong><span>Veiculações cadastradas</span></article>
      </div>

      {state === 'error' && (
        <div className="admin-save-feedback admin-save-feedback--error" role="alert">
          Não foi possível concluir a operação. Código: {errorCode || 'ads_error'}.
        </div>
      )}

      <nav className="ads-tabs" aria-label="Áreas principais">
        <button type="button" className={tab === 'quick' ? 'is-active' : ''} onClick={() => setTab('quick')}>Publicar banner</button>
        <button type="button" className={tab === 'reports' ? 'is-active' : ''} onClick={() => setTab('reports')}>Relatórios</button>
      </nav>
      <details className="ads-advanced-navigation" id="ads-advanced-navigation">
        <summary>Configurações avançadas e gestão</summary>
      <nav className="ads-tabs" aria-label="Áreas de publicidade">
        {([
          ['advertisers', 'Anunciantes'],
          ['campaigns', 'Campanhas'],
          ['creatives', 'Criativos'],
          ['slots', 'Posições'],
          ['placements', 'Veiculação'],
        ] as const).map(([key, label]) => (
          <button
            type="button"
            className={tab === key ? 'is-active' : ''}
            onClick={() => setTab(key)}
            key={key}
          >
            {label}
          </button>
        ))}
      </nav>
      </details>

      {tab === 'quick' && <QuickBannerForm csrfToken={csrfToken} data={data}
        onSaved={setData} onAdvanced={() => {
          setTab('placements');
          const node = document.querySelector<HTMLDetailsElement>('#ads-advanced-navigation');
          if (node) node.open = true;
        }} />}

      {tab === 'advertisers' && (
        <div className="ads-workspace">
          <section className="admin-editor-card">
            <div className="admin-editor-card__head"><span>Cadastro</span><strong>Anunciante</strong></div>
            <div className="admin-editor-card__body admin-editor-card__body--fields">
              <label className="admin-editor-field"><span>Nome</span><input value={advertiser.name} onChange={(e) => setAdvertiser({ ...advertiser, name: e.target.value })} /></label>
              <label className="admin-editor-field"><span>Contato</span><input value={advertiser.contactName} onChange={(e) => setAdvertiser({ ...advertiser, contactName: e.target.value })} /></label>
              <label className="admin-editor-field"><span>E-mail</span><input type="email" value={advertiser.email} onChange={(e) => setAdvertiser({ ...advertiser, email: e.target.value })} /></label>
              <label className="admin-editor-field"><span>Telefone</span><input value={advertiser.phone} onChange={(e) => setAdvertiser({ ...advertiser, phone: e.target.value })} /></label>
              <label className="admin-editor-field"><span>Site</span><input type="url" placeholder="https://" value={advertiser.websiteUrl} onChange={(e) => setAdvertiser({ ...advertiser, websiteUrl: e.target.value })} /></label>
              <label className="admin-editor-field"><span>Status</span><select value={advertiser.status} onChange={(e) => setAdvertiser({ ...advertiser, status: e.target.value as Advertiser['status'] })}><option value="active">Ativo</option><option value="inactive">Inativo</option></select></label>
              <button className="admin-button--primary" type="button" disabled={!advertiser.name.trim() || state === 'saving'} onClick={() => void save({ entity: 'advertiser', ...advertiser }, () => setAdvertiser({ ...EMPTY_ADVERTISER }))}>
                {state === 'saving' ? 'Salvando…' : advertiser.id ? 'Salvar anunciante' : 'Cadastrar anunciante'}
              </button>
            </div>
          </section>

          <section className="ads-list">
            {data.advertisers.map((item) => (
              <button type="button" className="ads-list__item" key={item.id} onClick={() => setAdvertiser({ ...item })}>
                <div><strong>{item.name}</strong><span>{item.contactName || item.email || 'Sem contato informado'}</span></div>
                <small>{item.status === 'active' ? 'Ativo' : 'Inativo'}</small>
              </button>
            ))}
          </section>
        </div>
      )}

      {tab === 'campaigns' && (
        <div className="ads-workspace">
          <section className="admin-editor-card">
            <div className="admin-editor-card__head"><span>Planejamento</span><strong>Campanha</strong></div>
            <div className="admin-editor-card__body admin-editor-card__body--fields">
              <label className="admin-editor-field"><span>Anunciante</span><select value={campaign.advertiserId} onChange={(e) => setCampaign({ ...campaign, advertiserId: Number(e.target.value) })}><option value={0}>Selecione</option>{data.advertisers.filter((item) => item.status === 'active').map((item) => <option value={item.id} key={item.id}>{item.name}</option>)}</select></label>
              <label className="admin-editor-field"><span>Nome</span><input value={campaign.name} onChange={(e) => setCampaign({ ...campaign, name: e.target.value })} /></label>
              <div className="ads-field-grid">
                <label className="admin-editor-field"><span>Início</span><input type="datetime-local" value={campaign.startsAt} onChange={(e) => setCampaign({ ...campaign, startsAt: e.target.value })} /></label>
                <label className="admin-editor-field"><span>Fim</span><input type="datetime-local" value={campaign.endsAt} onChange={(e) => setCampaign({ ...campaign, endsAt: e.target.value })} /></label>
              </div>
              <div className="ads-field-grid">
                <label className="admin-editor-field"><span>Status</span><select value={campaign.status} onChange={(e) => setCampaign({ ...campaign, status: e.target.value as Campaign['status'] })}><option value="draft">Rascunho</option><option value="active">Ativa</option><option value="paused">Pausada</option><option value="ended">Encerrada</option></select></label>
                <label className="admin-editor-field"><span>Prioridade</span><input type="number" min="0" max="1000" value={campaign.priority} onChange={(e) => setCampaign({ ...campaign, priority: Number(e.target.value) })} /></label>
              </div>
              <label className="admin-editor-field"><span>Observações</span><textarea rows={5} value={campaign.notes} onChange={(e) => setCampaign({ ...campaign, notes: e.target.value })} /></label>
              <button className="admin-button--primary" type="button" disabled={!campaign.name.trim() || !campaign.advertiserId || state === 'saving'} onClick={() => void save({ entity: 'campaign', ...campaign }, () => setCampaign({ ...EMPTY_CAMPAIGN }))}>
                {state === 'saving' ? 'Salvando…' : campaign.id ? 'Salvar campanha' : 'Criar campanha'}
              </button>
            </div>
          </section>

          <section className="ads-list">
            {data.campaigns.map((item) => (
              <button type="button" className="ads-list__item" key={item.id} onClick={() => setCampaign({ ...item, startsAt: dateTimeInput(item.startsAt), endsAt: dateTimeInput(item.endsAt) })}>
                <div><strong>{item.name}</strong><span>{item.advertiserName}</span></div>
                <small>{item.status}</small>
              </button>
            ))}
          </section>
        </div>
      )}

      {tab === 'slots' && (
        <div className="ads-workspace">
          <section className="admin-editor-card">
            <div className="admin-editor-card__head"><span>Inventário</span><strong>Posição</strong></div>
            <div className="admin-editor-card__body admin-editor-card__body--fields">
              <div className="ads-field-grid">
                <label className="admin-editor-field"><span>Código</span><input placeholder="header" value={slot.code} onChange={(e) => setSlot({ ...slot, code: e.target.value })} /></label>
                <label className="admin-editor-field"><span>Nome</span><input value={slot.name} onChange={(e) => setSlot({ ...slot, name: e.target.value })} /></label>
              </div>
              <label className="admin-editor-field"><span>Localização</span><input value={slot.location} onChange={(e) => setSlot({ ...slot, location: e.target.value })} /></label>
              <label className="admin-editor-field"><span>Descrição</span><textarea rows={4} value={slot.description} onChange={(e) => setSlot({ ...slot, description: e.target.value })} /></label>
              <div className="ads-format-picker">
                <span>Formatos aceitos</span>
                <div>
                  {data.formats.map((format) => {
                    const key = format.width + 'x' + format.height;
                    return (
                      <label key={key}>
                        <input
                          type="checkbox"
                          checked={slot.allowedSizes.includes(key)}
                          onChange={() => setSlot({
                            ...slot,
                            allowedSizes: slot.allowedSizes.includes(key)
                              ? slot.allowedSizes.filter((item) => item !== key)
                              : [...slot.allowedSizes, key],
                          })}
                        />
                        <span>{key}<small>{format.label}</small></span>
                      </label>
                    );
                  })}
                </div>
              </div>
              <div className="ads-field-grid">
                <label className="admin-editor-field"><span>Sem anúncio</span><select value={slot.fallbackStrategy} onChange={(e) => setSlot({ ...slot, fallbackStrategy: e.target.value as AdSlotRecord['fallbackStrategy'] })}><option value="hide">Não exibir nada</option><option value="header_message">Texto institucional do header</option></select></label>
                <label className="ads-check"><input type="checkbox" checked={slot.enabled} onChange={(e) => setSlot({ ...slot, enabled: e.target.checked })} /><span>Posição habilitada</span></label>
              </div>
              <button className="admin-button--primary" type="button" disabled={!slot.code.trim() || !slot.name.trim() || !slot.location.trim() || slot.allowedSizes.length === 0 || state === 'saving'} onClick={() => void save({ entity: 'slot', ...slot }, () => setSlot({ ...EMPTY_SLOT }))}>
                {state === 'saving' ? 'Salvando…' : slot.id ? 'Salvar posição' : 'Criar posição'}
              </button>
            </div>
          </section>

          <section className="ads-list">
            {data.slots.map((item) => (
              <button type="button" className="ads-list__item" key={item.id} onClick={() => setSlot({ ...item })}>
                <div><strong>{item.name}</strong><span>{item.location} · {item.allowedSizes.join(', ')}</span></div>
                <small>{item.enabled ? item.code : 'desabilitada'}</small>
              </button>
            ))}
          </section>
        </div>
      )}

      {tab === 'creatives' && (
        <div className="ads-creative-workspace">
          <section className="admin-editor-card">
            <div className="admin-editor-card__head"><span>Criação</span><strong>Criativo</strong></div>
            <div className="admin-editor-card__body admin-editor-card__body--fields">
              <label className="admin-editor-field"><span>Campanha</span><select value={creative.campaignId} onChange={(e) => setCreative({ ...creative, campaignId: Number(e.target.value) })}><option value={0}>Selecione</option>{data.campaigns.map((item) => <option value={item.id} key={item.id}>{item.name} · {item.advertiserName}</option>)}</select></label>
              <label className="admin-editor-field"><span>Nome do criativo</span><input value={creative.name} onChange={(e) => setCreative({ ...creative, name: e.target.value })} /></label>

              <div className="ads-field-grid">
                <label className="admin-editor-field"><span>Tipo</span><select value={creative.kind} onChange={(e) => { setCreative({ ...creative, kind: e.target.value as Creative['kind'] }); setBuilderOpen(false); }}><option value="image">Imagem</option><option value="html5">HTML5 + CSS3</option></select></label>
                <label className="admin-editor-field"><span>Formato</span><select value={creative.width + 'x' + creative.height} onChange={(e) => { const found = data.formats.find((item) => item.width + 'x' + item.height === e.target.value); if (found) setCreative({ ...creative, width: found.width, height: found.height }); }}>{data.formats.map((item) => <option value={item.width + 'x' + item.height} key={item.width + 'x' + item.height}>{item.width}×{item.height} · {item.label}</option>)}</select></label>
              </div>

              {creative.kind === 'image' ? (
                <>
                  <label className="admin-editor-field"><span>URL da imagem</span><input placeholder="/wp-content/uploads/... ou https://" value={creative.imageUrl} onChange={(e) => setCreative({ ...creative, imageUrl: e.target.value })} /></label>
                  <button className="ads-builder-button" type="button" onClick={() => setMediaOpen((open) => !open)}>{mediaOpen ? 'Fechar biblioteca' : 'Escolher da Biblioteca de Mídias'}</button>
                  {mediaOpen && <AdMediaPicker size={creative.width + '×' + creative.height} csrfToken={csrfToken} onClose={() => setMediaOpen(false)} onSelect={(item) => {
                    setCreative({ ...creative, imageUrl: item.url, altText: item.alt || item.title });
                    setMediaOpen(false);
                  }} />}
                  <label className="admin-editor-field"><span>Texto alternativo</span><input value={creative.altText} onChange={(e) => setCreative({ ...creative, altText: e.target.value })} /></label>
                </>
              ) : (
                <button type="button" className="ads-builder-button" onClick={() => setBuilderOpen((value) => !value)}>
                  {builderOpen ? 'Fechar editor visual' : 'Abrir editor visual GrapesJS'}
                </button>
              )}

              <label className="admin-editor-field"><span>Link do anúncio</span><input type="url" placeholder="https://" value={creative.clickUrl} onChange={(e) => setCreative({ ...creative, clickUrl: e.target.value })} /></label>
              <label className="admin-editor-field"><span>Status</span><select value={creative.status} onChange={(e) => setCreative({ ...creative, status: e.target.value as Creative['status'] })}><option value="draft">Rascunho</option><option value="active">Ativo</option><option value="paused">Pausado</option></select></label>

              <CreativePreview creative={creative} />

              <button className="admin-button--primary" type="button" disabled={!creative.name.trim() || !creative.campaignId || state === 'saving' || (creative.kind === 'image' ? !creative.imageUrl.trim() : !creative.html.trim())} onClick={() => void save({ entity: 'creative', ...creative }, () => setCreative({ ...EMPTY_CREATIVE }))}>
                {state === 'saving' ? 'Salvando…' : creative.id ? 'Salvar criativo' : 'Salvar criativo'}
              </button>
            </div>
          </section>

          <div>
            {builderOpen && creative.kind === 'html5' && (
              <Html5Builder creative={creative} onChange={setCreative} />
            )}

            <section className="ads-list ads-list--creatives">
              {data.creatives.map((item) => (
                <button type="button" className="ads-list__item" key={item.id} onClick={() => { setCreative({ ...item }); setBuilderOpen(false); }}>
                  <div><strong>{item.name}</strong><span>{item.campaignName} · {item.width}×{item.height} · {item.kind.toUpperCase()}</span></div>
                  <small>{item.status}</small>
                </button>
              ))}
            </section>
          </div>
        </div>
      )}

      {tab === 'reports' && <AdsReports />}

      {tab === 'placements' && (
        <div className="ads-workspace">
          <section className="admin-editor-card">
            <div className="admin-editor-card__head"><span>Entrega</span><strong>Veiculação</strong></div>
            <div className="admin-editor-card__body admin-editor-card__body--fields">
              <label className="admin-editor-field"><span>Campanha</span><select value={placement.campaignId} onChange={(e) => setPlacement({ ...placement, campaignId: Number(e.target.value), creativeId: 0, slotId: 0 })}><option value={0}>Selecione</option>{data.campaigns.map((item) => <option value={item.id} key={item.id}>{item.name} · {item.status}</option>)}</select></label>
              <label className="admin-editor-field"><span>Criativo</span><select value={placement.creativeId} disabled={!placement.campaignId} onChange={(e) => setPlacement({ ...placement, creativeId: Number(e.target.value), slotId: 0 })}><option value={0}>Selecione</option>{activeCampaignCreatives.map((item) => <option value={item.id} key={item.id}>{item.name} · {item.width}×{item.height} · {item.status}</option>)}</select></label>
              <label className="admin-editor-field"><span>Posição compatível</span><select value={placement.slotId} disabled={!selectedCreative} onChange={(e) => setPlacement({ ...placement, slotId: Number(e.target.value) })}><option value={0}>Selecione</option>{selectedSlot && !compatibleSlots.some((item) => item.id === selectedSlot.id) && <option value={selectedSlot.id} disabled>{selectedSlot.name} (incompatível ou desativada)</option>}{compatibleSlots.map((item) => <option value={item.id} key={item.id}>{item.name} · {creativeSize}</option>)}</select></label>
              {selectedCreative && compatibleSlots.length === 0 && <p className="ads-placement-help">Nenhuma posição habilitada aceita {creativeSize}. Ajuste os formatos em Posições ou escolha outro criativo.</p>}
              <div className="ads-field-grid">
                <label className="admin-editor-field"><span>Dispositivo</span><select value={placement.device} onChange={(e) => setPlacement({ ...placement, device: e.target.value as Placement['device'] })}><option value="all">Todos</option><option value="desktop">Desktop</option><option value="mobile">Mobile</option></select></label>
                <label className="admin-editor-field"><span>Status</span><select value={placement.status} onChange={(e) => setPlacement({ ...placement, status: e.target.value as Placement['status'] })}><option value="active">Ativa</option><option value="paused">Pausada</option></select></label>
              </div>
              <div className="ads-field-grid">
                <label className="admin-editor-field"><span>Início opcional</span><input type="datetime-local" value={placement.startsAt} onChange={(e) => setPlacement({ ...placement, startsAt: e.target.value })} /></label>
                <label className="admin-editor-field"><span>Fim opcional</span><input type="datetime-local" value={placement.endsAt} onChange={(e) => setPlacement({ ...placement, endsAt: e.target.value })} /></label>
              </div>
              <label className="admin-editor-field"><span>Prioridade</span><input type="number" min="0" max="1000" value={placement.priority} onChange={(e) => setPlacement({ ...placement, priority: Number(e.target.value) })} /></label>
              {selectedCreative && selectedSlot && <div className="ads-placement-readiness" role="status" aria-live="polite">
                <strong>{deliveryIssues.length === 0 ? 'Elegível para exibição' : 'Atenção antes de veicular'}</strong>
                {deliveryIssues.length > 0 ? (
                  <ul>{[...new Set(deliveryIssues)].map((issue) => <li key={issue}>{issue}</li>)}</ul>
                ) : (
                  <p>Os cadastros estão compatíveis. A entrega também depende da prioridade de outras campanhas na posição.</p>
                )}
              </div>}
              <button className="admin-button--primary" type="button" disabled={!canSavePlacement} onClick={() => void save({ entity: 'placement', ...placement }, () => setPlacement({ ...EMPTY_PLACEMENT }))}>
                {state === 'saving' ? 'Salvando…' : placement.id ? 'Salvar veiculação' : 'Ativar veiculação'}
              </button>
            </div>
          </section>

          <section className="ads-list">
            {data.placements.map((item) => (
              <button type="button" className="ads-list__item" key={item.id} onClick={() => setPlacement({ id: item.id, campaignId: item.campaignId, creativeId: item.creativeId, slotId: item.slotId, device: item.device, status: item.status, startsAt: dateTimeInput(item.startsAt), endsAt: dateTimeInput(item.endsAt), priority: item.priority })}>
                <div><strong>{item.creativeName}</strong><span>{item.campaignName} → {item.slotName} · {item.device}</span></div>
                <small>{item.status}</small>
              </button>
            ))}
          </section>
        </div>
      )}
    </div>
  );
}
