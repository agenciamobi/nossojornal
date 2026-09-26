# /sistema — Administração do Nosso Jornal

Documento canônico do painel administrativo próprio do portal.

- Rota: `/sistema`
- Frontend: `src/AdminApp.tsx` e `src/admin.css`
- Backend: `public/api/admin/*`
- Banco: MariaDB legado do site
- Autenticação: usuários e permissões já existentes no WordPress

## 1. Papel do /sistema

O `/sistema` é o CMS administrativo do Nosso Jornal.

Ele substitui progressivamente o uso operacional do `wp-admin`, mantendo o mesmo banco e reaproveitando usuários, notícias, categorias, mídia, configurações e metadados existentes.

Arquitetura:

```text
/sistema
React/Vite
   ↓
/api/admin/*
PHP próprio
   ↓
MariaDB existente
   ↓
users / posts / terms / media / options / metadata
```

Não existe uma base paralela para o painel.

## 2. Princípios

### Uma única fonte de verdade

O conteúdo continua no mesmo banco utilizado pelo portal público.

### Permissões reais

A interface e os endpoints respeitam capabilities herdadas do WordPress.

| Recurso | Capability |
| --- | --- |
| Notícias | `edit_posts` |
| Publicar/agendar | `publish_posts` |
| Editar conteúdo de outros autores | `edit_others_posts` |
| Editar conteúdo publicado | `edit_published_posts` |
| Categorias | `manage_categories` |
| Mídia | `upload_files` |
| Usuários | `list_users`, `edit_users`, `promote_users` |
| Configurações | `manage_options` |

A Mesa de Pautas possui ainda uma regra própria:

```text
user_login === 'agenciamobi'
```

### Ação e status são separados

Editar uma notícia não publica automaticamente.

```text
Salvar conteúdo
≠
Publicar
≠
Agendar
≠
Mover para rascunho
```

## 3. Autenticação

O painel reutiliza:

```text
njsite_users
njsite_usermeta
njsite_options -> njsite_user_roles
```

O login aceita usuário ou e-mail e a senha já existente.

Hashes compatíveis:

- WordPress moderno `$wp$2...`;
- bcrypt;
- phpass legado.

A API nunca devolve `user_pass` ou hashes.

## 4. Sessão e segurança

Implementado:

- sessão PHP própria;
- cookie Secure;
- HttpOnly;
- SameSite=Lax;
- strict mode;
- regeneração de session id após login;
- vínculo leve com User-Agent;
- CSRF em mutations;
- capability checks por endpoint;
- respostas administrativas sem cache;
- frame denial;
- noindex/nofollow;
- mensagens de erro sem credenciais ou detalhes sensíveis.

## 5. Navegação

### Conteúdo

- Painel
- Notícias
- Categorias
- Mídia

### Gestão

- Usuários
- Mesa de Pautas

### Sistema

- Configurações

A sidebar utiliza ícones SVG próprios e mantém o item-pai ativo nas rotas internas.

## 6. Rotas

```text
/sistema
/sistema/noticias
/sistema/noticias/:id
/sistema/categorias
/sistema/categorias/nova
/sistema/categorias/:id
/sistema/midia
/sistema/usuarios
/sistema/usuarios/:id
/sistema/pautas
/sistema/configuracoes
```

## 7. Dashboard

O Dashboard apresenta informação operacional:

- notícias publicadas;
- rascunhos;
- categorias;
- mídia;
- usuários;
- atividade recente;
- pendentes;
- agendadas;
- comentários pendentes.

As notícias recentes levam diretamente ao editor administrativo.

Diagnósticos internos de banco não são exibidos ao administrador.

## 8. Notícias

### Listagem

`/sistema/noticias` possui:

- busca;
- paginação;
- filtro por status;
- autor;
- categorias;
- status;
- atualização;
- acesso ao editor;
- link para a publicação pública;
- criação de nova notícia.

### Nova notícia

`+ Nova notícia` cria um post como rascunho e abre o editor.

O draft nasce:

- com o usuário autenticado como autor;
- sem publicação automática;
- sem slug público obrigatório;
- sem categorias forçadas.

### Editor

`/sistema/noticias/:id` gerencia:

- título;
- slug;
- resumo;
- conteúdo;
- categorias;
- categoria principal;
- título SEO;
- descrição SEO;
- imagem destacada;
- status;
- publicação;
- agendamento.

### Salvar

Endpoint:

```text
POST /api/admin/post-save.php
```

Persiste, em transação:

- conteúdo;
- slug;
- categorias;
- relações taxonômicas;
- título SEO;
- descrição SEO;
- categoria principal.

