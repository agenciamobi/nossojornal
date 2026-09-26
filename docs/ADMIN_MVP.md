# Painel administrativo MVP

Rota pública do shell:

```text
/sistema
```

O painel é deliberadamente inspirado na ergonomia do `wp-admin`: navegação lateral, barra administrativa, dashboard, tabelas densas, filtros e biblioteca de mídia. A identidade visual continua sendo do Nosso Jornal.

## Identidade e autenticação

O painel não cria uma base paralela de usuários.

Fonte:

```text
njsite_users
njsite_usermeta
njsite_options -> njsite_user_roles
```

O login aceita o mesmo usuário ou e-mail e senha do WordPress legado.

Formatos de senha suportados pelo adapter:

- WordPress moderno com prefixo `$wp$2...`;
- bcrypt WordPress/compatível;
- phpass portátil `$P$` e `$H$`.

A senha e o hash nunca são devolvidos ao frontend.

A sessão administrativa usa:

- cookie Secure;
- HttpOnly;
- SameSite=Lax;
- strict session mode;
- regeneração do session id no login;
- vínculo ao hash do User-Agent;
- token CSRF para mutations administrativas;
- respostas sem cache;
- `X-Frame-Options: DENY`;
- CSP restritiva nos endpoints;
- `X-Robots-Tag: noindex, nofollow`.

O acesso ao painel editorial exige a capability WordPress `edit_posts`.

## Permissões

As permissões não são inferidas apenas pelo nome da role. O adapter lê `njsite_user_roles` e expande as capabilities reais do WordPress.

Superfícies:

| Área | Capability |
| --- | --- |
| Painel | edit_posts |
| Notícias | edit_posts |
| Categorias | manage_categories |
| Mídia | upload_files |
| Usuários | list_users |
| Configurações | manage_options |

## Rotas

```text
/sistema
/sistema/noticias
/sistema/categorias
/sistema/midia
/sistema/usuarios
/sistema/configuracoes
```

Todas permanecem `noindex,nofollow`.

## API administrativa

Autoridade única:

```text
/api/admin/login.php
/api/admin/session.php
/api/admin/logout.php
/api/admin/dashboard.php
/api/admin/posts.php
/api/admin/categories.php
/api/admin/media.php
/api/admin/users.php
/api/admin/settings.php
```

A implementação paralela `/api/v1/system/*` foi removida para evitar duas sessões e dois contratos administrativos.

## Dashboard

Exibe dados reais:

- publicadas;
- rascunhos;
- categorias;
- mídia;
- usuários;
- posts totais;
- pendentes;
- agendados;
- comentários pendentes;
- atividade recente.

## Notícias

A listagem administrativa oferece:

- filtros por status;
- busca;
- paginação;
- título;
- autor;
- categorias;
- status;
- data de atualização;
- link para a publicação pública quando aplicável.

## Categorias

A tabela mostra:

- nome;
- slug;
- categoria superior;
- cor editorial;
- origem da cor (`termmeta` ou fallback);
- quantidade de posts;
- link público.

## Mídia

Biblioteca read-only de attachments WordPress com:

- preview;
- mime type;
- título;
- alt text;
- data;
- paginação.

## Usuários

Lista usuários reais de `njsite_users`, suas roles e dados públicos de administração. Nenhum hash ou credential material é exposto.

## Configurações

Somente opções explicitamente allowlisted são exibidas:

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

## Estado do MVP

O painel é `read_only`.

A interface não finge CRUD: edição/publicação fica para o próximo gate, quando o MySQL write estiver homologado e pudermos aplicar operações por capability, CSRF, validação e auditoria.

## Próximos gates

1. validar login com um usuário WordPress existente;
2. validar todas as áreas read-only;
3. validar logout e acesso sem sessão;
4. homologar MySQL write;
5. criar edição de notícia;
6. criar publicação/rascunho;
7. CRUD de categorias e cor editorial;
8. upload de mídia;
9. gestão de usuários restrita a `edit_users/create_users`;
10. configurações mutáveis restritas a `manage_options`.
