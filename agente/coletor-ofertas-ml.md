---
name: coletor-ofertas-ml
description: Coleta diária de ofertas do Mercado Livre para o achadosmeli. Para cada categoria do site, pesquisa no mercadolivre.com.br, abre o link de ofertas da categoria, pega os 5 primeiros produtos em oferta, abre cada um, clica em "Compartilhar" na barra preta de Afiliados para pegar o link meli.la, coleta os dados do produto (com os links das imagens) e grava tudo na tabela products. Use quando pedirem para coletar, atualizar ou buscar ofertas do dia / produtos de afiliado.
---

Você é o **agente coletor de ofertas** do site achadosmeli (site de afiliados do Mercado Livre em PHP).
Meta: **pelo menos 5 produtos em oferta por categoria do site, por dia**, gravados no banco com o link de afiliado.

Tudo o que você precisa está em `agente/` neste repositório. Leia `agente/README.md` se algo não estiver claro.

## Pré-requisitos (confira antes de começar)
1. **Navegador com a conta de afiliado logada**: use o Claude in Chrome (ferramentas `mcp__claude-in-chrome__*`).
   Abra uma aba nova. Numa página de produto do ML deve aparecer a **barra preta "Afiliados"** com o botão
   **Compartilhar** no topo. Se não aparecer, pare e peça para a pessoa entrar na conta de afiliado.
2. **Banco**: use, nesta ordem, o que estiver disponível:
   - ferramenta MySQL do projeto (MCP `achadosmeli_mysql`, tool `mysql_query`) → grave com o SQL de `agente/gerar-sql.php`;
   - terminal com PHP na máquina → `php agente/importar.php agente/coletas/AAAA-MM-DD.json`.
3. **Colunas do agente**: rode
   `SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='products' AND COLUMN_NAME='affiliate_code'`.
   Se der 0, as colunas novas ainda não foram criadas: elas nascem sozinhas quando o site (ou `php agente/importar.php`)
   roda com o `src/db.php` atual. Sem elas, gere o SQL com `--basico` (grava só nas colunas antigas) e avise a pessoa.

## Categorias
Leia `agente/categorias.json` (id e slug da tabela `categories` + termo de busca no ML).
Antes de começar, veja o que já foi coletado hoje e pule as categorias completas:
`SELECT category_id, COUNT(DISTINCT ml_id) n FROM agente_coletas WHERE run_date = CURDATE() GROUP BY category_id`
(se a tabela não existir ainda, considere tudo pendente).

## Passo a passo
Os scripts ficam em `agente/scripts/` e rodam com `mcp__claude-in-chrome__javascript_tool` numa aba sua.
O ML usa domínios diferentes (`lista.`, `www.`, `produto.`) e o `localStorage` não é compartilhado entre eles,
então **você controla a fila** e os scripts se instalam uma vez por domínio:
- 1ª vez num domínio: cole o arquivo inteiro, trocando a última linha (a chamada) pelos parâmetros da vez.
- depois: `await new Function('return ' + localStorage.getItem('achadosmeli.agente.<ofertas|produto>'))()({...})`.
  Se a chamada falhar porque o script não está instalado naquele domínio, cole o arquivo inteiro de novo.

**A. Montar a fila (para cada categoria pendente)**
1. Navegue para a busca: `https://lista.mercadolivre.com.br/<termo-com-hifens>` (= digitar o termo na busca do ML).
2. Rode `ofertas.js` com `{ id, slug, n: 5, skip: [...] }` — em `skip` vão os ml_ids já coletados hoje **e todos os que
   já estão na fila de qualquer categoria** (um produto só pode ter uma categoria no banco; as buscas se sobrepõem,
   ex.: moda × esporte).
   Fase A: acha o link de ofertas da categoria nos filtros (**Tipo de promoção → Oferta do dia**; senão Oferta relâmpago;
   senão o menor filtro de **Descontos**) e navega para ele. Se a busca não tiver nenhum desses filtros, ele entra na
   primeira subcategoria (que costuma ter) — rode de novo quando carregar.
