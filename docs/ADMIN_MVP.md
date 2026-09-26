# /sistema — Painel administrativo do Nosso Jornal

> Documento canônico do painel administrativo próprio do portal.
>
> Rota: `/sistema`
>
> Frontend: `src/AdminApp.tsx` + `src/admin.css`
>
> Backend: `public/api/admin/*`

## 1. Objetivo

O `/sistema` substitui progressivamente o uso do `wp-admin` sem descartar os dados do WordPress legado.

A estratégia adotada é:

```text
React/Vite no frontend
        ↓
API administrativa PHP própria
        ↓
MariaDB legado do WordPress
        ↓
njsite_users / njsite_posts / taxonomias / mídia / options / termmeta
```

O WordPress deixa de ser a interface administrativa principal, mas o banco existente continua sendo a autoridade dos dados durante a migração.

O sistema foi desenhado para reaproveitar:

- usuários;
- senhas;
- roles;
- capabilities;
- posts;
- categorias;
- mídia;
- opções;
- metadados;
- relações taxonômicas.

Nenhuma base paralela de usuários ou conteúdo foi criada.

---

## 2. Princípios usados no desenvolvimento

### 2.1 Compatibilidade antes de substituição

O painel novo trabalha sobre o schema WordPress existente em vez de exigir uma migração imediata para um banco novo.

Isso permite desenvolver a nova aplicação em camadas:

1. leitura segura do legado;
2. autenticação própria sobre os usuários existentes;
3. administração read-only;
4. write readiness;
5. mutations pequenas e reversíveis;
6. CRUD editorial;
7. desligamento progressivo da dependência operacional do WordPress.

### 2.2 Um único contrato administrativo

A autoridade administrativa é:

```text
/api/admin/*
```

Uma implementação paralela em `/api/v1/system/*` foi descartada para não manter duas sessões, dois contratos e duas fontes de verdade.

### 2.3 Capability first

A interface não libera uma ação apenas porque o usuário está logado.

Cada superfície respeita capabilities reais herdadas do WordPress:

| Área | Capability |
| --- | --- |
| Painel | `edit_posts` |
| Notícias | `edit_posts` |
| Publicação | `publish_posts` |
| Categorias | `manage_categories` |
| Mídia | `upload_files` |
| Usuários | `list_users` / `edit_users` |
| Configurações | `manage_options` |
| Mesa de Pautas | regra exclusiva adicional para `agenciamobi` |

### 2.4 Escrita progressiva

Writes não são liberados de uma vez.

A ordem escolhida foi:

```text
cor editorial
→ salvar draft existente
→ criar novo draft
→ categorias/SEO
→ mídia
→ publicação
→ agendamento
→ usuários/configurações
```

Essa sequência reduz o raio de impacto caso uma capability de banco ainda não esteja pronta.

---

## 3. Estado validado

O MVP read-only já foi validado em produção com login real.

Foi confirmado:

- login no `/sistema`;
- sessão administrativa;
- usuário `agenciamobi`;
- role Administrador;
- Dashboard carregando dados reais;
- Notícias;
- Categorias;
- Mídia;
- Usuários;
- Configurações;
- Mesa de Pautas visível somente para `agenciamobi`;
- logout;
- navegação entre telas.

No Dashboard foram lidos dados reais do legado, incluindo publicadas, rascunhos, categorias, mídia, usuários e atividade recente.

As mutations descritas mais abaixo estão implementadas na `main`, mas dependem de write real no runtime MySQL e precisam ser homologadas antes de serem tratadas como produção validada.

---

## 4. Identidade e autenticação

O painel não cria usuários próprios.

Fonte:

```text
njsite_users
njsite_usermeta
njsite_options -> njsite_user_roles
```

O login aceita:

- `user_login`;
- `user_email`;
- senha original do WordPress.

### 4.1 Hashes suportados

O adapter de autenticação suporta:

- WordPress moderno com prefixo `$wp$2...`;
- bcrypt `$2y$`, `$2a$`, `$2b$`;
- phpass portátil `$P$` e `$H$`.

A senha e o hash nunca são devolvidos ao frontend.

### 4.2 Usuário administrativo principal

A conta administrativa usada pela MOBI é:

```text
user_login === 'agenciamobi'
```

Essa conta continua recebendo suas capabilities reais do WordPress.

Além disso, ela possui uma regra específica para a Mesa de Pautas:

```text
permissions.managePautas = true
```

somente quando:

