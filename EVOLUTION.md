# Nosso Jornal - Evolução Canônica

**Última atualização:** 25/09/2026  
**Repositório:** `agenciamobi/nossojornal`  
**Domínio:** `nossojornal.com.br`  
**Estado:** Header e homepage conectados ao legado real; aguardando publicação e validação visual

Este é o único documento de evolução do projeto. Planos, decisões arquiteturais, fases, pendências e mudanças de direção devem ser consolidados aqui.

## 1. Visão do produto

O Nosso Jornal será reconstruído como um portal de notícias local e regional moderno, com foco em leitura, velocidade, credibilidade editorial, descoberta de conteúdo, SEO para notícias e operação simples da redação.

O novo produto não será um theme WordPress. O WordPress antigo deixa de ser runtime e passa a ser uma fonte histórica de dados durante a transição.

Objetivos centrais:

- homepage editorial organizada por relevância;
- matérias com excelente experiência de leitura;
- editorias e arquivos navegáveis;
- busca eficiente;
- preservação do acervo histórico;
- painel editorial próprio;
- classificados e comunicados como produtos editoriais específicos;
- infraestrutura preparada para publicidade, newsletter, push e distribuição social;
- SEO, Google News, dados estruturados e descoberta por mecanismos de resposta;
- integração operacional com MOBI Core sem expor complexidade ao leitor.

## 2. Referência visual de partida

A primeira capa toma como referência estrutural o portal existente mostrado pelo operador em 25/09/2026:

1. faixa superior e masthead;
2. navegação por editorias;
3. grande área de destaque;
4. bloco de matérias mais recentes;
5. espaço para colunas e parceiros;
6. faixa institucional de apoiadores;
7. grade de notícias recentes;
8. classificados;
9. comunicados oficiais;
10. footer editorial.

A nova implementação não copia o frontend antigo. Ela reaproveita a hierarquia editorial e reconstrói tudo com um sistema visual mais limpo, responsivo e performático.

## 3. Estado inicial conhecido

### Hospedagem

- cPanel ativo na conta `nossojornal`;
- target MOBI Core: `755d8037-c644-464f-99a0-99a8778aebd5`;
- repositório canônico: `repositories/nossojornal`;
- produção: `public_html`;
- branch: `main`;
- deployment contract registrado no Core.

### Banco legado

- MariaDB 10.5.x;
- banco histórico: `nossojornal_wp262`;
- prefixo WordPress: `njsite_`;
- conteúdo histórico em posts, postmeta, termos, taxonomias e usuários;
- nova aplicação reservará prefixo `njapp_`.

### Mídia

O acervo histórico continuará inicialmente em:

```text
public_html/wp-content/uploads/
```

As URLs históricas de mídia devem continuar válidas após o cutover.

### Estado do release

Em 25/09/2026 o MOBI Core recebeu a correção do Release Runner para tratar caminhos persistentes no deploy Vite.

O contrato seguro passou a preservar e verificar:

```text
.well-known/
wp-content/uploads/
```

Além disso, o backup estático passou a excluir esses caminhos persistentes, evitando duplicar o acervo de mídia em uma conta cPanel com pouco espaço livre. O backup continua cobrindo os arquivos gerenciados pela aplicação e o acervo persistente permanece intacto no document root.

A Edge `deployment-release-runner-provision` foi atualizada para provisionar o worker pinado com essa correção.

Estado atual:

- source do portal: publicado em produção;
- receita detectada: `vite_spa` + `npm_ci` + `dist`;
- persistent paths no Core: implementados e preservados;
- Release Runner da conta `nossojornal`: operacional;
- deploy canônico já executado com readback de SHA e homepage HTTP 200;
- API PHP publicada e conectada ao MariaDB legado com usuário runtime read-only.

## 4. Identidade visual

A identidade deriva do logotipo atual.

Paleta-base extraída do arquivo fornecido:

```text
#30323B  carvão azulado
#1D3261  marinho editorial
#0A3284  azul institucional
#6597F8  azul de destaque
#FDFDFD  branco
```

Direção:

- jornal digital, não painel administrativo;
- masthead forte;
- superfícies claras para leitura;
- headlines com personalidade;
- azul institucional em marca, navegação, destaques e links;
- imagens grandes em manchetes e reportagens;
- divisórias finas e grids editoriais;
- responsividade mobile-first;
- fontes de sistema na fundação, com webfonts avaliadas depois por peso e performance.

## 5. Arquitetura alvo

```text
Leitor
  |
  v
nossojornal.com.br
React + TypeScript + Vite
  |
  v
API pública do Nosso Jornal
  |
  +--> conteúdo novo normalizado -> njapp_*
  |
  +--> arquivo legado normalizado -> njsite_* durante transição
  |
  v
MariaDB
```

Princípios:

- frontend não conhece credenciais;
- frontend não conhece schema WordPress;
- API expõe contrato editorial estável;
- legado e conteúdo novo coexistem atrás do mesmo DTO;
- migração ocorre progressivamente sem quebrar URLs;
- WordPress não é dependência para servir o portal.

## 6. Stack

### Frontend

- React 18;
- TypeScript;
- Vite;
- CSS editorial próprio;
- build estático em `dist/`.

### API

Direção preferencial no cPanel atual:

- PHP 8.4 server-side para endpoints HTTP leves;
- PDO com prepared statements;
- autenticação separada para endpoints administrativos;
- respostas JSON;
- cache HTTP quando seguro.

Isso evita exigir processo Node persistente no shared hosting. Se o ambiente evoluir, a API pode ser reavaliada sem alterar o contrato consumido pelo frontend.

