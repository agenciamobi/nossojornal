import { Brand, SiteHeader } from './Header';

type Story = {
  section: string;
  title: string;
  summary: string;
  time: string;
  tone: 'navy' | 'blue' | 'sky' | 'slate';
};


const recentStories: Story[] = [
  {
    section: 'Demonstração',
    title: 'Título de matéria para validar a hierarquia editorial da nova capa',
    summary: 'Este conteúdo é apenas demonstrativo e será substituído pela API editorial do Nosso Jornal.',
    time: 'layout de demonstração',
    tone: 'navy',
  },
  {
    section: 'Demonstração',
    title: 'Bloco secundário mostra como notícias recentes convivem com a manchete',
    summary: 'A estrutura foi pensada para receber conteúdo real sem mudar a composição visual.',
    time: 'layout de demonstração',
    tone: 'blue',
  },
  {
    section: 'Demonstração',
    title: 'Cards menores priorizam leitura rápida e navegação entre editorias',
    summary: 'Imagem, título, editoria e horário serão abastecidos por dados normalizados.',
    time: 'layout de demonstração',
    tone: 'sky',
  },
  {
    section: 'Demonstração',
    title: 'A capa poderá misturar relevância editorial com recência',
    summary: 'A ordem final será controlada pela redação, não apenas por data de publicação.',
    time: 'layout de demonstração',
    tone: 'slate',
  },
];

const latest = [
  'Espaço preparado para a primeira notícia real conectada à API',
  'Categoria, autoria e horário entram no mesmo contrato editorial',
  'O acervo legado será normalizado sem expor o schema WordPress',
  'Links históricos poderão ser preservados durante o cutover',
  'Classificados e comunicados terão áreas próprias no novo portal',
  'A home poderá destacar colunas e conteúdos especiais',
];

const classifiedCards = [
  'Empregos e oportunidades',
  'Imóveis e serviços',
  'Comércio e negócios',
  'Editais e oportunidades',
];

const officialNotices = [
  'Comunicado oficial de demonstração',
  'Edital de demonstração',
  'Aviso institucional de demonstração',
];

function StoryArtwork({ tone, label }: { tone: Story['tone']; label: string }) {
  return (
    <div className={`story-art story-art--${tone}`} aria-hidden="true">
      <span>{label}</span>
    </div>
  );
}

