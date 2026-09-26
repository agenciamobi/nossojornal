export type NavigationRole = 'primary' | 'utility' | 'footer' | 'other';

export type NavigationItem = {
  id: number;
  parentId: number | null;
  title: string;
  url: string;
  target: '' | '_blank';
  external: boolean;
  kind: 'custom' | 'post' | 'page' | 'category' | 'tag';
  object: string;
  objectId: number | null;
  order: number;
  children: NavigationItem[];
};

export type NavigationMenu = {
  id: number;
  taxonomyId: number;
  name: string;
  slug: string;
  role: NavigationRole;
  itemCount: number;
  items: NavigationItem[];
};

type NavigationPayload = {
  ok: boolean;
  data?: {
    menus?: NavigationMenu[];
  };
};

let navigationPromise: Promise<NavigationMenu[]> | null = null;

export function loadNavigation(): Promise<NavigationMenu[]> {
  if (navigationPromise) {
    return navigationPromise;
  }

  navigationPromise = fetch('/api/v1/navigation.php', {
    headers: { Accept: 'application/json' },
  })
    .then(async (response) => {
      if (!response.ok) {
        throw new Error(`navigation_http_${response.status}`);
      }

      const payload = (await response.json()) as NavigationPayload;

      if (!payload.ok || !Array.isArray(payload.data?.menus)) {
        throw new Error('navigation_invalid_payload');
      }

      return payload.data.menus.filter(
        (menu) => menu.itemCount > 0 && Array.isArray(menu.items),
      );
    })
    .catch((error) => {
      navigationPromise = null;
      throw error;
    });

  return navigationPromise;
}

export function selectNavigationMenu(
  menus: NavigationMenu[],
  role: NavigationRole,
): NavigationMenu | null {
  return menus.find((menu) => menu.role === role && menu.itemCount > 0) ?? null;
}

export function navigationLinkProps(item: NavigationItem) {
  if (item.target === '_blank' || item.external) {
    return {
      target: item.target === '_blank' ? '_blank' : undefined,
      rel: 'noopener noreferrer external',
    };
  }

  return {};
}
