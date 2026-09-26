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
- Páginas
- Categorias
- Mídia
- Comentários

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
/sistema/paginas
/sistema/paginas/:id
/sistema/categorias
/sistema/categorias/nova
/sistema/categorias/:id
/sistema/midia
/sistema/midia/:id
/sistema/comentarios
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

### Diagnóstico de banco

```text
GET /api/admin/write-readiness.php
```

Esse endpoint permanece disponível apenas para diagnóstico técnico.

Ele **não controla mais a habilitação da interface administrativa**.

A regra do painel é:

```text
capability do usuário
→ interface habilitada
→ endpoint executa a mutation
→ sucesso ou erro da operação
```

Uma falha de diagnóstico nunca deve transformar o CMS inteiro em somente leitura.

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


## 23. Páginas, comentários, lixeira e mídia detalhada

### Páginas

Nova área:

```text
/sistema/paginas
/sistema/paginas/:id
```

A listagem usa os registros `post_type=page` existentes no banco e permite:

- busca;
- filtros por status;
- acesso ao editor;
- link público quando a página possui rota no portal.

O editor permite alterar:

- título;
- resumo;
- conteúdo;
- título SEO;
- descrição SEO;
- slug quando a rota não é estrutural.

As páginas institucionais que alimentam:

```text
/sobre
/contato
```

mantêm o slug protegido no painel, evitando quebrar a navegação pública.

Endpoints:

```text
GET  /api/admin/pages.php
GET  /api/admin/page-item.php?id=:id
POST /api/admin/page-save.php
```

Permissões usadas:

```text
edit_pages
edit_others_pages
edit_published_pages
publish_pages
```

### Comentários

Nova área:

```text
/sistema/comentarios
```

Visível somente para usuários com:

```text
moderate_comments
```

A tela oferece:

- busca por autor, e-mail, conteúdo ou notícia;
- Todos;
- Pendentes;
- Aprovados;
- Spam;
- Lixeira;
- Aprovar;
- Marcar como pendente;
- Spam;
- Não é spam;
- Lixeira;
- Restaurar.

Endpoints:

```text
GET  /api/admin/comments.php
POST /api/admin/comment-status.php
```

### Lixeira de notícias

A listagem de Notícias passa a separar a Lixeira da aba `Todas`.

Ações disponíveis conforme as capabilities do usuário:

```text
Mover para lixeira
Restaurar
```

Endpoint:

```text
POST /api/admin/post-trash.php
```

A operação utiliza os metadados compatíveis com a lixeira do WordPress:

```text
_wp_trash_meta_status
_wp_trash_meta_time
```

Ao restaurar, o sistema tenta recuperar o status anterior. Quando a conta não possui permissão para restaurar diretamente um conteúdo publicado, o item volta como rascunho.

### Mídia individual

A Biblioteca de Mídia passa a ter:

```text
/sistema/midia/:id
```

O editor individual permite alterar:

- título;
- texto alternativo;
- legenda;
- descrição.

Também mostra:

- tipo MIME;
- arquivo físico relativo;
- datas;
- ID;
- notícias que utilizam a imagem como destaque.

Endpoints:

```text
GET  /api/admin/media-item.php?id=:id
POST /api/admin/media-save.php
```

A Biblioteca também passa a aceitar busca por:

- título;
- nome/URL do arquivo;
- texto alternativo.

O objetivo é tornar um acervo grande utilizável sem depender do Media Library do WordPress.

## 24. Estado desta camada

Implementado na `main`, ainda dependente de deploy e homologação controlada:

- moderação de comentários;
- lixeira/restauração de notícias;
- busca de mídia;
- editor individual de mídia;
- edição de metadados de imagem;
- listagem de páginas;
- edição de páginas institucionais.

A primeira validação deve continuar usando conteúdo descartável ou de teste para qualquer mutation.


## 25. Correção do bloqueio global de edição

Foi removida a dependência do frontend em `write-readiness.php` para habilitar campos e botões.

O comportamento anterior tinha um efeito indesejado: uma falha no probe de banco deixava o painel inteiro sem edição, incluindo Notícias, Páginas, Categorias, Mídia, Usuários e Configurações.

A partir desta revisão:

- Notícias usam as capabilities editoriais do usuário;
- Publicar e Agendar usam `publish_posts`;
- Páginas usam `edit_pages` e capabilities relacionadas;
- Categorias são gerenciadas por `manage_categories`;
- Mídia usa `upload_files`;
- Usuários usam `edit_users` e `promote_users`;
- Configurações usam `manage_options`;
- Lixeira usa capabilities de delete;
- Comentários usam `moderate_comments`.

Os endpoints continuam validando sessão, capability, CSRF, entrada e persistência.

Se uma mutation não puder ser executada no banco, somente aquela ação falha e a interface informa o problema. O restante do painel continua gerenciável.


## 26. Editor rico de conteúdo

O palco de redação deixa de usar um `textarea` bruto e passa a utilizar:

```text
src/AdminRichEditor.tsx
```

O componente é utilizado em:

```text
/sistema/noticias/:id
/sistema/paginas/:id
```

### Experiência

