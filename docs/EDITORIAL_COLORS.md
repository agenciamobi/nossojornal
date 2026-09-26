# Sistema de cores editoriais

A cor é propriedade da categoria, não do componente visual.

## Autoridade

WordPress padrão:

```text
njsite_termmeta
meta_key = nj_editorial_color
```

A API consulta `termmeta` primeiro. Quando não existe valor persistido, usa a paleta canônica do código em `public/api/v1/_category_theme.php`.

Contrato:

```json
{
  "color": "#6D28D9",
  "colorSource": "termmeta"
}
```

Valores possíveis de `colorSource`:

- `termmeta`: autoridade persistida no banco;
- `palette`: fallback canônico do código.

## Paleta

| Categoria | Slug | Cor |
| --- | --- | --- |
| Capa | capa | #123B8C |
| Geral | geral | #475569 |
| Outros | outros | #64748B |
| Eleições 2024 | eleicoes-2024 | #9333EA |
| Cobertura Regional | cobertura-regional | #1D4ED8 |
| Hulha Negra | hulha-negra | #0B57D0 |
| Política | politica | #6D28D9 |
| Segurança | seguranca | #C2410C |
| Economia | economia | #0F766E |
| Esportes | esportes | #15803D |
| Cultura | cultura | #BE185D |
| Educação | educacao | #0369A1 |
| Rural | rural | #4D7C0F |
| Saúde | saude | #0891B2 |
| Rio Grande do Sul | rio-grande-do-sul | #B45309 |
| Brasil | brasil | #166534 |
| Internacional | internacional | #334155 |
| Aceguá | acegua | #4338CA |
| Bagé | bage | #2563EB |
| Candiota | candiota | #0284C7 |
| Dom Pedrito | dom-pedrito | #0E7490 |
| Herval | herval | #0369A1 |
| Pedras Altas | pedras-altas | #4F46E5 |
| Pinheiro Machado | pinheiro-machado | #1E40AF |
| Piratini | piratini | #075985 |

## Persistência

Migration preparada:

```text
database/category-editorial-colors.sql
```

Ela remove apenas valores da chave `nj_editorial_color` para os slugs conhecidos e recria os metadados a partir de `njsite_terms`. Não altera nomes, slugs, taxonomia ou conteúdo.

## Uso visual

A cor editorial aparece como código de navegação, não como preenchimento dominante:

- underline/estado ativo no Header;
- marcador nas cidades da cobertura regional;
- badge de categoria;
- filete superior das seções da homepage;
- hover de headlines;
- filete da página de editoria;
- marcador da matéria;
- drop cap;
- links e blockquotes no corpo;
- sumário e relacionadas.

O objetivo é preservar o branco e o navy do Nosso Jornal como identidade-base enquanto cada editoria ganha reconhecimento visual próprio.
