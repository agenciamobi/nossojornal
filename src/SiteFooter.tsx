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

type Category = {
  id: number;
  name: string;
  slug: string;
  parentId: number | null;
  url: string;
  color: string;
  publishedCount: number;
  children?: Category[];
};

type CategoriesResponse = {
  ok: boolean;
  data?: {
    tree?: Category[];
  };
};

const SYSTEM_CATEGORY_SLUGS = new Set([
  'capa',
  'geral',
  'outros',
  'eleicoes-2024',
  'cobertura-regional',
]);

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
    <div className="site-footer__native-nav">
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
    </div>
  );
}

export function SiteFooter() {
  const [navigationMenus, setNavigationMenus] = useState<NavigationMenu[]>([]);
  const [categories, setCategories] = useState<Category[]>([]);

  useEffect(() => {
    let active = true;
    const controller = new AbortController();

    void loadNavigation()
      .then((menus) => {
        if (active) setNavigationMenus(menus);
      })
      .catch(() => {
        if (active) setNavigationMenus([]);
      });

    void fetch('/api/v1/categories.php?include_empty=0', {
      headers: { Accept: 'application/json' },
      signal: controller.signal,
    })
      .then(async (response) => {
        if (!response.ok) throw new Error(`categories_http_${response.status}`);
        return response.json() as Promise<CategoriesResponse>;
      })
      .then((payload) => {
        if (!active || !payload.ok || !Array.isArray(payload.data?.tree)) return;
        setCategories(payload.data.tree);
      })
      .catch((error) => {
        if (error instanceof DOMException && error.name === 'AbortError') return;
        if (active) setCategories([]);
      });

    return () => {
      active = false;
      controller.abort();
    };
  }, []);

  const footerMenu = useMemo(
    () => selectNavigationMenu(navigationMenus, 'footer'),
    [navigationMenus],
  );

  const editorialCategories = useMemo(
    () =>
      categories
        .filter(
          (category) =>
            category.parentId === null
            && category.publishedCount > 0
            && !SYSTEM_CATEGORY_SLUGS.has(category.slug),
        )
        .sort((a, b) => a.name.localeCompare(b.name, 'pt-BR')),
    [categories],
  );

  const regionalCategory = categories.find(
    (category) => category.slug === 'cobertura-regional',
  );

  const regionalCities = useMemo(
    () =>
      [...(regionalCategory?.children ?? [])]
        .filter((category) => category.publishedCount > 0)
        .sort((a, b) => a.name.localeCompare(b.name, 'pt-BR')),
    [regionalCategory],
  );

  const serviceLinks = useMemo(() => {
    if (!footerMenu) return [];

    const serviceColumn = footerColumns(footerMenu).find((column) => {
      const title = column.title
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLocaleLowerCase('pt-BR');

      return title.includes('servic');
    });

    return serviceColumn?.links ?? [];
  }, [footerMenu]);

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
        <div className="site-footer__native-nav">
          <nav aria-label="Editorias no rodapé">
            <span className="site-footer__column-title">Editorias</span>
            {editorialCategories.length > 0 ? (
              editorialCategories.slice(0, 12).map((category) => (
                <a href={category.url} key={category.id}>{category.name}</a>
              ))
            ) : (
              <a href="/ultimas">Últimas notícias</a>
            )}
          </nav>

          <nav aria-label="Cobertura regional no rodapé">
            <span className="site-footer__column-title">Cobertura regional</span>
            {regionalCities.length > 0 ? (
              regionalCities.slice(0, 12).map((category) => (
                <a href={category.url} key={category.id}>{category.name}</a>
              ))
            ) : (
              <a href="/ultimas">Cobertura regional</a>
            )}
          </nav>

          <nav aria-label="Serviços do Nosso Jornal">
            <span className="site-footer__column-title">Serviços</span>
            {serviceLinks.length > 0 ? (
              serviceLinks.slice(0, 12).map((item) => (
                <a key={item.id} href={item.url} {...navigationLinkProps(item)}>
                  {item.title}
                </a>
              ))
            ) : (
              <>
                <a href="/ultimas">Últimas notícias</a>
                <a href="/classificados">Classificados</a>
                <a href="/comunicados">Comunicados</a>
                <a href="/busca">Busca</a>
                <a href="/sobre">Sobre</a>
                <a href="/contato">Contato</a>
              </>
            )}
          </nav>
        </div>

        <div className="site-footer__edition">
          <span className="site-footer__column-title">Nosso Jornal</span>
          <strong>Hulha Negra • Rio Grande do Sul</strong>
          <p>
            Portal regional com cobertura jornalística de Hulha Negra e da região.
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