```text
user_login === 'agenciamobi'
```

---

## 5. Sessão e segurança

A sessão administrativa é PHP própria e independente do `wp-admin`.

Características implementadas:

- cookie `Secure`;
- `HttpOnly`;
- `SameSite=Lax`;
- `session.use_strict_mode`;
- cookies como único transporte de sessão;
- regeneração do session id no login;
- vínculo leve com hash do User-Agent;
- CSRF token por sessão;
- checagem de capability por endpoint;
- resposta genérica para credencial inválida;
- atraso curto em login inválido;
- `Cache-Control: no-store`;
- `Pragma: no-cache`;
- `X-Content-Type-Options: nosniff`;
- `X-Frame-Options: DENY`;
- `Referrer-Policy: same-origin`;
- CSP restritiva nos endpoints;
- `X-Robots-Tag: noindex,nofollow`.

O shell server-side também trata:

```text
/sistema
/sistema/*
```

como `noindex,nofollow`.

---

## 6. Estrutura visual

A interface foi inspirada na ergonomia do `wp-admin`, sem reutilizar seu frontend.

A linguagem visual utiliza:

- sidebar escura;
- topbar branca;
- workspace cinza claro;
- cards e tabelas densos;
- estados editoriais compactos;
- responsive mobile;
- identidade visual do Nosso Jornal.

### 6.1 Sidebar

A primeira versão usava letras dentro de caixas:

```text
P / N / C / M / U / P+ / G
```

Isso foi substituído por ícones SVG vetoriais próprios para:

- Painel;
- Notícias;
- Categorias;
- Mídia;
- Usuários;
- Mesa de Pautas;
- Configurações.

Não foi adicionada uma biblioteca de ícones externa apenas para essa necessidade.

A sidebar passou a ter agrupamentos:

```text
Conteúdo
  Painel
  Notícias
  Categorias
  Mídia

Gestão
  Usuários
  Mesa de Pautas

Sistema
  Configurações
```

Em rotas filhas, o item-pai continua ativo. Exemplos:

```text
/sistema/noticias/123      -> Notícias ativo
/sistema/categorias/58     -> Categorias ativo
```

No mobile, a sidebar vira navegação horizontal compacta.

---

## 7. Rotas do painel

### Dashboard

```text
/sistema
```

### Notícias

```text
/sistema/noticias
/sistema/noticias/:id
```

### Categorias

```text
/sistema/categorias
/sistema/categorias/:id
```

### Mídia

```text
/sistema/midia
```

### Usuários

```text
/sistema/usuarios
```

### Mesa de Pautas

```text
/sistema/pautas
```

### Configurações

```text
/sistema/configuracoes
```

---

## 8. API administrativa

### Sessão

```text
POST /api/admin/login.php
GET  /api/admin/session.php
POST /api/admin/logout.php
```

### Dashboard e inventários

```text
GET /api/admin/dashboard.php
GET /api/admin/posts.php
GET /api/admin/post.php?id=:id
GET /api/admin/categories.php
GET /api/admin/category.php?id=:id
GET /api/admin/media.php
GET /api/admin/users.php
GET /api/admin/settings.php
GET /api/admin/pautas.php
```

### Capabilities de escrita

```text
GET  /api/admin/write-readiness.php
POST /api/admin/category-color.php
POST /api/admin/post-draft.php
POST /api/admin/post-create-draft.php
```

Todas as mutations exigem sessão válida e CSRF.

---

## 9. Dashboard

O Dashboard lê dados reais do WordPress legado.

Resumo:

- publicadas;
- rascunhos;
- categorias;
- mídia;
- usuários.

Atividade:

- posts recentes;
- status;
- autor;
- data;
- link para conteúdo público.

Estado do sistema:

- total de posts;
- pendentes;
- agendados;
- comentários pendentes.

### 9.1 Runtime MySQL

Foi criada uma leitura específica de readiness para o próprio painel.

O Dashboard pode exibir:

```text
SELECT
INSERT
UPDATE
DELETE
```

como:

```text
Disponível
Bloqueado
```

A intenção é que o administrador veja imediatamente quando o runtime deixou de ser somente leitura.

---

## 10. Write readiness

Endpoint:

```text
GET /api/admin/write-readiness.php
```

O probe não deve modificar conteúdo.

Estratégia:

- `SELECT 1`;
- probe de `INSERT` com `SELECT ... WHERE 1 = 0`;
- `UPDATE ... WHERE 1 = 0`;
- `DELETE ... WHERE 1 = 0`;
- transação;
- rollback ao final.