O status atual é preservado durante o save.

### Publicar, despublicar e agendar

Endpoint:

```text
POST /api/admin/post-status.php
```

Ações:

```text
publish
draft
schedule
```

Publicação/agendamento exigem `publish_posts`.

O agendamento grava horário local e GMT.

### Imagem destacada

Endpoint:

```text
POST /api/admin/post-featured-image.php
```

O editor possui seletor visual que lê a biblioteca de mídia.

Permite:

- escolher imagem;
- trocar imagem;
- remover imagem.

O vínculo usa o metadado WordPress `_thumbnail_id`.

## 9. Categorias

### Listagem

`/sistema/categorias` mostra:

- nome;
- slug;
- parent;
- cor;
- quantidade de posts;
- link público;
- acesso ao editor.

### Nova categoria

`/sistema/categorias/nova` cria:

- nome;
- slug;
- descrição;
- categoria superior;
- cor editorial.

Endpoint:

```text
POST /api/admin/category-create.php
```

### Editar categoria

`/sistema/categorias/:id` permite editar os mesmos campos.

Endpoint:

```text
POST /api/admin/category-save.php
```

O backend protege contra:

- slug duplicado;
- categoria como parent dela própria;
- ciclos de parent;
- parent inexistente;
- cor inválida.

### Cores editoriais

Persistência:

```text
njsite_termmeta
meta_key = nj_editorial_color
```

A UI mostra apenas:

- Cor personalizada;
- Cor padrão da editoria.

Detalhes de armazenamento não aparecem no painel.

## 10. Mídia

`/sistema/midia` mostra a biblioteca existente e suporta upload de imagens.

Endpoint:

```text
POST /api/admin/media-upload.php
```

Formatos aceitos:

- JPEG;
- PNG;
- WebP;
- GIF.

Limite atual:

```text
12 MB
```

Validações:

- upload PHP válido;
- MIME real via finfo;
- imagem válida via getimagesize;
- nome de arquivo seguro;
- colisão de nome;
- diretório gravável.

Destino:

```text
/wp-content/uploads/YYYY/MM/
```

Após gravar o arquivo, o sistema cria o attachment no banco e registra os metadados básicos.

Se a gravação no banco falhar, o arquivo enviado é removido.

## 11. Usuários

### Listagem

`/sistema/usuarios` mostra:

- nome;
- login;
- e-mail;
- função;
- cadastro.

### Editor

`/sistema/usuarios/:id` permite:

- editar nome de exibição;
- editar e-mail;
- alterar função quando permitido.

Login não é alterado nesta etapa.

A senha também não é gerenciada por essa tela.

### Proteção da conta administrativa

A conta:

```text
agenciamobi
```

não pode ter sua função alterada pelo editor comum.

Também não é permitido auto-rebaixamento pela própria conta.

## 12. Configurações

`/sistema/configuracoes` possui formulário próprio.

Campos editáveis:

- nome do site;
- descrição;
- e-mail administrativo;
- notícias por página;
- fuso horário;
- formato de data;
- formato de hora.

Endpoint:

```text
POST /api/admin/settings-save.php
```

Campos estruturais permanecem protegidos:

- URL pública;
- URL técnica;
- estrutura de permalink.

Isso evita quebrar roteamento ou domínio pelo painel.

## 13. Mesa de Pautas

Rota:

```text
/sistema/pautas
```

Acesso exclusivo:

```text
user_login === 'agenciamobi'
```

A regra existe no frontend e no backend.

A tela apresenta:

- fontes;
- temas;
- fluxo editorial;
- catálogo de RSS.

Fluxo:

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

A integração futura deve criar drafts diretamente no mesmo editor de Notícias.

## 14. Endpoints administrativos

### Sessão

```text
POST /api/admin/login.php
GET  /api/admin/session.php
POST /api/admin/logout.php
```

### Dashboard e leitura

```text
GET /api/admin/dashboard.php
GET /api/admin/posts.php
GET /api/admin/post.php
GET /api/admin/categories.php
GET /api/admin/category.php
GET /api/admin/media.php
GET /api/admin/users.php
GET /api/admin/user.php
GET /api/admin/settings.php
GET /api/admin/pautas.php
```

### Gestão

```text
POST /api/admin/post-create-draft.php
POST /api/admin/post-save.php
POST /api/admin/post-status.php
POST /api/admin/post-featured-image.php

POST /api/admin/category-create.php
POST /api/admin/category-save.php

POST /api/admin/media-upload.php

POST /api/admin/user-save.php

POST /api/admin/settings-save.php
```