### Banco

- MariaDB existente;
- usuário runtime próprio com privilégio mínimo;
- `njsite_*` como legado;
- `njapp_*` como domínio novo.

## 7. Modelo editorial novo proposto

Tabelas candidatas:

```text
njapp_articles
njapp_article_revisions
njapp_categories
njapp_article_categories
njapp_tags
njapp_article_tags
njapp_authors
njapp_media
njapp_home_sections
njapp_breaking_news
njapp_redirects
njapp_advertisers
njapp_ad_slots
njapp_ads
njapp_classifieds
njapp_official_notices
njapp_newsletter_subscribers
njapp_push_subscriptions
njapp_notifications
njapp_social_queue
njapp_article_metrics
njapp_api_tokens
njapp_audit_log
```

Não criar tudo de uma vez. Cada tabela nasce quando uma fase funcional exigir, com migration versionada.

Contrato lógico mínimo de artigo:

```text
id
legacy_id?
slug
title
subtitle?
excerpt?
body_html
status
author
categories[]
tags[]
featured_media
published_at
updated_at
canonical_url
seo_title?
seo_description?
source
```

`source` poderá indicar `legacy_wordpress` ou `native` internamente, mas o frontend não ramifica UX por origem.

## 8. Rotas públicas previstas

```text
/
 /ultimas
 /categoria/:slug
 /noticia/:slug
 /autor/:slug
 /busca
 /classificados
 /comunicados
 /sobre
 /contato
 /404
```

Compatibilidade com slugs históricos será decidida a partir do inventário real do banco.

## 9. Plano completo por fases

### Fase 0 - Fundação

Estado: **CONCLUÍDA**

Entregas:

- Vite + React + TypeScript;
- identidade visual inicial;
- home editorial de demonstração;
- `AGENTS.md`;
- `EVOLUTION.md`;
- lockfile determinístico;
- build `dist/`;
- regra explícita de mídia persistente.

Gate:

- `npm ci`;
- `npm run typecheck`;
- `npm run build`.

### Fase 1 - Design system editorial

Estado: **EM ANDAMENTO**

Construir:

- masthead;
- navegação;
- headline principal;
- cards de matéria;
- listas compactas;
- badge de editoria;
- timestamps;
- byline;
- banners publicitários;
- blocos de classificados;
- comunicados oficiais;
- newsletter;
- footer;
- skeletons e estados vazios.

Definir:

- escala tipográfica;
- grid;
- espaçamento;
- breakpoints;
- proporções de mídia;
- estados de hover/focus;
- contraste;
- orçamento de movimento.

### Fase 2 - Inventário do legado

Estado: **EM ANDAMENTO**

Ler e documentar:

- posts publicados;
- páginas;
- categorias;
- tags;
- autores;
- imagens destacadas;
- anexos;
- custom post types úteis;
- slugs;
- redirects necessários;
- metadados SEO;
- volume por ano;
- HTML problemático;
- conteúdo órfão.

Resultado:

- mapa de compatibilidade `njsite_* -> DTO editorial`;
- lista de exceções;
- mapa inicial de redirects.

### Fase 3 - API pública de leitura

Estado: **EM ANDAMENTO**

Criar endpoints server-side:

```text
GET /api/v1/home
GET /api/v1/articles
GET /api/v1/articles/:slug
GET /api/v1/categories
GET /api/v1/categories/:slug/articles
GET /api/v1/authors/:slug
GET /api/v1/search
GET /api/v1/classifieds
GET /api/v1/notices
```

Requisitos:

- PDO;
- prepared statements;
- paginação;
- cache;
- ETag/Last-Modified quando aplicável;
- normalização do HTML;
- sem exposição de schema físico;
- rate limit para busca.

### Fase 4 - Conteúdo real no frontend

Estado: **EM ANDAMENTO**

Substituir demonstração por API real:

- capa;
- últimas notícias;
- editorias;
- matéria;
- categoria;
- autor;
- busca;
- classificados;
- comunicados.

A home deve ser orientada por configuração editorial, não apenas pela ordem cronológica.

### Fase 5 - Página de matéria

Estado: **EM ANDAMENTO**

Recursos:

- headline;
- subtítulo;
- byline;
- data e atualização;
- imagem principal;
- legenda e crédito;
- corpo tipográfico;
- embeds allowlisted;
- compartilhamento;
- matérias relacionadas;
- tags;
- correções;
- publicidade não intrusiva;
- breadcrumbs;
- JSON-LD `NewsArticle`.

### Fase 6 - SEO, Google News e descoberta

Estado: **PLANEJADA**

Entregas:

- canonical;
- title/description;
- Open Graph;
- Twitter cards;
- `NewsArticle`;
- `BreadcrumbList`;
- `Organization`;
- sitemap geral;
- sitemap de notícias;
- RSS/Atom;
- redirects 301;
- robots;
- políticas de indexação;
- estratégia de prerender para páginas críticas;
- páginas de autor e organização com sinais editoriais claros.

### Fase 7 - Novo domínio editorial no banco

Estado: **PLANEJADA**

Criar `njapp_*` conforme necessidade.

Objetivo:

- novas matérias podem nascer fora do WordPress;
- revisão e publicação ganham workflow próprio;
- conteúdo legado continua legível;
- migração pode ser gradual.

### Fase 8 - Painel editorial

Estado: **PLANEJADA**

Construir área autenticada:

- dashboard;
- matérias;
- rascunhos;
- revisão;
- agendamento;
- autores;
- categorias;
- tags;
- mídia;
- capa/home;
- breaking news;
- classificados;
- comunicados;
- publicidade;
- redirects;
- auditoria.