export function App() {
  return (
    <div className="site-shell">
      <SiteHeader />

      <main>
        <section className="container hero" aria-labelledby="hero-title">
          <article className="lead-story">
            <StoryArtwork tone="navy" label="MANCHETE" />
            <div className="lead-story__content">
              <span className="eyebrow">Demonstração editorial</span>
              <h1 id="hero-title">A nova capa do Nosso Jornal começa pela hierarquia da informação</h1>
              <p>
                Uma manchete forte, navegação por editorias, blocos de recência e espaço para produtos
                editoriais específicos. O conteúdo real entra na próxima fase via API.
              </p>
              <div className="story-meta">
                <span>Nosso Jornal</span>
                <span>estrutura de demonstração</span>
              </div>
            </div>
          </article>

          <aside className="hero-side" aria-label="Destaques secundários">
            <div className="section-heading section-heading--compact">
              <div>
                <span>AGORA</span>
                <h2>Mais recentes</h2>
              </div>
              <a href="#recentes">Ver todas</a>
            </div>

            {recentStories.slice(0, 3).map((story, index) => (
              <article className="compact-story" key={story.title}>
                <span className="compact-story__index">{String(index + 1).padStart(2, '0')}</span>
                <div>
                  <span className="eyebrow">{story.section}</span>
                  <h3>{story.title}</h3>
                  <span className="story-time">{story.time}</span>
                </div>
              </article>
            ))}
          </aside>
        </section>

        <section className="container editorial-grid" id="editorias">
          <div className="editorial-grid__main">
            <div className="section-heading">
              <div>
                <span>CAPA</span>
                <h2>Destaques editoriais</h2>
              </div>
              <p>Estrutura modular pronta para receber conteúdo real.</p>
            </div>

            <div className="story-grid">
              {recentStories.map((story) => (
                <article className="story-card" key={story.title}>
                  <StoryArtwork tone={story.tone} label={story.section.toUpperCase()} />
                  <div className="story-card__body">
                    <span className="eyebrow">{story.section}</span>
                    <h3>{story.title}</h3>
                    <p>{story.summary}</p>
                    <span className="story-time">{story.time}</span>
                  </div>
                </article>
              ))}
            </div>
          </div>

          <aside className="editorial-rail">
            <div className="rail-card rail-card--column">
              <span className="eyebrow">Colunas</span>
              <h2>Opinião e análise terão um espaço visual próprio</h2>
              <p>Autores e colunistas serão conectados ao novo modelo editorial em fase posterior.</p>
              <a href="#colunas">Conhecer estrutura</a>
            </div>

            <div className="ad-slot" aria-label="Espaço reservado para publicidade">
              <span>PUBLICIDADE</span>
              <strong>Espaço comercial</strong>
              <p>Slot preparado para campanhas futuras.</p>
            </div>
          </aside>
        </section>

        <section className="support-strip" aria-labelledby="support-title">
          <div className="container support-strip__inner">
            <div>
              <span className="eyebrow eyebrow--light">Institucional</span>
              <h2 id="support-title">Apoiadores e colaboradores</h2>
            </div>
            <div className="support-logos" aria-label="Espaços de apoiadores">
              <span>APOIADOR 01</span>
              <span>APOIADOR 02</span>
              <span>APOIADOR 03</span>
            </div>
          </div>
        </section>

        <section className="container latest-section" id="recentes">
          <div className="section-heading">
            <div>
              <span>FLUXO</span>
              <h2>Notícias mais recentes</h2>
            </div>
            <p>Uma grade densa para acompanhar a atualização do portal ao longo do dia.</p>
          </div>

          <div className="latest-grid">
            {latest.map((title, index) => (
              <article className="latest-card" key={title}>
                <div className={`latest-card__media tone-${(index % 4) + 1}`} aria-hidden="true">
                  <span>{String(index + 1).padStart(2, '0')}</span>
                </div>
                <span className="eyebrow">Demonstração</span>
                <h3>{title}</h3>
                <p>Texto provisório usado exclusivamente para validar composição, densidade e leitura.</p>
              </article>
            ))}
          </div>
        </section>

        <section className="classifieds" id="classificados">
          <div className="container">
            <div className="section-heading section-heading--dark">
              <div>
                <span>UTILIDADE</span>
                <h2>Classificados</h2>
              </div>
              <p>Produto separado das notícias, com linguagem visual própria.</p>
            </div>
            <div className="classified-grid">
              {classifiedCards.map((title, index) => (
                <article className="classified-card" key={title}>
                  <span className="classified-card__number">0{index + 1}</span>
                  <div>
                    <span className="eyebrow">Demonstração</span>
                    <h3>{title}</h3>
                    <p>Área preparada para ofertas reais, expiração e moderação.</p>
                  </div>
                </article>
              ))}
            </div>
          </div>
        </section>

        <section className="container notices" id="comunicados">
          <div className="section-heading">
            <div>
              <span>DOCUMENTOS</span>
              <h2>Comunicados oficiais</h2>
            </div>
            <p>Área dedicada a editais, avisos e publicações institucionais.</p>
          </div>
          <div className="notice-grid">
            {officialNotices.map((title, index) => (
              <article className="notice-card" key={title}>
                <div className="notice-card__icon" aria-hidden="true">{String(index + 1).padStart(2, '0')}</div>
                <div>
                  <span className="eyebrow">Demonstração</span>
                  <h3>{title}</h3>
                  <p>Estrutura preparada para documento, data, categoria e arquivo histórico.</p>
                </div>
              </article>
            ))}
          </div>
        </section>

        <section className="newsletter">
          <div className="container newsletter__inner">
            <div>
              <span className="eyebrow eyebrow--light">Newsletter</span>
              <h2>O resumo do dia direto para o leitor.</h2>
              <p>A captura real de e-mail só será ativada quando consentimento e backend estiverem prontos.</p>
            </div>
            <form className="newsletter__form" onSubmit={(event) => event.preventDefault()}>
              <label htmlFor="newsletter-email">E-mail</label>
              <div>
                <input id="newsletter-email" type="email" placeholder="voce@exemplo.com" disabled />
                <button type="submit" disabled>Em breve</button>
              </div>
            </form>
          </div>
        </section>
      </main>

      <footer className="site-footer" id="sobre">
        <div className="container site-footer__grid">
          <div>
            <Brand />
            <p>
              Fundação da nova experiência digital do Nosso Jornal. Conteúdo real e funcionalidades
              editoriais serão conectados por etapas.
            </p>
          </div>
          <div>
            <strong>Editorias</strong>
            <a href="#editorias">Últimas</a>
            <a href="#editorias">Região</a>
            <a href="#editorias">Esportes</a>
            <a href="#editorias">Cultura</a>
          </div>
          <div>
            <strong>Serviços</strong>
            <a href="#classificados">Classificados</a>
            <a href="#comunicados">Comunicados</a>
            <a href="#recentes">Arquivo</a>
            <a href="#sobre">Sobre</a>
          </div>
          <div>
            <strong>Projeto</strong>
            <span>React + Vite</span>
            <span>Mobile-first</span>
            <span>SEO orientado a notícias</span>
            <span>Integração MOBI Core</span>
          </div>
        </div>
        <div className="container site-footer__bottom">
          <span>Nosso Jornal</span>
          <span>Nova aplicação em desenvolvimento</span>
        </div>
      </footer>
    </div>
  );
}
