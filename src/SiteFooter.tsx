import { useEffect, useMemo, useState } from 'react';
import { Brand } from './Header';
import {
  loadNavigation,
  navigationLinkProps,
  selectNavigationMenu,
  type NavigationItem,
  type NavigationMenu,
} from './navigation';

type FooterColumn = {
  title: string;
  titleItem?: NavigationItem;
  links: NavigationItem[];
};

function footerColumns(menu: NavigationMenu): FooterColumn[] {
  const hierarchical: FooterColumn[] = menu.items
    .filter((item) => item.children.length > 0)
    .map((item) => ({
      title: item.title,
      titleItem: item,
      links: item.children,
    }));

  const flatItems = menu.items.filter((item) => item.children.length === 0);

  if (flatItems.length > 0) {
    hierarchical.push({
      title: menu.name || 'Navegação',
      links: flatItems,
    });
  }

  return hierarchical.slice(0, 3);
}

function NativeFooterNavigation({ menu }: { menu: NavigationMenu }) {
  const columns = footerColumns(menu);

  return (
    <>
      {columns.map((column, index) => (
        <nav key={column.title + '-' + index} aria-label={column.title}>
          {column.titleItem ? (
            <a
              className="site-footer__column-title site-footer__column-title--link"
              href={column.titleItem.url}
              {...navigationLinkProps(column.titleItem)}
            >
              {column.title}
            </a>
          ) : (
            <span className="site-footer__column-title">{column.title}</span>
          )}

          {column.links.slice(0, 12).map((item) => (
            <a
              key={item.id}
              href={item.url}
              {...navigationLinkProps(item)}
            >
              {item.title}
            </a>
          ))}
        </nav>
      ))}
    </>
  );
}

export function SiteFooter() {
  const [navigationMenus, setNavigationMenus] = useState<NavigationMenu[]>([]);

  useEffect(() => {
    let active = true;

    void loadNavigation()
      .then((menus) => {
        if (active) setNavigationMenus(menus);
      })
      .catch(() => {
        if (active) setNavigationMenus([]);
      });

    return () => {
      active = false;
    };
  }, []);

  const footerMenu = useMemo(
    () => selectNavigationMenu(navigationMenus, 'footer'),
    [navigationMenus],
  );

  return (
    <footer className="site-footer site-footer--magazine" id="sobre">
      <div className="container site-footer__mast">
        <div className="site-footer__identity">
          <Brand />
          <div>
            <span className="site-footer__kicker">Jornalismo local • cobertura regional</span>
            <p>Informação de Hulha Negra, da região e do Rio Grande do Sul.</p>
          </div>
        </div>

        <a className="site-footer__latest-link" href="/ultimas">
          <span>Atualização contínua</span>
          <strong>Últimas notícias</strong>
        </a>
      </div>

      <div className="container site-footer__rule" />

      <div className="container site-footer__mag-grid">
        {footerMenu ? (
          <NativeFooterNavigation menu={footerMenu} />
        ) : (
          <>
            <nav aria-label="Editorias no rodapé">
              <span className="site-footer__column-title">Editorias</span>
              <a href="/categoria/hulha-negra">Hulha Negra</a>
              <a href="/categoria/politica">Política</a>
              <a href="/categoria/seguranca">Segurança</a>
              <a href="/categoria/economia">Economia</a>
              <a href="/categoria/educacao">Educação</a>
              <a href="/categoria/rural">Rural</a>
              <a href="/categoria/esportes">Esportes</a>
            </nav>

            <nav aria-label="Cobertura regional no rodapé">
              <span className="site-footer__column-title">Cobertura regional</span>
              <a href="/categoria/bage">Bagé</a>
              <a href="/categoria/acegua">Aceguá</a>
              <a href="/categoria/candiota">Candiota</a>
              <a href="/categoria/dom-pedrito">Dom Pedrito</a>
              <a href="/categoria/herval">Herval</a>
              <a href="/categoria/pinheiro-machado">Pinheiro Machado</a>
              <a href="/categoria/piratini">Piratini</a>
            </nav>

            <nav aria-label="Serviços do Nosso Jornal">
              <span className="site-footer__column-title">Serviços</span>
              <a href="/ultimas">Últimas notícias</a>
              <a href="/classificados">Classificados</a>
              <a href="/comunicados">Comunicados</a>
              <a href="/busca">Busca</a>
              <a href="/sobre">Sobre</a>
              <a href="/contato">Contato</a>
            </nav>
          </>
        )}

        <div className="site-footer__edition">
          <span className="site-footer__column-title">Nosso Jornal</span>
          <strong>Hulha Negra • Rio Grande do Sul</strong>
          <p>
            Portal regional com cobertura de notícias, política, economia,
            segurança, educação, rural, esporte e comunidade.
          </p>
          <a href="/contato">Fale com a redação</a>
        </div>
      </div>

      <div className="container site-footer__bottom">
        <span>© {new Date().getFullYear()} Nosso Jornal</span>

        <span
          className="site-footer__credit"
          itemScope
          itemType="https://schema.org/Organization"
        >
          Site desenvolvido por{' '}
          <a
            href="https://agenciamobi.com.br/"
            target="_blank"
            rel="noopener noreferrer external"
            aria-label="Visitar o site da MOBI - Marketing Inteligente em nova aba"
            title="MOBI - Marketing Inteligente"
            itemProp="url"
          >
            <span itemProp="name">MOBI - Marketing Inteligente</span>
          </a>
        </span>
      </div>
    </footer>
  );
}