Perfis iniciais:

```text
admin
editor
reporter
commercial
```

### Fase 9 - Mídia

Estado: **PLANEJADA**

Curto prazo:

- consumir `/wp-content/uploads/`.

Depois:

- catálogo `njapp_media`;
- metadados;
- alt text;
- crédito;
- variantes responsivas;
- política de retenção;
- uploads novos independentes do WordPress.

A migração física do acervo só ocorre quando houver ganho claro e mapa de URL seguro.

### Fase 10 - Comercial

Estado: **PLANEJADA**

Publicidade:

- anunciantes;
- slots;
- campanhas;
- datas;
- criativos;
- impressões/cliques;
- regras por editoria.

Classificados:

- publicação;
- expiração;
- contato;
- imagem;
- categoria;
- moderação.

Comunicados oficiais:

- área própria;
- filtros;
- arquivo;
- documento/anexo quando necessário.

### Fase 11 - Newsletter, push e distribuição

Estado: **PLANEJADA**

- newsletter;
- listas e consentimento;
- web push;
- alertas de breaking news;
- fila social;
- cards para redes;
- integrações via MOBI Core;
- rastreamento de origem e campanha.

### Fase 12 - Busca e descoberta

Estado: **EM ANDAMENTO**

Primeiro:

- busca SQL segura e paginada.

Depois, se volume justificar:

- índice dedicado;
- sinônimos;
- relevância;
- conteúdo relacionado;
- busca por entidade e localidade.

### Fase 13 - Performance, observabilidade e segurança

Estado: **PLANEJADA**

- budgets de JS/CSS;
- Core Web Vitals;
- monitoramento de erros;
- logs estruturados;
- health endpoint;
- uptime;
- rate limiting;
- CSP;
- headers de segurança;
- sanitização HTML;
- backup;
- restore testado;
- auditoria administrativa.

### Fase 14 - Cutover

Estado: **BLOQUEADA**

Pré-condições:

1. release preserva `wp-content/uploads/`;
2. build reproduzível;
3. API real validada;
4. home e matéria validadas em mobile/desktop;
5. redirects preparados;
6. sitemap e canonical prontos;
7. backup completo;
8. smoke tests;
9. rollback testado.

Cutover:

- gerar backup;
- publicar artefato;
- preservar uploads;
- validar homepage;
- validar amostra de matérias históricas;
- validar imagens;
- validar robots/sitemaps;
- monitorar 404 e 5xx.

### Fase 15 - Pós-cutover

Estado: **PLANEJADA**

- monitorar 404;
- corrigir redirects;
- acompanhar indexação;
- remover runtime WordPress residual quando seguro;
- reduzir plugins e PHP legado;
- manter apenas o que ainda for necessário ao acervo até migração final;
- documentar encerramento do legado.

## 10. Home editorial inicial

A fundação visual deve conter:

1. barra utilitária;
2. masthead;
3. menu de editorias;
4. faixa de última hora;
5. manchete principal;
6. notícias secundárias;
7. últimas notícias;
8. colunas/opinião;
9. publicidade;
10. apoiadores;
11. classificados;
12. comunicados;
13. newsletter;
14. footer.

Enquanto a API não existir, os textos são explicitamente de demonstração e não representam notícias reais.

## 11. Política de dados de demonstração

A fundação visual pode usar conteúdo fictício apenas quando marcado como demonstração.

É proibido:

- atribuir fala a pessoa real;
- inventar acidente, crime, morte, eleição, denúncia ou evento real;
- usar título fictício sem aviso de demonstração;
- publicar o mock em produção como conteúdo editorial.

## 12. Critério de sucesso da primeira etapa

A primeira etapa termina quando:

- repositório possui app Vite funcional;
- identidade visual está aplicada;
- home demonstra a hierarquia do portal;
- build é reproduzível;
- MOBI Core reconhece receita `vite_spa`;
- nenhum release é executado até persistent paths estarem suportados.


## 13. Header: leitura real do legado e primeira API

Leitura do snapshot `nossojornal_wp262.sql` concluída para o domínio necessário ao Header.

### Taxonomia real

A taxonomia WordPress `category` contém 25 termos: 16 categorias raiz e 9 filhas. `Cobertura Regional` é o agrupador das cidades Hulha Negra, Bagé, Aceguá, Candiota, Dom Pedrito, Herval, Pedras Altas, Pinheiro Machado e Piratini.

O snapshot possui 34 posts publicados. As categorias com maior presença são Hulha Negra (32), Geral (17) e Política (12). As contagens do frontend não serão congeladas: a API recalcula contra `post_type=post` e `post_status=publish`.

### Menu legado

O Porto registrava `main_menu=70` e `top_nav=28`. O menu principal histórico tinha oito itens: Início, Quem Somos, Notícias, quatro filhos de Notícias (Outros, Política, Esportes e Hulha Negra) e Contato. Isso é tratado como evidência histórica, não como política editorial obrigatória do novo Header.

### API criada

Endpoints iniciais:

```text
GET /api/v1/health.php
GET /api/v1/categories.php
GET /api/v1/categories.php?include_empty=0
GET /api/v1/latest.php?limit=6
```

`latest.php` expõe as notícias publicadas mais recentes do legado, ordenadas por data e ID, sem posts protegidos por senha. O contrato retorna título normalizado, slug, URL editorial, data de publicação e data de atualização. O limite aceito é de 1 a 20 itens.

`categories.php` retorna coleção flat e árvore hierárquica com id, taxonomyId, nome, slug, parentId, URL normalizada, legacyCount, publishedCount e latestPublishedAt.