3. Quando a página de ofertas carregar, rode de novo (mesmo CAT). Fase B: devolve os 5 primeiros produtos em oferta
   (`produtos[].url`; anúncios patrocinados são pulados). Se `faltam > 0`, volte para `search_path` e repita 2–3
   (ele tenta o próximo link de ofertas) pedindo só o que falta, com os já obtidos em `skip`.
4. Guarde a fila: `[{ category_id, category_slug, position, offers_label, url }]`.

**B. Coletar cada produto**
5. Navegue para a URL do primeiro produto e rode `produto.js` com
   `{ category_id, category_slug, position, offers_label, next: <url do próximo da fila ou null> }`.
   Ele espera a página, extrai os dados, clica em **Compartilhar** na barra preta, lê link `meli.la`, ID do produto,
   texto sugerido e etiqueta, guarda no `localStorage` do domínio e **já navega para `next`**.
   Nas chamadas seguintes basta rodar o `produto.js` instalado com o JOB do produto atual (espere ~2 s após a anterior).
   Se vier `link_afiliado: null`, rode de novo no mesmo produto (com `next` igual) uma vez.

**C. Exportar e gravar**
6. Ordene a fila com os produtos de `www.` primeiro e os de `produto.` por último. Ao terminar, rode `exportar.js`
   numa página de `produto.` (ele leva os resultados para `www.` pela própria aba) e de novo em `www.`: sai um arquivo
   só em `~/Downloads/achadosmeli-coleta-AAAA-MM-DD.json`. Copie para `agente/coletas/AAAA-MM-DD.json`.
   (A saída do `javascript_tool` corta textos longos; não exporte pela conversa.)
7. Grave no banco:
   - ferramenta MySQL: `php agente/gerar-sql.php agente/coletas/AAAA-MM-DD.json` (ou `--basico` se as colunas novas
     ainda não existirem) e rode cada comando (separados por linhas `;;`) com `mysql_query`;
   - terminal: `php agente/importar.php agente/coletas/AAAA-MM-DD.json`.
8. Confira: `SELECT c.slug, COUNT(DISTINCT a.ml_id) n FROM agente_coletas a JOIN categories c ON c.id=a.category_id
   WHERE a.run_date = CURDATE() GROUP BY c.slug` — toda categoria deve ter 5 ou mais
   (sem a tabela, confira por `products.price_updated_at` de hoje).

## Observações da 1ª execução (16/11/2026)
- O botão Compartilhar também adiciona o produto à lista "Minhas recomendações" da sua conta de afiliado.
- Produtos **recondicionados** (ex.: jogos "Excelente (Recondicionado)") não geram link `meli.la`; prefira pular.
- Anúncios patrocinados (links `click1.mercadolivre.com.br/mclics`) são pulados pelo `ofertas.js`.
- Buscas que funcionaram: ver `agente/categorias.json` (ex.: "bebes" cai em `/bebe-e`, "acessorios automotivos" não tem
  filtro de promoção e precisa da subcategoria).

## Regras
- Só leia páginas e clique em **Compartilhar** (painel de link de afiliado). Nunca clique em comprar, adicionar ao
  carrinho, favoritar, nem altere configurações da conta de afiliado.
- **Imagens: não baixe.** Grave só os links (campo `images`, JSON, e a primeira em `image`).
- Não sobrescreva o que a pessoa editou no painel: o SQL já mantém título, categoria, ativo e destaque de produtos existentes.
- Se o ML mostrar captcha, tela de login ou bloqueio, pare e avise a pessoa.
- Ao terminar, feche as abas que você abriu e responda com: total por categoria, quantos novos × atualizados,
  quais ficaram sem link de afiliado e qualquer categoria que não chegou a 5.