Resultado esperado:

```json
{
  "database": {
    "select":  { "available": true },
    "insert":  { "available": false },
    "update":  { "available": false },
    "delete":  { "available": false },
    "runtimeWriteReady": false
  },
  "probe": {
    "mutatedRows": 0,
    "transactionRolledBack": true
  }
}
```

A UI usa esse retorno como feature gate.

Ela não apresenta um botão gravável simplesmente porque o frontend foi desenvolvido.

---

## 11. Notícias

### 11.1 Listagem

`/sistema/noticias` possui:

- busca;
- paginação;
- filtros por status;
- título;
- autor;
- categorias;
- status;
- última atualização;
- link público;
- link para editor individual.

Filtros previstos/implementados:

```text
Todas
Publicadas
Rascunhos
Pendentes
Agendadas
```

### 11.2 Editor individual

Rota:

```text
/sistema/noticias/:id
```

Endpoint:

```text
GET /api/admin/post.php?id=:id
```

Carrega:

- ID;
- título;
- slug;
- resumo;
- conteúdo bruto;
- status;
- autor;
- publicação;
- modificação;
- categorias;
- imagem destacada;
- Yoast title;
- Yoast meta description;
- primary category quando disponível;
- URL pública.

Layout:

```text
coluna principal
  título
  slug
  resumo
  conteúdo
  SEO

sidebar
  estado da publicação
  categorias
  imagem destacada
  próximos gates
```

---

## 12. Primeira escrita de notícia: salvar draft existente

Endpoint:

```text
POST /api/admin/post-draft.php
```

Esta mutation foi deliberadamente limitada.

Somente aceita:

```text
post_status = draft
```

Um post publicado não pode ser alterado por esse endpoint.

Campos permitidos nesta etapa:

- título;
- resumo;
- conteúdo.

Proteções:

- `edit_posts`;
- CSRF;
- validação de ID;
- limites de tamanho;
- transação;
- `UPDATE` condicionado a `post_status='draft'`;
- atualização de `post_modified`;
- read-back obrigatório;
- rollback em erro.

O editor só libera os campos quando:

```text
post.status === 'draft'
AND runtime UPDATE disponível
```

---

## 13. Criar nova notícia

Endpoint:

```text
POST /api/admin/post-create-draft.php
```

O botão:

```text
+ Nova notícia
```

só é habilitado quando o runtime possui:

```text
INSERT + UPDATE
```

A criação é conservadora:

- usa o usuário autenticado como autor;
- cria somente `post_type=post`;
- cria exclusivamente `post_status=draft`;
- comentários fechados;
- ping fechado;
- sem slug público nesta etapa;
- sem publicação;
- sem categorias automáticas;
- read-back do ID e status;
- redirecionamento para `/sistema/noticias/:id`.

O schema usado continua sendo `njsite_posts`, cujo `ID` é auto incrementável.

---

## 14. Publicação

O botão `Publicar` já aparece no editor como parte da experiência planejada, mas permanece bloqueado nesta etapa.

Isso é intencional.

Antes de liberar publicação real, ainda queremos homologar:

1. salvar draft;
2. criação de draft;
3. categorias;
4. SEO;
5. imagem destacada;
6. geração de slug;
7. capability `publish_posts`;
8. datas local/GMT;
9. read-back;
10. efeito no frontend público.

---

## 15. Categorias

### 15.1 Listagem

`/sistema/categorias` mostra:

- ID;
- nome;
- slug;
- categoria superior;
- cor editorial;
- origem da cor;
- quantidade de posts;
- link público;
- link para editor.

### 15.2 Sistema cromático

A cor editorial utiliza:

```text
njsite_termmeta
meta_key = nj_editorial_color
```

A resolução é:

```text
termmeta persistido
        ↓
se ausente
        ↓
paleta fallback do código
```

A API retorna:

```text
colorSource = termmeta | palette
```

### 15.3 Editor individual

Rota:

```text
/sistema/categorias/:id
```

Endpoint:

```text
GET /api/admin/category.php?id=:id
```

Carrega:

- nome;
- slug;
- descrição;
- parent;
- contagem;
- cor;
- origem da cor;
- possíveis parents;
- tabelas previstas para mutation.

---

## 16. Primeira mutation canária do sistema: cor editorial

Endpoint:

```text
POST /api/admin/category-color.php
```

