# WordPress Native Capabilities — Nosso Jornal

O banco legado do WordPress não é apenas uma fonte de posts. Ele já contém um modelo de conteúdo, identidade, mídia, taxonomias, histórico e configuração que pode continuar servindo como fundação do novo portal sem manter o WordPress como camada de apresentação.

Este documento define o que reaproveitar, o que evitar e a ordem sugerida de evolução.

## Princípio

A API própria do Nosso Jornal é o contrato da aplicação.

O banco WordPress continua sendo o patrimônio de dados.

A regra é:

- reaproveitar estruturas nativas quando elas já modelam bem o problema;
- usar metadados `_nj_*` quando o produto novo precisa de informação própria;
- evitar novas tabelas quando `wp_posts`, `wp_postmeta`, taxonomias, comentários ou options resolvem o caso com clareza;
- nunca depender do runtime do WordPress para renderizar o portal;
- nunca expor valores sensíveis de `wp_options`, configurações ou credenciais.

## O que já usamos

### wp_posts

Hoje serve como base para:

- notícias (`post`);
- páginas (`page`);
- mídia (`attachment`);
- agenda e objetos editoriais próprios;
- comentários editoriais;
- correções;
- atividade;
- pautas;
- redirecionamentos (`nj_redirect`).

O padrão de tipos de conteúdo internos permite evoluir produto sem criar uma coleção de tabelas paralelas.

### wp_postmeta

Já concentra:

- SEO legado do Yoast;
- imagem destacada;
- metadados editoriais `_nj_*`;
- homepage/capa;
- coautoria;
- fontes;
- séries/dossiês;
- relacionadas;
- redirecionamentos.

### terms / term_taxonomy / term_relationships

Já usamos:

- categorias;
- tags.

A mesma estrutura pode fornecer:

- menus;
- coleções temáticas;
- taxonomias legadas de plugins;
- regiões e sub-regiões;
- filtros editoriais.

### users / usermeta / options

O novo Sistema reaproveita:

- identidade dos usuários;
- senha WordPress;
- roles;
- capabilities;
- definições de roles em options.

Isso preserva contas e permissões sem autenticação paralela.

### comments

Já há administração/moderação no novo Sistema.

O modelo nativo também suporta:

- respostas encadeadas por `comment_parent`;
- comentários autenticados por `user_id`;
- status de moderação;
- metadados por comentário.

## Rodada 4 — aplicada

### Inventário vivo

`GET /api/admin/wp-capabilities.php`

A área `/sistema/wordpress` mostra:

- tipos de post e distribuição por status;
- taxonomias;
- chaves de postmeta mais usadas;
- usuários;
- comentários;
- attachments;
- revisões;
- menus;
- itens de menu;
- opções nativas presentes;
- relações órfãs;
- term_taxonomy sem term correspondente;
- oportunidades de produto.

O inventário consulta o banco real em runtime. Isso reduz decisões feitas por suposição.

### Redirecionamentos

Inspirado no princípio arquitetural do Safe Redirect Manager da 10up, sem copiar sua implementação.

As regras vivem em:

`wp_posts.post_type = nj_redirect`

Metadados:

```text
_nj_redirect_from
_nj_redirect_to
_nj_redirect_status
```

Admin:

```text
GET/POST /api/admin/redirects.php
/sistema/wordpress
```

O servidor resolve a regra em `meta.php` antes do shell React.

Suporte inicial:

- 301;
- 302;
- 307;
- 308;
- 410;
- ativar/desativar;
- nota interna;
- proteção contra origem duplicada;
- proteção contra loop direto;
- rotas /api e /sistema não podem ser origem.

## Próximas capacidades prioritárias

### 1. Revisões nativas

O WordPress Core usa posts do tipo `revision`, ligados ao original por `post_parent`.

Podemos:

- listar o histórico WordPress real;
- comparar título, resumo e conteúdo;
- restaurar revisão;
- distinguir autosave de revisão;
- preservar revisões antigas do WordPress junto das revisões `_nj` atuais;
- convergir no futuro para uma única timeline de histórico.

Regra: não apagar o histórico legado ao migrar.

### 2. Menus nativos

Menus do WordPress são:

- termos `nav_menu`;
- posts `nav_menu_item`;
- metadados como `_menu_item_url`, `_menu_item_object_id`, `_menu_item_object`, `_menu_item_type` e parent.

Podemos transformar isso em:

- menu principal configurável;
- menu institucional;
- colunas do rodapé;
- links externos;
- hierarquia/dropdowns;
- interface de edição no novo Sistema.

O frontend deve manter fallback editorial automático enquanto nenhum menu apropriado estiver configurado.

### 3. Sticky posts como sinal editorial

`wp_options.sticky_posts` pode entrar como um sinal adicional de destaque.

Não deve substituir a curadoria `_nj_home_*`.

Uso proposto:

