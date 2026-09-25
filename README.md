# Nosso Jornal

Nova aplicação editorial do **Nosso Jornal**, desenvolvida como portal independente do runtime WordPress legado.

## Stack inicial

- React 18
- TypeScript
- Vite
- CSS editorial próprio
- build estático em `dist/`
- cPanel + Git + MOBI Core
- API server-side e MariaDB nas próximas fases

## Documentos canônicos

- `AGENTS.md`: regras operacionais e limites de desenvolvimento
- `EVOLUTION.md`: único documento de evolução, arquitetura e roadmap
- `main`: fonte canônica do código aceito

## Legado que deve ser preservado

O novo portal não depende do WordPress para servir páginas. Porém:

- `public_html/wp-content/uploads/` é acervo persistente e não pode ser apagado por deploy
- o banco legado `nossojornal_wp262` permanece como fonte histórica durante a transição
- tabelas WordPress usam prefixo `njsite_`
- tabelas novas usarão prefixo `njapp_`

Antes do primeiro release em produção, o Release Runner do MOBI Core deve preservar explicitamente `wp-content/uploads/`.

Consulte `EVOLUTION.md` para o plano completo.