O editor possui uma faixa de ferramentas inspirada em processadores de texto, organizada por grupos:

```text
Estilo
Texto
Parágrafo
Inserir
Edição
```

Recursos disponíveis:

- texto normal;
- Título 2;
- Título 3;
- Título 4;
- citação;
- negrito;
- itálico;
- sublinhado;
- tachado;
- cor do texto;
- marca-texto;
- alinhamento à esquerda;
- centralização;
- alinhamento à direita;
- justificação;
- listas com marcadores;
- listas numeradas;
- aumentar recuo;
- diminuir recuo;
- inserir link;
- remover link;
- inserir imagem da Biblioteca de Mídia;
- inserir tabela;
- inserir separador horizontal;
- desfazer;
- refazer;
- limpar formatação.

### Modos de edição

O editor possui:

```text
Visual
HTML
Tela cheia
```

O modo HTML permite revisar ou ajustar diretamente o conteúdo persistido no banco.

A tela cheia transforma o editor no foco principal da redação sem perder a sidebar administrativa depois que o usuário sair desse modo.

### Biblioteca de Mídia

O botão `Imagem` abre a Biblioteca de Mídia dentro do próprio editor.

A biblioteca permite:

- carregar as imagens já existentes;
- pesquisar imagens;
- selecionar uma imagem;
- inserir a imagem no ponto atual do cursor.

A imagem é inserida como HTML sem virar automaticamente imagem destacada.

Imagem destacada continua sendo uma operação independente na lateral do editor.

### Seleção e cursor

O editor preserva a seleção atual antes de abrir ferramentas que mudam o foco, como:

- links;
- cores;
- marca-texto;
- Biblioteca de Mídia.

Ao concluir a ação, a formatação ou inserção é aplicada na posição em que o cursor estava.

### Colagem de conteúdo

Ao colar HTML vindo de Word, navegador ou outro editor, o conteúdo passa por limpeza no frontend.

São removidos:

- scripts;
- stylesheets;
- iframes;
- objetos;
- formulários;
- inputs;
- botões;
- embeds;
- atributos de evento;
- classes e atributos estranhos ao conteúdo editorial.

Links perigosos com `javascript:` também são rejeitados.

O objetivo é preservar a estrutura útil e evitar carregar lixo de formatação de outros editores.

### Conteúdo público

O sanitizador público foi ajustado para preservar somente estilos editoriais seguros:

- `text-align`;
- `color`;
- `background-color`;
- `font-weight`;
- `font-style`;
- `text-decoration`.

Outras propriedades CSS continuam sendo removidas.

Isso permite que cor, marca-texto e alinhamento definidos no editor sobrevivam à publicação sem abrir a porta para CSS arbitrário.

### Tabelas e estrutura

O frontend público ganhou suporte visual para:

- tabelas;
- cabeçalhos;
- células;
- separadores horizontais;
- alinhamentos.

A aparência final continua subordinada ao design editorial do Nosso Jornal.

### Status do editor

A barra inferior mostra:

- quantidade de palavras;
- quantidade de caracteres;
- atalho de salvamento.

Atalho disponível:

```text
Ctrl+S
```

ou `Cmd+S` em macOS.

O atalho chama a mesma ação `Salvar` da tela administrativa.

### Princípio de design

O editor é semelhante a um processador de texto na ergonomia, mas não oferece liberdade tipográfica irrestrita.

Famílias de fonte arbitrárias e estilos que poderiam quebrar a identidade visual do portal não fazem parte da primeira versão.

O objetivo é:

```text
facilidade de redação
+
controle editorial
+
HTML limpo
+
consistência visual do jornal
```


## 27. Sistema operacional de redação

O `/sistema` passa a administrar não apenas o conteúdo publicado, mas também o trabalho editorial anterior e posterior à publicação.

A primeira onda introduz:

- workflow editorial por matéria;
- prazo interno;
- responsável;
- prioridade;
- Caderno de Apuração;
- fontes consultadas;
- checklist de publicação;
- Central da Capa;
- Agenda Editorial;
- Mesa de Pautas operacional;
- captura RSS;
- Central de Fontes;
- blocos editoriais estruturados;
- cockpit da redação no Dashboard.

### 27.1 Workflow editorial

Cada notícia pode possuir uma etapa própria da redação:

```text
Ideia
→ Apuração
→ Redação
→ Revisão
→ Pronta
→ Agendada
→ Publicada
```

O workflow é persistido em `postmeta` e não substitui `post_status`.

Isso separa duas coisas distintas:

```text
estado editorial interno
≠
estado técnico de publicação
```

Campos:

```text
_nj_editorial_stage
_nj_editorial_priority
_nj_editorial_deadline
_nj_editorial_assignee
```

O status técnico sincroniza etapas importantes:

```text
future  → scheduled
publish → published
draft   → writing
```

### 27.2 Caderno de Apuração

Cada matéria possui uma área privada da redação.

Persistência:

```text
_nj_reporting_notes
_nj_reporting_sources
```

O caderno permite guardar:

- perguntas em aberto;
- dados para verificar;
- contexto;
- trechos de entrevistas;
- fontes consultadas;
- contatos;
- links e documentos;
- observações privadas.

