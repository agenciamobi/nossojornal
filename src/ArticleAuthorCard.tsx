import { useEffect, useState } from 'react';
import './article-author-card.css';

type ArticleAuthor = {
  name: string;
  slug: string;
  url: string | null;
};

type PublicAuthorPreview = {
  name: string;
  slug: string;
  url: string;
  role: string;
  bio: string;
  avatar: {
    url: string;
    alt: string;
    srcSet: string;
  } | null;
};

type AuthorResponse = {
  ok: boolean;
  data?: { author: PublicAuthorPreview };
};

function authorInitials(name: string): string {
  return name.trim().split(/\s+/).slice(0, 2)
    .map((part) => part.charAt(0).toLocaleUpperCase('pt-BR')).join('');
}

/**
 * Public, optional author summary. Reuses the same author profile endpoint as
 * /autor/:slug; never blocks article content if the profile is unavailable.
 */
export function ArticleAuthorCard({ author }: { author: ArticleAuthor }) {
  const [profile, setProfile] = useState<PublicAuthorPreview | null>(null);

  useEffect(() => {
    setProfile(null);
    if (!author.url || !/^[a-z0-9-]+$/.test(author.slug)) return;

    const controller = new AbortController();
    const query = new URLSearchParams({ slug: author.slug, page: '1', per_page: '1' });

    void fetch('/api/v1/author.php?' + query.toString(), {
      headers: { Accept: 'application/json' },
      signal: controller.signal,
    })
      .then(async (response) => response.ok ? response.json() as Promise<AuthorResponse> : null)
      .then((response) => {
        const candidate = response?.ok ? response.data?.author : null;
        if (
          !controller.signal.aborted
          && candidate?.slug === author.slug
          && candidate.name.trim() !== ''
          && candidate.url === '/autor/' + encodeURIComponent(author.slug)
        ) {
          setProfile(candidate);
        }
      })
      .catch(() => {
        // Author information is supplementary: no empty/error card on failure.
      });

    return () => controller.abort();
  }, [author.slug, author.url]);

  // Also guard against an old profile flashing during a client-side slug change.
  if (!author.url || !profile || profile.slug !== author.slug) return null;

  const role = profile.role.trim();
  const bio = profile.bio.trim();
  const isColumnist = role.toLocaleLowerCase('pt-BR').includes('colunista');

  return (
    <section className="article-author-card" aria-label={isColumnist ? 'Perfil do colunista' : 'Perfil do autor'}>
      <span className="article-author-card__eyebrow">
        {isColumnist ? 'Sobre o colunista' : 'Sobre o autor'}
      </span>
      <a className="article-author-card__identity" href={profile.url} aria-label={'Conhecer ' + profile.name}>
        {profile.avatar?.url ? (
          <img
            className="article-author-card__avatar"
            src={profile.avatar.url}
            srcSet={profile.avatar.srcSet || undefined}
            sizes={profile.avatar.srcSet ? '58px' : undefined}
            width={58}
            height={58}
            loading="lazy"
            decoding="async"
            alt={profile.avatar.alt || 'Foto de ' + profile.name}
          />
        ) : (
          <span className="article-author-card__avatar article-author-card__avatar--fallback" aria-hidden="true">
            {authorInitials(profile.name)}
          </span>
        )}
        <span className="article-author-card__identity-copy">
          <strong>{profile.name}</strong>
          {role && <span>{role}</span>}
        </span>
      </a>
      {bio && <p className="article-author-card__bio">{bio}</p>}
      <a className="article-author-card__more" href={profile.url}>
        Ver perfil e matérias <span aria-hidden="true">↗</span>
      </a>
    </section>
  );
}
