# AGENTS.md - Nosso Jornal

Este arquivo é a autoridade operacional para agentes e automações que trabalham em `agenciamobi/nossojornal`.

## 1. Missão

Reconstruir o Nosso Jornal como um portal editorial moderno, rápido, acessível e independente do runtime WordPress antigo, preservando o acervo histórico, mídia, URLs relevantes e valor de SEO.

## 2. Autoridades

- GitHub `main` é a fonte canônica do código aceito.
- `EVOLUTION.md` é o único documento de evolução e roadmap.
- Não criar `ROADMAP.md`, `PLAN.md`, `TODO_ARCHITECTURE.md` ou evolução concorrente.
- Mudanças de direção, arquitetura, fases e status devem ser incorporadas em `EVOLUTION.md`.
- Mudança direta em `main` só quando o operador humano pedir explicitamente. Fora disso, usar branch e PR.

## 3. Ambiente canônico

- Repositório: `agenciamobi/nossojornal`
- Branch de produção: `main`
- Domínio: `https://nossojornal.com.br/`
- Target MOBI Core: `755d8037-c644-464f-99a0-99a8778aebd5`
- Estratégia: cPanel + Git
- Repositório no servidor: `repositories/nossojornal`
- Produção: `public_html`
- Artefato: `dist/`
- Runtime público pretendido: Vite SPA estática, sem Node server obrigatório

## 4. Regra crítica de mídia persistente

`public_html/wp-content/uploads/` contém o acervo histórico.

É proibido:

- apagar essa pasta;
- mover ou renomear em massa sem plano aprovado;
- versionar o acervo no Git;
- copiar o acervo inteiro para `dist/`;
- publicar com `rsync --delete` sem preservação explícita;
- assumir que backup elimina a obrigação de preservar o caminho.

O primeiro cutover só pode ocorrer quando o release comprovar a preservação de:

- `.well-known/`
- `wp-content/uploads/`

## 5. WordPress legado

O WordPress antigo é legado, não a nova arquitetura.

- Não criar novas features dependentes de `wp-admin`, plugins, Elementor, Porto ou themes.
- Não assumir disponibilidade de `wp-load.php` no novo runtime.
- O acervo de mídia continua válido por URL.
- As tabelas `njsite_*` são fonte histórica durante a transição.
- O browser nunca acessa MariaDB diretamente.
- Leitura do legado passa por API server-side e normalização editorial.

## 6. Banco de dados

Banco histórico: `nossojornal_wp262`.

Convenções:

- `njsite_*`: legado WordPress, inicialmente read-only pela aplicação nova.
- `njapp_*`: namespace exclusivo das tabelas novas.
- Nunca criar tabela nova com prefixo `wp_` ou `njsite_`.
- Nunca gravar diretamente em estruturas WordPress sem migration e decisão documentada.
- Credenciais nunca entram no Git, frontend, bundle Vite ou variáveis `VITE_*`.
- A aplicação pública consome apenas API HTTP.
- A API usa usuário de banco com privilégio mínimo.

## 7. Arquitetura pública

```text
browser
  -> nossojornal.com.br
  -> React/Vite
  -> API server-side
  -> normalização editorial
       -> njapp_* (novo)
       -> njsite_* (legado durante migração)
  -> MariaDB
```

A API deve esconder a origem física do conteúdo. O frontend não deve saber se uma matéria veio de `njapp_articles` ou `njsite_posts`.

## 8. Identidade visual

Tokens derivados do logotipo institucional fornecido em 25/09/2026:

- carvão azulado: `#30323B`
- marinho editorial: `#1D3261`
- azul institucional profundo: `#0A3284`
- azul de destaque: `#6597F8`
- branco: `#FDFDFD`

Princípios:

- aparência editorial, não dashboard SaaS;
- masthead forte;
- grande presença de superfícies claras para leitura;
- azul concentrado em marca, navegação, links e estados;
- contraste AA ou superior;
- mobile-first;
- cards devem parecer blocos editoriais, não widgets genéricos;
- fotografia terá protagonismo quando o acervo real estiver conectado.

## 9. Conteúdo editorial

- Não inventar fatos, datas, números, fontes ou notícias para preencher layout.
- Conteúdo de demonstração deve estar explicitamente marcado como demonstração.
- Não publicar conteúdo gerado por IA como reportagem sem revisão humana.
- Autoria, publicação e atualização devem ser rastreáveis.
- Correções futuras devem possuir trilha de auditoria.

## 10. SEO e News SEO

- Preservar slugs históricos quando possível.
- Mudança de URL exige mapa de redirect 301 antes do cutover.
- Toda matéria pública precisa de canonical.
- Implementar `NewsArticle`, breadcrumbs, Open Graph e metadados sociais.
- Sitemap geral e sitemap de notícias são responsabilidades separadas.
- RSS/Atom deve existir.
- Não indexar admin, preview privado, endpoint interno ou busca vazia.
- Conteúdo essencial não deve depender exclusivamente de JavaScript na fase final de SEO.

## 11. Performance

- HTML inicial pequeno e estável.
- JavaScript sob orçamento explícito.
- Imagens responsivas, lazy loading fora do LCP e dimensões conhecidas.
- Evitar bibliotecas grandes sem justificativa.
- Não adicionar dependência para algo simples que CSS/DOM resolva.
- Priorizar Core Web Vitals em dispositivos móveis.

## 12. Acessibilidade

- HTML semântico primeiro.
- Navegação completa por teclado.
- Foco visível.
- Labels e nomes acessíveis.
- Respeitar `prefers-reduced-motion`.
- Não comunicar estado apenas por cor.
- Respeitar hierarquia de headings.

## 13. Segurança

- Nenhum segredo em `src/`, `public/` ou `VITE_*`.
- Sanitizar HTML vindo do legado antes de renderizar.
- API valida entrada, aplica rate limit e CORS explícito.
- Uploads novos validam MIME, extensão, tamanho e nome.
- Painel editorial exige autenticação e autorização server-side.
- Logs não podem conter senha, token, cookie, DSN ou segredo.

## 14. Qualidade mínima

Executar, quando aplicável:

```bash
npm ci
npm run typecheck
npm run build
```

Além disso:

- sem erros TypeScript;
- build produz `dist/index.html`;
- mobile e desktop revisados;
- nenhuma regressão que remova caminhos persistentes;
- URLs e metadados afetados revisados;
- `EVOLUTION.md` atualizado quando fase ou arquitetura mudar.

## 15. Release

Não considerar release seguro apenas porque o build passou.

Antes de produção, comprovar:

1. SHA aceito da `main`;
2. workspace limpo;
3. build determinístico;
4. backup do `public_html`;
5. preservação dos caminhos persistentes;
6. publicação do artefato;
7. fallback SPA quando necessário;
8. smoke HTTP;
9. readback do SHA publicado;
10. rollback disponível.

Enquanto o Release Runner do MOBI Core não preservar `wp-content/uploads/`, publicação destrutiva permanece bloqueada por decisão arquitetural.