Nada desse conteúdo é enviado ao frontend público.

### 27.3 Fontes da matéria

Uma matéria pode armazenar snapshots de fontes consultadas com:

- nome;
- organização/função;
- contato;
- URL/documento;
- observação.

A fonte pode ser digitada manualmente ou importada da Central de Fontes.

O snapshot é intencional: uma futura alteração no cadastro central não modifica retroativamente o registro de apuração daquela matéria.

### 27.4 Checklist editorial

Checklist manual:

- título revisado;
- fatos conferidos;
- nomes e cargos conferidos;
- datas e números conferidos;
- fontes identificadas;
- direitos/crédito de imagem;
- texto alternativo;
- links;
- editoria;
- SEO;
- revisão final.

Checks automáticos:

- título;
- resumo;
- imagem destacada;
- categoria;
- metadados de busca.

Publicar ou agendar com pendências gera confirmação explícita.

O checklist orienta a redação, mas não transforma o CMS em um bloqueio rígido.

## 28. Central da Capa

Rota:

```text
/sistema/capa
```

A Central da Capa permite controlar a página inicial independentemente da ordem cronológica.

Posições:

```text
Automático
Manchete principal
Destaque
```

Também permite:

- ordem dos destaques;
- validade da fixação;
- retorno automático ao fluxo normal quando a validade expira.

Metadados:

```text
_nj_home_slot
_nj_home_rank
_nj_home_until
```

A Home pública resolve a manchete nesta ordem:

```text
Hero manual ativo
→ categoria Capa
→ notícia mais recente
```

Destaques manuais ativos entram antes das últimas notícias automáticas.

A administração da Capa exige:

```text
publish_posts
+
edit_others_posts
```

## 29. Agenda Editorial

Rota:

```text
/sistema/agenda
```

A Agenda reúne na mesma linha do tempo:

- prazos internos das matérias;
- publicações agendadas;
- coberturas;
- entrevistas;
- reuniões;
- eventos;
- prazos avulsos.

Eventos próprios usam o post type privado:

```text
nj_agenda_event
```

Metadados:

```text
_nj_event_start
_nj_event_end
_nj_event_location
_nj_event_kind
```

A Agenda não cria conteúdo público.

## 30. Mesa de Pautas operacional

A Mesa de Pautas deixa de ser apenas catálogo de RSS.

Entidade:

```text
post_type = nj_pauta
post_status = private
```

Campos:

```text
etapa
prioridade
tema
origem
URL de referência
prazo
responsável
anotações
draft vinculado
```

Etapas:

```text
Entrada
Selecionada
Apuração
Pronta
Em redação
```

A tela utiliza um quadro editorial em colunas.

### Produzir matéria

A ação:

```text
Produzir matéria
```

cria um draft real em `post_type=post`.

São transportados para o novo draft:

- título;
- prioridade;
- prazo;
- responsável;
- anotações da pauta;
- origem/link como fonte de apuração.

A pauta guarda o ID da matéria gerada, impedindo a criação acidental de vários drafts para a mesma pauta.

## 31. Captura RSS

A Mesa possui:

```text
Capturar agora
Capturar por feed
```

A captura:

- usa somente feeds presentes na allowlist;
- não aceita URL arbitrária;
- limita itens por feed;
- suporta RSS e Atom;
- remove HTML da descrição;
- deduplica pela URL original;
- cria pautas na coluna Entrada;
- preserva fonte, link e tema;
- executa feeds em requisições isoladas;
- usa concorrência controlada no frontend;
- não segue redirects durante o fetch.

Falha em um feed não interrompe os demais.

## 32. Central de Fontes

Rota:

```text
/sistema/fontes
```

Entidade privada:

```text
post_type = nj_source
post_status = private
```

Campos:

- nome;
- organização;
- cargo/função;
- telefone;
- WhatsApp;
- e-mail;
- cidade;
- assuntos;
- site/perfil oficial;
- observações privadas.

A Central possui busca e ações rápidas de contato.

No editor da matéria, o Caderno de Apuração possui:

```text
Adicionar da Central
```

que abre uma busca sem sair da notícia.

## 33. Blocos editoriais estruturados

O editor rico ganhou blocos próprios de jornalismo:

```text
Entenda
Serviço
Em números
Cronologia
```

São armazenados como HTML com:

```html
<aside data-nj-block="...">
```

Não usam shortcode.

O frontend público possui apresentação própria para cada tipo e o editor mostra uma prévia coerente com a matéria final.

## 34. Cockpit da redação

O Dashboard passa a priorizar ação editorial.

Exibe:

- prazos vencidos;
- prazos dos próximos sete dias;
- matérias em revisão;
- matérias prontas;
- publicações agendadas;
- coberturas e compromissos próximos;
- pautas de prioridade alta;
- atividade recente;
- contagem por etapa editorial.

Atalhos rápidos:

```text
Organizar capa
Abrir agenda
Notícias
Mesa de Pautas
```

O painel deixa de ser apenas inventário do banco e passa a funcionar como entrada da operação diária.