A API é PHP 8.4 + PDO, somente leitura nesta fase. O frontend não consulta `njsite_*` diretamente.

### Credenciais

Credenciais não são versionadas. O runtime procura configuração server-side fora do document root em:

```text
~/.mobi/nossojornal-api.php
```

Também aceita `NJ_DB_HOST`, `NJ_DB_PORT`, `NJ_DB_NAME`, `NJ_DB_USER`, `NJ_DB_PASSWORD` e `NJ_DB_PREFIX`.

O usuário runtime deverá ter somente `SELECT` nas tabelas legadas necessárias.

### Estado validado do Header

Em produção, `GET /api/v1/health.php` confirmou `database=reachable` e 25 categorias legadas. `GET /api/v1/categories.php?include_empty=1` confirmou 16 categorias raiz, 9 cidades filhas de `Cobertura Regional` e a árvore hierárquica completa.

O Header deixou de usar o array estático de editorias. A navegação principal agora consome a API real e mantém apenas a política editorial de apresentação no frontend. Categorias técnicas ou históricas de organização (`Capa`, `Geral`, `Outros` e `Eleições 2024`) ficam fora da barra principal sem serem removidas da taxonomia.

`Últimas` permanece como rota editorial do produto, não como categoria do banco. `Cobertura Regional` recebe uma faixa secundária alimentada pelos filhos reais da taxonomia: Aceguá, Bagé, Candiota, Dom Pedrito, Herval, Hulha Negra, Pedras Altas, Pinheiro Machado e Piratini.

O Header agora também abandona os textos de projeto/demonstração. A barra utilitária usa links públicos reais (`/sobre`, `/classificados`, `/comunicados`, `/contato`, `/busca`), informa a edição regional e a data atual. O masthead assume linguagem editorial regional.

A antiga faixa `EM DESENVOLVIMENTO` foi removida. Em seu lugar, o Header consome `/api/v1/latest.php?limit=6` e exibe as seis notícias publicadas mais recentes em ticker horizontal contínuo, com looping infinito, pausa em hover/foco e fallback acessível para `prefers-reduced-motion`.

Próximo gate do Header:

1. publicar a nova `main`;
2. validar `latest.php?limit=6` contra o MariaDB live;
3. validar ticker, links, editorias e cobertura regional em desktop/mobile;
4. encerrar a seção Header antes de avançar para a manchete/capa.


## 14. Homepage editorial real

A homepage deixou de depender de conteúdo demonstrativo e passa a ser montada por `GET /api/v1/home.php`.

### Política editorial da capa

- `Capa` é a categoria de controle editorial do hero;
- quando houver matéria publicada em `Capa`, a mais recente dessa categoria assume a manchete principal;
- enquanto `Capa` estiver vazia, a publicação mais recente do jornal é usada como fallback;
- o hero expõe título, resumo real, autor, data, categoria primária e imagem destacada quando existir.

### Últimas notícias

A home recebe as oito publicações seguintes à manchete, em ordem cronológica decrescente. O Header continua usando o endpoint enxuto `/api/v1/latest.php?limit=6` para o ticker.

### Mais lidas

A coluna `Mais lidas` usa o metadado legado `views`, sem estimativas artificiais. Apenas matérias com contador maior que zero participam do ranking.

### Seções por editoria

As seções são derivadas das categorias efetivamente associadas a posts publicados. Categorias técnicas ou históricas de organização não criam seção própria:

```text
Capa
Geral
Outros
Eleições 2024
Cobertura Regional
```

As demais categorias com conteúdo publicado geram automaticamente blocos editoriais. No snapshot atual isso inclui, entre outras, Hulha Negra, Política, Educação, Rural, Economia, Segurança, Esportes, Saúde e Internacional.

Cada bloco usa até quatro matérias reais e preserva a relação original post ↔ categoria. Uma matéria pode aparecer no hero e também em sua editoria, comportamento aceitável em uma capa jornalística e necessário para não ocultar editorias com pouco volume.

### Imagens

A API resolve `_thumbnail_id` e o attachment correspondente. Quando a imagem pertence ao acervo local, a URL é normalizada para `/wp-content/uploads/...`, preservando o caminho persistente durante releases do frontend.

### Conteúdo fictício

Os blocos demonstrativos anteriores de manchete, recentes, apoiadores, classificados e comunicados foram removidos da homepage. Produtos sem fonte de dados real não recebem conteúdo inventado.


## 15. Páginas internas

A estrutura pública agora resolve rotas internas sem adicionar dependência de roteador cliente. O Apache continua entregando o app Vite em modo history e o frontend seleciona a experiência pelo pathname.

### Rotas implementadas

```text
/noticia/:slug
/categoria/:slug
/ultimas
/busca
/sobre
/contato
/classificados
/comunicados
/404
```

Compatibilidade histórica inicial:

```text
/:slug               -> tenta resolver matéria legada
/noticias            -> /ultimas
/quem-somos          -> conteúdo de /sobre
```

Matérias históricas servidas por `/:slug` mantêm canonical na rota editorial nova `/noticia/:slug`.

### API interna criada

```text
GET /api/v1/article.php?slug=:slug
GET /api/v1/articles.php
GET /api/v1/articles.php?category=:slug
GET /api/v1/articles.php?q=:termo
GET /api/v1/page.php?slug=:slug
```

`_content.php` concentra normalização de artigos, autores, categorias, imagens e HTML legado. O frontend continua sem conhecer o schema WordPress.

### Página de matéria

A matéria inclui:

