import { useEffect, useMemo, useRef, useState } from 'react';
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

export function AdminAds({ csrfToken }: { csrfToken: string }) {
  const [data, setData] = useState<AdsData | null>(null);
  const [tab, setTab] = useState<'advertisers' | 'campaigns' | 'creatives' | 'slots' | 'placements'>('creatives');
  const [state, setState] = useState<'loading' | 'idle' | 'saving' | 'error'>('loading');
  const [errorCode, setErrorCode] = useState('');
  const [builderOpen, setBuilderOpen] = useState(false);

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
