# Agente coletor de ofertas (Mercado Livre → achadosmeli)

Coleta **pelo menos 5 produtos em oferta por categoria do site, por dia**, navegando no mercadolivre.com.br
com a sua conta de afiliado logada, e grava tudo na tabela `products` com o link `meli.la`.

O agente é um subagente do Claude, definido em `agente/coletor-ofertas-ml.md`. Ele usa o navegador
(Claude in Chrome) e os scripts desta pasta. Para o Claude Code reconhecê-lo pelo nome, copie uma vez:
`mkdir -p .claude/agents && cp agente/coletor-ofertas-ml.md .claude/agents/`

## Como rodar
No Claude (Claude Code ou chat com o repositório conectado), dentro do projeto:

> rode o agente coletor-ofertas-ml

Para rodar todo dia, crie uma tarefa agendada com esse mesmo pedido (o Chrome precisa estar aberto e logado).

## O que ele faz, por categoria
1. Pesquisa o termo da categoria no ML (`agente/categorias.json`).
2. Nos filtros da busca, abre o link de ofertas: **Tipo de promoção → Oferta do dia**
   (se não houver: Oferta relâmpago → menor filtro de Descontos).
3. Pega os 5 primeiros produtos em oferta (pula os já coletados no dia).
4. Abre cada produto, extrai os dados e clica em **Compartilhar** na barra preta de Afiliados para pegar
   link `meli.la`, ID do produto, texto sugerido e etiqueta.
5. Grava no banco (produto novo entra ativo; produto existente tem preço e dados do ML atualizados,
   mantendo título, categoria, ativo e destaque que você editou).

## Dados gravados em `products`
| Coluna | Origem |
|---|---|
| `ml_id`, `item_id`, `permalink` | ID de catálogo (`/p/MLB…`) ou do anúncio, e link canônico |
| `title`, `description`, `highlights` (JSON) | título, descrição e "O que você precisa saber" |
| `price`, `original_price`, `discount_label`, `pix_price` | preços e selo de desconto |
| `installments_qty`, `installments_value`, `installments_free` | parcelamento |
| `image`, `images` (JSON) | **links** das fotos (não são baixadas): 1ª foto e a galeria inteira |
| `rating`, `reviews_count`, `sold_label` | nota, nº de avaliações, "+500 vendidos" |
| `badge` | OFERTA DO DIA, OFERTA RELÂMPAGO, MAIS VENDIDO… |
| `free_shipping`, `is_full`, `delivery_label`, `coupon_label` | entrega e cupom |
| `brand`, `brand_verified`, `store_name`, `item_condition`, `ml_breadcrumb` | marca, loja oficial, loja, condição, categoria no ML |
| `affiliate_url`, `affiliate_code`, `affiliate_text`, `affiliate_tag` | painel **Compartilhar** de afiliados |
| `category_id`, `collected_at` | categoria do site e data da coleta |

Além disso: `price_history` (preço do dia) e `agente_coletas` (um registro por produto por dia,
usado para conferir as 5 ofertas por categoria).

As colunas novas e a tabela `agente_coletas` são criadas automaticamente pelo `src/db.php` na primeira
vez que o site (ou `php agente/importar.php`) roda com esta versão do código.

## Arquivos
```
coletor-ofertas-ml.md       definição do agente (instruções passo a passo)
baixar-video-ml.md          agente que monta o post do Instagram (vídeo com card + fotos + legenda)
scripts/video-post.js       grava o vídeo com o card do produto e baixa as fotos (usado pelo baixar-video-ml)
categorias.json             categorias do site → termo de busca no ML (edite os termos à vontade)
scripts/ofertas.js          busca → link de ofertas da categoria → 5 primeiros produtos
scripts/produto.js          coleta o produto + painel Compartilhar e vai para o próximo
scripts/extrair-produto.js  só a extração de dados (para testar numa página de produto)
scripts/status.js           resumo do dia por domínio (por categoria, sem link)
scripts/exportar.js         exporta o JSON coletado (em blocos)
gerar-sql.php               JSON → SQL (para gravar por uma ferramenta MySQL)
importar.php                JSON → banco direto, usando o .env do site
lib.php                     regras de gravação (upsert, histórico, log)
coletas/                    JSON de cada dia
```

## Outro agente: post de Instagram de um anúncio
`agente/baixar-video-ml.md` prepara um post a partir de um anúncio do ML, tudo no próprio Chrome (sem script externo):
- `{slug}.mp4`: vídeo principal com o card do produto (título, nota, preço) embaixo e o selo "💬 Comente QUERO";
- `{slug}-imagens.zip`: todas as fotos (~1000 px), sempre em JPG;
- `{slug}-legenda.txt`: legenda do Instagram com "💬 Comente QUERO para receber o link".

Os arquivos vão para a pasta Downloads. Para registrar: `cp agente/baixar-video-ml.md .claude/agents/`

> rode o agente baixar-video-ml com https://www.mercadolivre.com.br/...