- breadcrumbs;
- editoria;
- headline;
- resumo;
- autor;
- publicação e atualização;
- imagem destacada;
- corpo legado sanitizado;
- categorias relacionadas;
- compartilhamento;
- notícias relacionadas;
- canonical;
- Open Graph;
- JSON-LD `NewsArticle`.

Scripts, formulários, handlers inline e embeds arbitrários são removidos do HTML legado nesta primeira versão. Embeds serão reintroduzidos posteriormente por allowlist.

### Editorias e últimas

`/categoria/:slug` e `/ultimas` usam o mesmo endpoint paginado. A listagem oferece cards editoriais com imagem, título, resumo, data e autoria. A paginação preserva query string e filtros.

### Busca

`/busca?q=` pesquisa título, resumo e corpo publicados, com paginação. A busca vazia apresenta somente o formulário e não dispara consulta ampla desnecessária.

### Institucional

`/sobre` reutiliza a página legada `quem-somos`. `/contato` reutiliza a página publicada `contato`. O HTML passa pelo mesmo sanitizador usado em conteúdo legado.

### Classificados e comunicados

As rotas e layouts existem, mas permanecem explicitamente sem registros até o inventário dos tipos de conteúdo específicos ser concluído. Nenhum anúncio, classificado ou comunicado fictício é exibido.

### Próximo gate

1. publicar a nova `main`;
2. validar sintaxe PHP dos novos endpoints em runtime;
3. testar uma matéria histórica por `/noticia/:slug` e por `/:slug`;
4. testar editoria, últimas, busca, sobre e contato;
5. corrigir HTML legado que exigir adaptadores específicos;
6. inventariar fontes reais de classificados e comunicados.


## 16. Refinamento editorial das internas

A segunda passada das páginas internas aplica linguagem de revista e reduz dependência visual do WordPress legado.

### Matéria

A página de notícia passa a oferecer:

- headline e deck com escala editorial;
- autoria, publicação e atualização em bloco próprio;
- estimativa de tempo de leitura;
- visualizações quando o legado possuir contador;
- compartilhamento por WhatsApp, Web Share API e cópia de link;
- imagem principal em escala ampla;
- corpo com tipografia para leitura longa e drop cap no primeiro parágrafo;
- rail de editorias;
- retorno para a editoria principal;
- relacionadas ao fim da leitura.

A camada de descoberta também inclui Open Graph editorial, Twitter Card, datas de publicação/atualização, seção, canonical, JSON-LD `NewsArticle` e `BreadcrumbList`.

### HTML legado

O sanitizador compartilhado remove scripts, formulários, iframes arbitrários, handlers inline, estilos inline, SVGs de widgets e atributos específicos do Elementor. URLs de mídia do acervo continuam normalizadas para `/wp-content/uploads/...`.

### Sobre

`/sobre` continua usando o conteúdo histórico real de `quem-somos`, mas passa a apresentá-lo em composição editorial própria, com texto principal e rail de marcos institucionais.

### Contato

`/contato` deixa de despejar o layout Elementor. A API extrai os canais públicos da própria página legada e o frontend os apresenta em cards nativos. Assim, telefone, WhatsApp e e-mail continuam derivados da fonte histórica, sem duplicação manual de configuração.

### Robustez HTTP

Erros de slug, categoria, matéria ou página inexistente agora usam `NjApiHttpException` e retornam códigos HTTP explícitos em vez de cair em `internal_error`.


## 17. Normalização editorial do acervo

A análise de uma matéria longa do acervo revelou shortcodes, captions, imagens em sequência e subtítulos armazenados como `<strong>`. A API passa a interpretar esse legado antes de entregar conteúdo ao React.

### Indexação pública

O shell Vite deixa de usar `noindex,nofollow` e passa a publicar:

```text
index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1
```

Os endpoints JSON continuam com `X-Robots-Tag: noindex, nofollow`.

### Normalizador de matérias

`_content.php` passa a:

- remover shortcodes residuais de excerpts e metadados;
- reconhecer vídeos YouTube do shortcode `[embedyt]`;
- converter captions legadas;
- transformar blocos de texto solto em parágrafos;
- promover subtítulos legados em headings reais;
- gerar IDs estáveis e sumário da matéria;
- extrair sequências iniciais de imagens para galeria;
- deduplicar da galeria a imagem já usada como destaque;
- aplicar lazy loading às imagens internas;
- normalizar URLs do acervo para `/wp-content/uploads/...`;
- marcar links externos com atributos seguros.

O contrato de uma matéria pode incluir:

```json
{
  "contentHtml": "...",
  "toc": [],
  "gallery": [],
  "videos": []
}
```

### Galeria

O frontend apresenta até quatro imagens no mosaico inicial. Fotografias adicionais ficam em expansão acessível por `details/summary`, mantendo todas as imagens do acervo sem transformar a leitura em uma coluna interminável.

### Vídeo

Vídeos reconhecidos são renderizados em iframe responsivo via `youtube-nocookie.com`, com loading lazy e permissões explicitamente limitadas.

### Sumário

Headings normalizados alimentam o rail `Nesta matéria`. As categorias passam a ocupar um bloco separado chamado `Editorias`.

### Sobre

O deck duplicado da página Sobre foi removido e a marca histórica recebeu limite de largura para não dominar a composição.

### Contato

A API agora consulta também `_elementor_data` da página de contato em modo somente leitura. O parser extrai links e valores de WhatsApp, telefone, e-mail e localização. Quando há mais de um e-mail legado, o frontend prefere o endereço institucional `@nossojornal.com.br` quando disponível.

### Próximo gate

