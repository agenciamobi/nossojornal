# Nosso Jornal - Evolução Canônica

**Última atualização:** 25/09/2026  
**Repositório:** `agenciamobi/nossojornal`  
**Domínio:** `nossojornal.com.br`  
**Estado:** fundação da nova aplicação

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

- source do portal: pronto;
- receita detectada: `vite_spa` + `npm_ci` + `dist`;
- persistent paths no Core: implementados;
- primeiro Release Runner da conta `nossojornal`: ainda não provisionado;
- primeiro cutover: pendente do provisionamento e readback do runner.

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

Estado: **EM ANDAMENTO**

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

Estado: **PLANEJADA**

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

Estado: **PLANEJADA**

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

Estado: **PLANEJADA**

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

Estado: **PLANEJADA**

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

Estado: **PLANEJADA**

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

Estado: **PLANEJADA**

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