Foi escolhida como primeira escrita por ter baixo raio de impacto e ser facilmente reversível.

Fluxo:

```text
validar usuário
→ validar CSRF
→ validar categoryId
→ validar #RRGGBB
→ verificar categoria
→ begin transaction
→ localizar nj_editorial_color
→ UPDATE ou INSERT
→ SELECT read-back
→ comparar valor
→ COMMIT
```

Em falha:

```text
ROLLBACK
```

O color picker só é habilitado quando o runtime declara:

```text
INSERT disponível
UPDATE disponível
```

---

## 17. Mídia

Rota:

```text
/sistema/midia
```

A biblioteca atual lê attachments existentes do WordPress.

Mostra:

- preview;
- título;
- mime type;
- alt;
- data;
- parent;
- paginação.

O upload ainda é um gate separado porque gravar a linha em `njsite_posts` não basta.

Precisamos homologar também:

- escrita física em `wp-content/uploads`;
- naming;
- MIME allowlist;
- tamanho;
- thumbnail/metadata;
- vínculo attachment/post;
- URL pública;
- segurança do upload.

---

## 18. Usuários

Rota:

```text
/sistema/usuarios
```

Fonte:

```text
njsite_users
njsite_usermeta
```

A lista mostra:

- display name;
- login;
- e-mail;
- role;
- cadastro.

Nunca mostra:

- `user_pass`;
- hashes;
- credenciais;
- dados de sessão.

Edição/criação de usuário permanece para uma fase posterior e deverá exigir capabilities específicas, não apenas `administrator`.

---

## 19. Configurações

Rota:

```text
/sistema/configuracoes
```

Somente opções allowlisted são expostas:

```text
blogname
blogdescription
home
siteurl
admin_email
posts_per_page
date_format
time_format
timezone_string
permalink_structure
```

O painel não oferece um browser genérico de `wp_options`.

Isso evita transformar a API administrativa em acesso arbitrário ao banco.

---

## 20. Mesa de Pautas

Rota:

```text
/sistema/pautas
```

Essa superfície é exclusiva de:

```text
user_login === 'agenciamobi'
```

A restrição é dupla.

Frontend:

```text
user.login === 'agenciamobi'
&& user.permissions.managePautas
```

Backend:

```text
nj_admin_require_pautas_owner($user)
```

Outro administrador que digitar a URL diretamente recebe:

```text
403 pautas_access_denied
```

### 20.1 Fluxo editorial planejado

```text
RSS
→ Captura
→ Mesa de Pautas
→ Seleção
→ Pesquisa
→ Redação
→ Draft
→ Revisão
→ Agendamento
```

### 20.2 Fontes

A fundação atual possui catálogo de fontes para:

- Pelotas;
- tecnologia;
- inteligência artificial;
- universo;
- ciência;
- curiosidades;
- pesquisa em IA.

### 20.3 Persistência planejada

```text
nj_feed_sources
nj_news_queue
```

As ações futuras serão:

```text
Ignorar
Salvar
Produzir matéria
```

A Mesa deverá alimentar diretamente o fluxo de criação de draft do próprio `/sistema`.

---

## 21. Backend compartilhado

Arquivo principal:

```text
public/api/admin/_admin.php
```

Responsabilidades:

- resposta JSON administrativa;
- headers de segurança;
- wrapper de execução;
- sessão;
- parsing JSON;
- leitura de roles/capabilities;
- serialização segura de usuário;
- busca de usuário;
- verificação de senha WordPress;
- login;
- usuário atual;
- CSRF;
- capability checks;
- regra exclusiva da Mesa de Pautas.

O backend reutiliza:

```text
public/api/v1/_bootstrap.php
public/api/v1/_content.php
```

para conexão PDO, prefixo e normalizações já usadas pelo portal público.

---

## 22. Banco legado utilizado

O sistema administrativo trabalha sobre o mesmo banco usado pelo portal.

Principais estruturas já consumidas:

```text
njsite_users
njsite_usermeta
njsite_options
njsite_posts
njsite_postmeta
njsite_terms
njsite_term_taxonomy
njsite_term_relationships
njsite_termmeta
njsite_comments
```

Não existe um banco administrativo paralelo.

---

## 23. Relação com o MOBI Core

O portal possui seu runtime PHP/PDO próprio para executar a API administrativa.

Paralelamente, o MOBI Core vem sendo evoluído para administrar o recurso MySQL lógico:

```text
logical_key = nossojornal_legacy
```