1. sincronizar a `main`;
2. inspecionar código;
3. revalidar deploy;
4. publicar;
5. validar uma matéria longa, Sobre e Contato;
6. só depois ligar o shell server-side de metadados por rota.


## 18. Metadados server-side e consistência editorial

A rodada posterior à validação visual corrige inconsistências detectadas em produção e adiciona entrega de metadados antes da hidratação React.

### Resumos e cache

Home e arquivos usam o mesmo normalizador editorial de `_content.php`. O frontend mantém uma segunda defesa contra shortcodes legados em cards e decks. As janelas de cache de home, últimas e arquivos foram reduzidas para:

```text
max-age=10
stale-while-revalidate=30
```

### Datas

Datas editoriais vindas do WordPress são normalizadas no backend em ISO 8601 com timezone `America/Sao_Paulo`.

O Header gera tanto o texto visível quanto o atributo `datetime` no mesmo calendário de Brasília, evitando divergência de dia causada por UTC.

Home e páginas internas também formatam publicação no timezone editorial, independentemente do fuso do navegador do leitor.

### Galeria

Quando existem fotos adicionais além das quatro exibidas inicialmente, a última miniatura do mosaico recebe overlay `+N fotos`. A expansão completa continua disponível via `details/summary`.

### Shell server-side

O build passa a copiar:

```text
public/meta.php
public/.htaccess
```

`.htaccess` preserva arquivos físicos, diretórios persistentes e endpoints `/api/`, encaminhando apenas rotas públicas não físicas para `meta.php`.

`meta.php` carrega o `index.html` compilado e substitui o bloco `NJ_META_START/NJ_META_END` antes da resposta.

Para matérias, o primeiro HTML já contém:

- title;
- description;
- robots;
- canonical;
- Open Graph;
- Twitter Card;
- imagem e alt;
- article:published_time;
- article:modified_time;
- article:section;
- JSON-LD NewsArticle;
- JSON-LD BreadcrumbList.

Rotas históricas `/:slug` recebem canonical em `/noticia/:slug`.

Busca permanece `noindex,follow`. Classificados e Comunicados também permanecem `noindex,follow` enquanto ainda não possuem fonte editorial final.

Rotas ou editorias inexistentes passam a receber HTTP 404 no shell, além da experiência de erro do React.

O JSON-LD server-side é marcado com `data-nj-server-jsonld`; o React só injeta seu fallback quando esse bloco não existir, evitando structured data duplicado.

### Gate de produção

1. sincronizar código;
2. inspecionar código;
3. revalidar deploy;
4. publicar;
5. confirmar que a raiz continua HTTP 200;
6. abrir `view-source:` de uma matéria e confirmar metadados antes do JavaScript;
7. validar uma URL inválida retornando HTTP 404;
8. validar Header e cards sem resíduos de shortcode.


## 19. Sistema cromático por editoria

As categorias passam a carregar identidade cromática própria.

### Autoridade

A cor persistida usa a tabela padrão WordPress `termmeta`:

```text
meta_key = nj_editorial_color
```

A API consulta o valor persistido primeiro e usa a paleta canônica do código como fallback.

Cada categoria expõe:

```json
{
  "color": "#6D28D9",
  "colorSource": "termmeta"
}
```

`colorSource=palette` indica que o banco ainda não possui override.

### Aplicação visual

- Header: underline cromático e estado ativo da editoria;
- cobertura regional: marcador colorido por município;
- homepage: badges, hovers e filete superior de cada seção;
- cards: interação respeita a editoria da própria matéria;
- página de categoria: filete e regra editorial assumem a cor;
- matéria: kicker, marcador superior, drop cap, links, blockquotes, sumário e relacionadas usam o contexto da editoria.

A cor funciona como código de navegação. Fundo branco, navy e tipografia permanecem como identidade principal do Nosso Jornal.

### Persistência preparada

```text
database/category-editorial-colors.sql
docs/EDITORIAL_COLORS.md
```

O SQL é reexecutável e altera somente a chave `nj_editorial_color` nos termos conhecidos.

Enquanto o MOBI Core ainda expuser MySQL como read-only, a paleta funciona integralmente via fallback em código. Assim que SQL write estiver disponível, persistir os valores em `termmeta` não exige mudança no frontend.


## 20. Painel administrativo /sistema

O portal passa a possuir um painel administrativo próprio em:

```text
/sistema
```

O MVP mantém a linguagem operacional do wp-admin sem reutilizar sua interface ou depender do WordPress em runtime.

### Fonte de identidade

A autenticação reutiliza as contas existentes em:

```text
njsite_users
njsite_usermeta
```

Roles e capabilities são derivadas de:

```text
njsite_capabilities
njsite_user_roles
```

Nenhuma tabela paralela de usuário é criada.

O verificador de senha suporta:

- hashes WordPress modernos `$wp$2y$...`;
- bcrypt nativo;
- hashes portáteis legados `$P$` / `$H$`.

### Segurança

O painel usa:

- sessão PHP própria;
- cookie Secure + HttpOnly + SameSite=Lax;
- regeneração de session id no login;
- vínculo leve com User-Agent;
- CSRF token para mutations administrativas;
- endpoints com `Cache-Control: no-store`;
- `X-Robots-Tag: noindex,nofollow`;
- checagem de capabilities por endpoint;
- resposta genérica para credenciais inválidas.

A rota pública `/sistema` também recebe `noindex,nofollow` no shell server-side.

### Endpoints administrativos

```text
POST /api/admin/login.php
GET  /api/admin/session.php
POST /api/admin/logout.php
GET  /api/admin/dashboard.php
GET  /api/admin/posts.php
GET  /api/admin/categories.php
GET  /api/admin/users.php
```