### Verificação interna de disponibilidade

```text
GET /api/admin/write-readiness.php
```

Esse endpoint é usado silenciosamente como feature gate e não aparece como diagnóstico na interface.

## 15. Experiência da interface

O painel não deve exibir linguagem de desenvolvimento.

Não usar na UI:

- MVP;
- runtime;
- canário;
- read-back;
- fallback;
- nomes de tabela;
- meta keys;
- INSERT/UPDATE/DELETE;
- mensagens de homologação;
- nomes internos de capabilities.

Esses termos podem existir na documentação técnica e no código, nunca na experiência administrativa comum.

A linguagem do painel deve ser operacional:

```text
Salvar
Publicar
Agendar
Mover para rascunho
Nova notícia
Nova categoria
Enviar imagem
Editar usuário
Salvar alterações
```

## 16. Compatibilidade com o legado

O painel continua operando sobre as estruturas existentes:

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

## 17. Relação com o MOBI Core

O `/sistema` usa a conexão PHP privada do próprio site para a operação administrativa.

O MOBI Core possui uma camada separada de infraestrutura para operar o mesmo recurso lógico:

```text
logical_key = nossojornal_legacy
```

As duas superfícies não devem criar bancos diferentes.

Objetivo:

```text
mesmo banco físico
mesmos usuários
mesmo conteúdo
interfaces diferentes
```

## 18. Estado de homologação

### Já validado em produção

- login;
- sessão;
- logout;
- Dashboard;
- Notícias em leitura;
- Categorias em leitura;
- Mídia em leitura;
- Usuários em leitura;
- Configurações em leitura;
- Mesa de Pautas restrita ao usuário `agenciamobi`.

### Implementado na main e aguardando validação em produção

- criação de draft;
- edição completa de notícia;
- categorias da notícia;
- SEO;
- publicar;
- mover para rascunho;
- agendamento;
- imagem destacada;
- criação de categoria;
- edição completa de categoria;
- upload de mídia;
- edição de usuário;
- função de usuário;
- edição de configurações.

As operações só ficam habilitadas se o runtime PHP conseguir executá-las.

## 19. Sequência recomendada de validação

Depois do deploy:

1. confirmar login e navegação;
2. editar uma cor de categoria e salvar;
3. criar uma categoria descartável;
4. editar o rascunho existente;
5. criar uma nova notícia;
6. definir categorias e SEO;
7. escolher imagem destacada;
8. enviar uma imagem de teste;
9. editar uma configuração inofensiva;
10. editar nome/e-mail de um usuário de teste;
11. publicar um draft descartável;
12. mover esse conteúdo de volta para rascunho;
13. testar agendamento.

Não usar uma matéria editorial importante como primeiro teste de publicação.

## 20. Critério de painel gerenciável

O `/sistema` será considerado plenamente homologado quando:

- writes passarem em produção;
- criação e edição de notícia funcionarem;
- categorias persistirem;
- mídia puder ser enviada;
- imagem destacada persistir;
- SEO persistir;
- publicação/despublicação funcionarem;
- agendamento funcionar;
- usuários puderem ser editados conforme permissões;
- configurações seguras puderem ser salvas;
- nenhum erro expuser detalhes sensíveis;
- o site público refletir as alterações sem inconsistência.

## 21. Arquivos principais

Frontend:

```text
src/AdminApp.tsx
src/admin.css
src/App.tsx
```

Backend:

```text
public/api/admin/_admin.php
public/api/admin/login.php
public/api/admin/session.php
public/api/admin/logout.php
public/api/admin/dashboard.php
public/api/admin/posts.php
public/api/admin/post.php
public/api/admin/post-create-draft.php
public/api/admin/post-save.php
public/api/admin/post-status.php
public/api/admin/post-featured-image.php
public/api/admin/categories.php
public/api/admin/category.php
public/api/admin/category-create.php
public/api/admin/category-save.php
public/api/admin/media.php
public/api/admin/media-upload.php
public/api/admin/users.php
public/api/admin/user.php
public/api/admin/user-save.php
public/api/admin/settings.php
public/api/admin/settings-save.php
public/api/admin/pautas.php
public/api/admin/write-readiness.php
```

## 22. Deploy

Fluxo oficial:

```text
Sincronizar código
→ Inspecionar código
→ Revalidar deploy
→ Publicar
```

Preservar:

```text
.well-known/
wp-content/uploads/
```

Nunca limpar `public_html` de forma destrutiva para publicar o painel.
