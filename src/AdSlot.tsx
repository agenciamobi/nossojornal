import { useEffect, useMemo, useRef, useState, type CSSProperties, type ReactNode } from 'react';
import './ads.css';

type AdCreative = {
  token: string;
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
  const [rotation, setRotation] = useState(0);
  const displayRef = useRef<HTMLElement | null>(null);
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
    const rotate = () => { if (document.visibilityState === 'visible') setRotation((count) => count + 1); };
    const timer = window.setInterval(rotate, 60000);
    document.addEventListener('visibilitychange', rotate);
    return () => { window.clearInterval(timer); document.removeEventListener('visibilitychange', rotate); };
  }, []);

  useEffect(() => {
    const controller = new AbortController();

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
  }, [slot, device, rotation]);

  // A selection request is not an impression. Register only after the banner
  // is at least half visible for a full second in a visible browser tab.
  useEffect(() => {
    if (!ad?.token || !ready || !displayRef.current || !('IntersectionObserver' in window)) return;
    let timeout: number | undefined;
    let recorded = false;
    const token = ad.token;
    const observer = new IntersectionObserver((entries) => {
      if (recorded || document.visibilityState !== 'visible' || !entries.some((entry) => entry.isIntersecting && entry.intersectionRatio >= 0.5)) {
        window.clearTimeout(timeout);
        timeout = undefined;
        return;
      }
      if (timeout !== undefined) return;
      timeout = window.setTimeout(() => {
        if (document.visibilityState !== 'visible') return;
        recorded = true;
        observer.disconnect();
        void fetch('/api/v1/ad-event.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ type: 'impression', token }),
          keepalive: true,
        }).catch(() => { /* Metrics are best-effort and never block editorial rendering. */ });
      }, 1000);
    }, { threshold: [0, 0.5, 1] });
    observer.observe(displayRef.current);
    const visibility = () => {
      if (document.visibilityState !== 'visible') {
        window.clearTimeout(timeout);
        timeout = undefined;
      }
    };
    document.addEventListener('visibilitychange', visibility);
    return () => {
      window.clearTimeout(timeout);
      observer.disconnect();
      document.removeEventListener('visibilitychange', visibility);
    };
  }, [ad?.token, ready]);

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
      ref={displayRef}
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
            <a href={'/api/v1/ad-click.php?t=' + encodeURIComponent(ad.token)} target="_blank" rel="sponsored noopener noreferrer">
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
                href={'/api/v1/ad-click.php?t=' + encodeURIComponent(ad.token)}
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