### Interface MVP

Menu lateral:

- Painel;
- Notícias;
- Categorias;
- Usuários.

Dashboard:

- publicadas;
- rascunhos;
- categorias;
- usuários;
- posts recentes;
- pendentes/agendados;
- comentários pendentes.

Notícias:

- paginação;
- busca;
- filtros por status;
- autor;
- categorias;
- link para publicação no site.

Categorias:

- nome;
- slug;
- parent;
- cor editorial;
- origem da cor (`termmeta` ou fallback);
- contagem;
- link público.

Usuários:

- display name;
- login;
- e-mail;
- roles;
- data de cadastro.

### Estado de escrita

O MVP é deliberadamente read-only.

O usuário MySQL runtime atual do portal possui leitura do legado e o MOBI Core ainda reporta `raw_sql_available=false` / `database_write_authorized=false`.

Quando write for homologado, o próximo estágio deve adicionar:

1. criar/editar notícia;
2. salvar rascunho;
3. publicar/agendar;
4. editar categoria e cor;
5. criar/editar usuário;
6. mídia/upload;
7. autosave/revisions.


## 20. Painel administrativo MVP

O novo portal passa a possuir administração própria em `/sistema`, inspirada na ergonomia do wp-admin e conectada aos usuários/capabilities reais do WordPress legado.

### Autoridade

Frontend:

```text
src/AdminApp.tsx
src/admin.css
```

API:

```text
/api/admin/*
```

A implementação paralela `/api/v1/system/*` foi removida para manter uma única sessão e um único contrato.

### Áreas do MVP

```text
/sistema
/sistema/noticias
/sistema/categorias
/sistema/midia
/sistema/usuarios
/sistema/configuracoes
```

### Segurança

O login reutiliza `njsite_users`, roles e capabilities WordPress, sem expor hashes. Acesso editorial exige `edit_posts`. Sessões usam cookies seguros, user-agent binding e CSRF para mutations. Endpoints administrativos são no-store/noindex e aplicam capability checks por superfície.

### Estado

O primeiro release é read-only. O CRUD será aberto somente após homologação do MySQL write.

Especificação detalhada: `docs/ADMIN_MVP.md`.


## 21. Mesa de Pautas exclusiva

A rota administrativa:

```text
/sistema/pautas
```

é exclusiva do usuário WordPress:

```text
user_login === 'agenciamobi'
```

A restrição existe em duas camadas:

1. o menu só é renderizado no painel quando o usuário autenticado possui `login === 'agenciamobi'`;
2. `GET /api/admin/pautas.php` executa a mesma validação no backend e retorna `403 pautas_access_denied` para qualquer outro usuário, inclusive outro administrador.

A API administrativa expõe:

```text
permissions.managePautas
```

derivada diretamente do `user_login`.

### Fundação da Mesa

O MVP já possui catálogo inicial de fontes RSS para:

- Pelotas;
- tecnologia;
- inteligência artificial;
- universo;
- ciência;
- curiosidades;
- pesquisa em IA.

O fluxo definido é:

```text
RSS → Captura → Mesa de Pautas → Seleção → Pesquisa → Redação → Draft → Revisão → Agendamento
```

O endpoint informa também as tabelas planejadas:

```text
nj_feed_sources
nj_news_queue
```

A persistência e as ações `Ignorar | Salvar | Produzir matéria` permanecem aguardando write homologado no banco.


## 22. Sidebar refinada e primeiras mutations do admin

A sidebar do `/sistema` deixa de usar letras dentro de caixas e passa a usar ícones SVG vetoriais próprios para:

- Painel;
- Notícias;
- Categorias;
- Mídia;
- Usuários;
- Mesa de Pautas;
- Configurações.

A navegação também passa a ter agrupamentos semânticos:

```text
Conteúdo
Gestão
Sistema
```

As telas filhas de Notícias e Categorias mantêm o item-pai ativo na sidebar.

### Editor individual de notícia

Nova rota:

```text
/sistema/noticias/:id
```

Novo endpoint:

```text
GET /api/admin/post.php?id=:id
```

O editor carrega:

- título;
- slug;
- resumo;
- conteúdo bruto;
- status;
- autor;
- datas;
- categorias;
- imagem destacada;
- metadados Yoast disponíveis.

### Editor individual de categoria

Nova rota:

```text
/sistema/categorias/:id
```

Novo endpoint:

```text
GET /api/admin/category.php?id=:id
```

A tela prepara edição de:

- nome;
- slug;
- descrição;
- categoria superior;
- cor editorial.

### Write readiness

Novo endpoint:

```text
GET /api/admin/write-readiness.php
```

O probe testa `SELECT / INSERT / UPDATE / DELETE` sem alterar dados. As probes de mutation usam instruções que afetam zero linhas e são executadas dentro de transação com rollback.

O Dashboard passa a mostrar o estado do runtime MySQL.

### Primeira mutation canária: cor editorial

Novo endpoint:

```text
POST /api/admin/category-color.php
```

Proteções:

- autenticação;
- `manage_categories`;
- CSRF;
- validação `#RRGGBB`;
- transaction;
- insert/update em `termmeta`;
- read-back obrigatório;
- rollback em falha.

O color picker só é habilitado quando o runtime reporta `INSERT + UPDATE`.

### Segunda mutation canária: salvar rascunho existente

Novo endpoint:

```text
POST /api/admin/post-draft.php
```

Nesta fase, somente posts com `post_status=draft` podem ser alterados.

Campos graváveis:

- título;
- resumo;
- conteúdo.