As capabilities MCP já apareceram como:

```text
infra.mysql.query
infra.mysql.write
```

O Core ainda precisa fechar completamente a resolução de sessão/materialização/runtime binding para permitir operação SQL direta pelo MCP de forma confiável.

Isso não deve ser confundido com o runtime PHP do site.

São duas superfícies diferentes:

```text
/sistema
  PHP do próprio site → banco

MOBI Core MCP
  Core → Runner → Vault → banco
```

O objetivo final é que ambas respeitem o mesmo recurso físico existente sem criar outro banco.

---

## 24. Estratégia de homologação de write

Writes devem ser liberados nesta ordem:

### Gate A — readiness

```text
SELECT
INSERT
UPDATE
DELETE
```

sem mutar dados.

### Gate B — cor editorial

Alteração em:

```text
njsite_termmeta
nj_editorial_color
```

com read-back.

### Gate C — draft existente

Editar somente o rascunho existente.

Nenhum publicado.

### Gate D — criar novo draft

```text
INSERT njsite_posts
post_status=draft
```

### Gate E — taxonomia/SEO/mídia

Adicionar metadados e relações.

### Gate F — publicação

Somente depois dos gates anteriores.

---

## 25. O que ainda não está liberado

Até a homologação completa, não tratar como concluídos:

- editar matéria publicada;
- publicar;
- despublicar;
- agendar;
- alterar slug público;
- editar categorias completas;
- editar SEO;
- selecionar/trocar imagem destacada;
- upload de mídia;
- criar usuário;
- editar usuário;
- alterar configurações;
- persistir fila RSS da Mesa de Pautas;
- autosave;
- revisions;
- histórico/auditoria editorial completa.

---

## 26. Próximas capacidades

Sequência recomendada a partir do MVP atual:

1. validar `write-readiness.php` em produção;
2. persistir uma cor editorial canária;
3. validar read-back no frontend público;
4. editar e salvar o draft existente;
5. criar nova notícia como draft;
6. categorias do draft;
7. título e descrição SEO;
8. geração segura de slug;
9. imagem destacada;
10. upload de mídia;
11. preview de draft autenticado;
12. publicação/despublicação;
13. agendamento;
14. usuários;
15. configurações;
16. Mesa de Pautas persistente;
17. autosave/revisions;
18. audit log editorial.

---

## 27. Arquivos principais

Frontend:

```text
src/AdminApp.tsx
src/admin.css
src/App.tsx
```

Backend administrativo:

```text
public/api/admin/_admin.php
public/api/admin/login.php
public/api/admin/session.php
public/api/admin/logout.php
public/api/admin/dashboard.php
public/api/admin/posts.php
public/api/admin/post.php
public/api/admin/post-draft.php
public/api/admin/post-create-draft.php
public/api/admin/categories.php
public/api/admin/category.php
public/api/admin/category-color.php
public/api/admin/media.php
public/api/admin/users.php
public/api/admin/settings.php
public/api/admin/pautas.php
public/api/admin/write-readiness.php
```

Integrações compartilhadas:

```text
public/api/v1/_bootstrap.php
public/api/v1/_content.php
public/api/v1/_category_theme.php
public/meta.php
```

Documentação relacionada:

```text
EVOLUTION.md
docs/EDITORIAL_COLORS.md
docs/ADMIN_MVP.md
```

---

## 28. Deploy

O fluxo operacional usado no projeto é:

```text
Sincronizar código
→ Inspecionar código
→ Revalidar deploy
→ Publicar
```

Não usar limpeza destrutiva de `public_html`.

Paths persistentes continuam preservados pelo deploy, especialmente:

```text
.well-known/
wp-content/uploads/
```

---

## 29. Critério para considerar o MVP Write homologado

O MVP Write só deve ser considerado validado quando todos estes pontos passarem em produção:

- login permanece funcional;
- páginas read-only continuam funcionando;
- readiness mostra os privilégios esperados;
- mutation de cor persiste e retorna por read-back;
- frontend público consome a cor persistida;
- draft existente salva e mantém `draft`;
- novo draft é criado com o usuário autenticado;
- nenhum post publicado é alterado durante os testes;
- CSRF inválido é rejeitado;
- usuário sem capability recebe 403;
- erros de banco não vazam credenciais;
- logout invalida a sessão;
- deploy não altera `wp-content/uploads`.

A partir daí, publicação real pode ser tratada como próximo estágio e não mais como experimento.
