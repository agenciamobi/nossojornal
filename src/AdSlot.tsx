import { useEffect, useMemo, useState, type CSSProperties, type ReactNode } from 'react';
import './ads.css';

type AdCreative = {
  placementId: number;
  campaignId: number;
  campaignName: string;
  creativeId: number;
  name: string;
  kind: 'image' | 'html5';
  width: number;
  height: number;
  imageUrl: string;
  clickUrl: string;
  altText: string;
  html: string;
  css: string;
  advertiser: {
    id: number;
    name: string;
  };
};

type AdResponse = {
  ok: boolean;
  data?: {
    slot: {
      code: string;
      name?: string;
      allowedSizes?: string[];
      fallbackStrategy: 'hide' | 'header_message';
    };
    ad: AdCreative | null;
  };
};

function htmlCreativeDocument(ad: AdCreative) {
  const safeTitle = ad.name.replace(/[<>]/g, '');
  return `<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=${ad.width},initial-scale=1">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src https: data:; style-src 'unsafe-inline'; font-src https: data:; media-src https: data:;">
<title>${safeTitle}</title>
<style>
html,body{margin:0;width:100%;height:100%;overflow:hidden;background:transparent}
*,*::before,*::after{box-sizing:border-box}
${ad.css}
</style>
</head>
<body>${ad.html}</body>
</html>`;
}

export function AdSlot({
  slot,
  fallback = null,
  className = '',
}: {
  slot: string;
  fallback?: ReactNode;
  className?: string;
}) {
  const [ad, setAd] = useState<AdCreative | null>(null);
  const [ready, setReady] = useState(false);
  const [device, setDevice] = useState<'desktop' | 'mobile'>(() =>
    window.matchMedia('(max-width: 720px)').matches ? 'mobile' : 'desktop'
  );

  useEffect(() => {
    const media = window.matchMedia('(max-width: 720px)');
    const onChange = () => setDevice(media.matches ? 'mobile' : 'desktop');
    media.addEventListener('change', onChange);
    return () => media.removeEventListener('change', onChange);
  }, []);

  useEffect(() => {
    const controller = new AbortController();
    setReady(false);

    void fetch(
      '/api/v1/ads.php?slot=' + encodeURIComponent(slot) + '&device=' + encodeURIComponent(device),
      {
        headers: { Accept: 'application/json' },
        signal: controller.signal,
      },
    )
      .then(async (response) => {
        if (!response.ok) throw new Error('ad_slot_http_' + response.status);
        return response.json() as Promise<AdResponse>;
      })
      .then((payload) => {
        if (!payload.ok || !payload.data) throw new Error('ad_slot_invalid');
        setAd(payload.data.ad);
        setReady(true);
      })
      .catch((error) => {
        if (error instanceof DOMException && error.name === 'AbortError') return;
        setAd(null);
        setReady(true);
      });

    return () => controller.abort();
  }, [slot, device]);

  const style = useMemo(
    () =>
      ad
        ? ({
            '--ad-width': String(ad.width),
            '--ad-height': String(ad.height),
          } as CSSProperties)
        : undefined,
    [ad],
  );

  if (!ready || !ad) {
    return <>{fallback}</>;
  }

  return (
    <aside
      className={'ad-slot ' + className}
      data-ad-slot={slot}
      data-ad-kind={ad.kind}
      style={style}
      aria-label={'Publicidade: ' + ad.advertiser.name}
    >
      <span className="ad-slot__label">Publicidade</span>

      <div className="ad-slot__frame">
        {ad.kind === 'image' ? (
          ad.clickUrl ? (
            <a href={ad.clickUrl} target="_blank" rel="sponsored noopener noreferrer">
              <img
                src={ad.imageUrl}
                width={ad.width}
                height={ad.height}
                alt={ad.altText || ad.name}
              />
            </a>
          ) : (
            <img
              src={ad.imageUrl}
              width={ad.width}
              height={ad.height}
              alt={ad.altText || ad.name}
            />
          )
        ) : (
          <>
            <iframe
              title={ad.name}
              sandbox=""
              scrolling="no"
              srcDoc={htmlCreativeDocument(ad)}
              width={ad.width}
              height={ad.height}
            />
            {ad.clickUrl && (
              <a
                className="ad-slot__click-overlay"
                href={ad.clickUrl}
                target="_blank"
                rel="sponsored noopener noreferrer"
                aria-label={'Abrir anúncio de ' + ad.advertiser.name}
              />
            )}
          </>
        )}
      </div>
    </aside>
  );
}