O endpoint valida tamanho, mantém o status `draft`, atualiza `post_modified`, faz read-back e nunca toca uma matéria publicada.

### Terceira mutation canária: criar nova notícia

Novo endpoint:

```text
POST /api/admin/post-create-draft.php
```

O botão `+ Nova notícia` só é habilitado quando o runtime possui `INSERT + UPDATE`.

A criação:

- usa o usuário autenticado como autor;
- cria exclusivamente `post_status=draft`;
- não publica;
- não cria slug público;
- valida o ID gerado por read-back;
- redireciona ao editor individual.

### Próximos gates

Depois da validação dessas mutations:

1. categorias e SEO do rascunho;
2. imagem destacada;
3. upload de mídia;
4. geração de slug;
5. publicação/despublicação;
6. agendamento;
7. criação e edição de usuários;
8. persistência completa da Mesa de Pautas.


## 23. Documento canônico do /sistema

A documentação consolidada do painel administrativo passa a viver em:

```text
docs/ADMIN_MVP.md
```

Esse arquivo é a referência para:

- arquitetura do `/sistema`;
- autenticação e compatibilidade com usuários WordPress;
- sessão, CSRF e segurança;
- capabilities;
- rotas;
- endpoints;
- Dashboard;
- Notícias;
- Categorias;
- Mídia;
- Usuários;
- Configurações;
- Mesa de Pautas;
- write readiness;
- mutations canárias;
- relação com o MOBI Core;
- sequência de homologação;
- próximos gates;
- fluxo de deploy.

As seções históricas deste `EVOLUTION.md` registram a ordem das mudanças. Em caso de divergência sobre o estado atual do painel, consultar primeiro `docs/ADMIN_MVP.md`.


## 24. /sistema como painel gerenciável

O painel deixa de se apresentar como ambiente de desenvolvimento e passa a assumir linguagem e capacidades de CMS.

A referência canônica atual passa a ser:

```text
docs/SISTEMA_ADMIN.md
```

`docs/ADMIN_MVP.md` permanece apenas como ponte histórica para a documentação atual.

### Limpeza da interface

Foram removidos da experiência administrativa termos internos como:

- MVP;
- read-only;
- runtime MySQL;
- write canário;
- read-back;
- fallback;
- nomes de tabela;
- meta keys;
- mensagens de homologação.

Os gates técnicos permanecem no backend, sem aparecer ao usuário.

### Notícias

O editor administrativo passa a suportar:

- título;
- slug;
- resumo;
- conteúdo;
- categorias;
- categoria principal;
- SEO;
- imagem destacada;
- salvar sem alterar status;
- publicar;
- mover para rascunho;
- agendar.

Novos endpoints:

```text
POST /api/admin/post-save.php
POST /api/admin/post-status.php
POST /api/admin/post-featured-image.php
```

### Categorias

O painel passa a suportar:

- criar categoria;
- editar nome;
- editar slug;
- editar descrição;
- editar parent;
- editar cor editorial.

Novos endpoints:

```text
POST /api/admin/category-create.php
POST /api/admin/category-save.php
```

### Mídia

A Biblioteca de Mídia passa a aceitar upload seguro de imagens para:

```text
/wp-content/uploads/YYYY/MM/
```

Endpoint:

```text
POST /api/admin/media-upload.php
```

### Usuários

Foi criada a rota:

```text
/sistema/usuarios/:id
```

Ela permite editar nome de exibição, e-mail e função conforme capabilities.

A conta `agenciamobi` é protegida contra alteração de função pelo editor comum.

Endpoints:

```text
GET  /api/admin/user.php
POST /api/admin/user-save.php
```

### Configurações

Configurações passam a ter formulário de edição allowlisted para nome do site, descrição, e-mail, paginação, fuso e formatos de data/hora.

Endpoint:

```text
POST /api/admin/settings-save.php
```

URLs e estrutura de permalink permanecem protegidas.

### Segurança

Todas as mutations novas preservam:

- sessão autenticada;
- capability check;
- CSRF;
- validação de entrada;
- transação quando aplicável;
- read-back interno;
- rollback em falha;
- ausência de secrets na resposta.

### Homologação

As capacidades de escrita estão implementadas na `main`, mas só devem ser tratadas como validadas após deploy e testes controlados no ambiente real.


## 25. Moderação, páginas e gestão de acervo

O `/sistema` recebe uma nova camada de funções administrativas.

### Comentários

Nova rota:

```text
/sistema/comentarios
```

Com suporte a:

- busca;
- pendentes;
- aprovados;
- spam;
- lixeira;
- aprovação;
- retorno para pendente;
- classificação como spam;
- restauração.

A área é controlada pela capability `moderate_comments`.

### Notícias

A aba `Todas` deixa de misturar conteúdo da lixeira.

É adicionada uma aba própria:

```text
Lixeira
```

com ações reversíveis de mover e restaurar.

### Mídia

A Biblioteca passa a ter busca e editor individual:

```text
/sistema/midia/:id
```

Campos gerenciáveis:

- título;
- alt;
- legenda;
- descrição.

O editor também mostra em quais notícias a imagem é usada como destaque.

### Páginas

Nova área:

```text
/sistema/paginas
/sistema/paginas/:id
```

Ela utiliza os registros `post_type=page` existentes e permite editar as páginas institucionais no novo painel.

As rotas estruturais de `/sobre` e `/contato` têm slug protegido para evitar quebra acidental do portal.

### Estado

Todas essas capacidades estão implementadas na `main` e devem ser consideradas pendentes de homologação até passarem pelo fluxo de deploy e testes controlados no ambiente real.