1. curadoria manual Nosso Jornal;
2. sticky WordPress quando fizer sentido;
3. fallback editorial automático.

### 4. Biblioteca de mídia completa

Attachments oferecem mais que URL.

Explorar:

- `_wp_attachment_metadata`;
- dimensões;
- tamanhos derivados;
- crop;
- orientação;
- metadados EXIF quando existentes;
- legenda;
- descrição;
- alt;
- vínculo por `post_parent`;
- auditoria de uso por thumbnail e conteúdo.

Objetivo: image picker melhor, responsivo e capaz de escolher tamanho adequado em vez de entregar sempre o original.

### 5. Coautoria e perfis

Além dos usuários nativos:

- usermeta pode alimentar bio pública;
- avatar;
- função editorial;
- redes sociais;
- página de autor;
- arquivo de matérias por autor.

Não ler/expor usermeta indiscriminadamente. Usar allowlist.

### 6. Comentários como produto

A estrutura nativa permite:

- comentários públicos;
- respostas em árvore;
- aprovação;
- spam;
- denúncias por commentmeta;
- destaque de comentários;
- fechamento por matéria;
- autenticação opcional.

Ativar apenas quando houver estratégia editorial/moderação.

### 7. Hierarquia de páginas

`post_parent` e `menu_order` permitem:

- páginas filhas;
- seções institucionais;
- landing pages organizadas;
- ordem editorial.

Isso pode substituir listas hardcoded de páginas institucionais.

### 8. Post formats e taxonomias legadas

O inventário deve revelar taxonomias de plugins e formatos úteis.

Antes de descartá-las:

- medir uso;
- entender relações;
- mapear intenção editorial;
- decidir se viram tag, coleção, tipo de conteúdo ou campo `_nj_*`.

### 9. Estados e workflow

O PublishPress Planner reforça um padrão útil: workflow editorial como estado + metadados, sem transformar cada etapa em banco próprio.

O Nosso Jornal já segue essa linha com `_nj_*`.

Próximos passos possíveis:

- transições permitidas por role;
- SLA por etapa;
- notificações;
- fila de revisão;
- filtros por responsável;
- calendário editorial;
- log de transição de estado.

### 10. Proveniência e distribuição

O Distributor da 10up mostra a importância de preservar origem/conexão do conteúdo distribuído.

Nossa camada pode evoluir:

- URL original;
- publisher original;
- ID externo;
- data da captura;
- última sincronização;
- hash da fonte;
- status: original, adaptado, republicado, atualizado;
- política de canonical.

Isso serve RSS, agências, comunicados e futura distribuição entre propriedades MOBI.

### 11. API com conexões bounded

O WPGraphQL é uma boa referência conceitual para:

- separar tipos e relações;
- paginação previsível;
- não expor statuses privados;
- resolver conexões sob permissão;
- lazy loading/batching.

Não precisamos instalar GraphQL. Podemos aplicar esses princípios à API REST própria.

## Recursos que não devemos reaproveitar cegamente

### active_plugins

Pode ser inventariado como presença, mas não exposto com configuração sensível nem tratado como dependência do portal.

### transients e caches

Não são fonte canônica.

### options arbitrárias

Options podem conter secrets, tokens e configurações privadas.

A API deve usar allowlist explícita.

### serialized PHP

Ler somente quando necessário, sempre com:

```php
unserialize($value, ['allowed_classes' => false])
```

Nunca instanciar classes vindas do banco.

### guids

`posts.guid` não deve ser tratado como URL pública canônica.

## Referências arquiteturais estudadas

### WordPress Core

Referência para:

- revisões;
- `post_parent`;
- menus;
- metadados de menu;
- capabilities;
- taxonomias.

### PublishPress Planner

Referência para:

- workflow;
- estados;
- calendário;
- editorial metadata;
- permissões por ação.

### 10up Safe Redirect Manager

Referência para:

- redirecionamento como tipo de conteúdo;
- origem/destino em postmeta;
- status HTTP controlado;
- execução antes da renderização.

### 10up Distributor

Referência para:

- proveniência;
- conexão entre original e distribuído;
- sincronização de metadados.

### WPGraphQL

Referência para:

- modelagem tipada;
- conexões;
- paginação;
- controle de exposição de estados privados.

Nenhum desses projetos deve ser copiado diretamente. A utilidade está nos padrões já testados em ecossistemas WordPress grandes.

## Ordem recomendada das próximas rodadas

1. revisar inventário real em `/sistema/wordpress`;
2. revisões nativas e restauração;
3. menus WordPress → header/footer com fallback;
4. mídia responsiva baseada em attachment metadata;
5. redirects automáticos ao alterar slug;
6. perfis públicos de autor;
7. comentários públicos, se houver decisão editorial;
8. proveniência/importação e sincronização;
9. arquivos por autor/taxonomia;
10. cache de leitura e otimizações de consultas com base em métricas reais.
