# Achados — site de afiliados do Mercado Livre (PHP)

Site em PHP puro (sem framework) que busca produtos na API do Mercado Livre, guarda no banco
e lista numa vitrine pública. Cada clique passa por `ir.php`, que conta o clique e manda para o
**seu link de afiliado**.

## Requisitos
- PHP 8.1+ com `pdo_sqlite` (ou `pdo_mysql`), `curl` e `mbstring`
- Qualquer hospedagem PHP comum (Hostinger, Locaweb, HostGator…) ou VPS
- **HTTPS** no domínio — o Mercado Livre só aceita Redirect URI com https

## Rodar localmente
```bash
cd achadosmeli
php -S localhost:8080
```
Abra http://localhost:8080/admin/ → crie a senha do painel.

> Localmente a conexão com o ML não funciona porque a Redirect URI precisa ser https.
> Para testar a API no seu computador, use um túnel (ex.: `ngrok http 8080`) e coloque
> a URL do ngrok em `base_url` no `config.php`.

## Configuração (.env)
Os dados do banco e da aplicação ficam no arquivo **`.env`** na raiz (modelo em `.env.example`):

| Variável | Para que serve |
|---|---|
| `APP_URL` | URL pública, sem barra final (ex.: `https://achadosmeli.com.br`). Vazio = detecta sozinho |
| `APP_DEBUG` | `true` mostra o erro técnico na tela. Use `false` em produção |
| `DB_DRIVER` | `mysql` ou `sqlite` |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Acesso ao MySQL/MariaDB |
| `DB_SQLITE_PATH` | Arquivo do SQLite (quando `DB_DRIVER=sqlite`) |

- As tabelas são criadas automaticamente no primeiro acesso. Se o banco ainda não existir e o usuário
  tiver permissão, ele também é criado (`utf8mb4`).
- Se o banco não conectar, o site mostra uma página pedindo para conferir o `.env`.
- Variáveis definidas no painel da hospedagem têm prioridade sobre o arquivo.
- O `.env` está no `.gitignore` e bloqueado no `.htaccess` (Apache). **Em Nginx**, bloqueie também:
  `location ~ /\.env { deny all; }`

## Colocar no ar
1. Suba a pasta inteira para a raiz (ou subpasta) do seu domínio.
2. Crie o banco MySQL no painel da hospedagem e preencha o `.env` (`APP_URL`, `DB_*`, `APP_DEBUG=false`).
3. Se for usar SQLite, garanta que a pasta `data/` tenha permissão de escrita.
4. Acesse `/admin/`, crie a senha e vá em **Configurações**.

## Conectar a API do Mercado Livre
Desde 2025 a API do ML exige token de acesso até para buscar produtos.
1. Crie um app em https://developers.mercadolivre.com.br/devcenter
2. Em **Redirect URI**, cole a URL que aparece em Configurações (`.../admin/ml-callback.php`).
3. Copie **App ID** e **Secret Key** para Configurações → salvar → **Conectar conta Mercado Livre**.
4. O token é renovado sozinho (refresh token).
5. Clique em **Testar conexão**: o painel diz se o seu app tem acesso à busca de anúncios ou só ao catálogo,
   e se o endpoint de mais vendidos está liberado.

> Desde 2025 o ML bloqueia `/sites/MLB/search` (busca de anúncios) para a maioria dos apps novos.
> Quando isso acontece, o site usa a busca de **catálogo** (`/products/search`) e consulta o preço
> produto a produto — e lembra disso por 24h para não gastar chamadas. A fonte mais estável para
> coleta é **Mais vendidos** (`/highlights/MLB/category/{id}`).

## Coleta automática (Painel → Coleta automática)
Cada **fonte** define onde procurar e o que aceitar:

| Campo | O que faz |
|---|---|
| Tipo | **Busca por palavra-chave** ou **Mais vendidos de uma categoria do ML** (top 20) |
| Categoria do ML | `MLB...` — dá para navegar pelas categorias e subcategorias na própria tela |
| Categoria no seu site | Onde os produtos novos entram |
| Filtros | Desconto mínimo, preço mín./máx., só frete grátis, só FULL, palavras proibidas no título |
| Máx. por execução / Rodar a cada | Quantos produtos aceitar por vez e o intervalo do cron |
| Publicar direto | Ligado: entra ativo no site. Desligado: entra **inativo** para revisão (filtro "Coletados aguardando revisão" em Produtos) |

Produtos que já existem só têm preço/foto atualizados — o que você editou (título, categoria, link, ativo) é mantido.
Itens do tipo `USER_PRODUCT` nos mais vendidos são ignorados (não há endpoint público para resolvê-los).

Cron (de hora em hora; cada fonte respeita o próprio intervalo):
```
0 * * * * php /caminho/achadosmeli/cron/coletar.php
```
`--todas` ignora o intervalo e `--fonte=ID` roda uma fonte só.

## Fluxo de uso
1. **Buscar no ML**: pesquise por palavra-chave, marque os produtos e importe numa categoria.
   Ou cole links de produtos do ML em "Importar por link".
2. **Link de afiliado**: o ML não tem API pública para gerar link de afiliado. Duas formas:
   - **Automática (recomendada com a coleta):** em Configurações → Link de afiliado, cole um link seu
     (`meli.la/...` ou a URL completa). O site lê `matt_word` e `matt_tool` e aplica em todos os produtos
     sem link próprio. Se o `meli.la` não abrir a partir do servidor, abra o link no navegador e cole a URL final.
     Teste em aba anônima e confira na Central de Afiliados se os cliques estão sendo contados.
   - **Por produto:** gere o `meli.la/...` pela barra de afiliados e cole na tela **Editar** do produto
     (tem prioridade sobre o automático).
3. **Divulgar**: na tela Editar há um texto pronto (preço, desconto, cupom, frete) com botões de
   copiar / WhatsApp / Telegram e "Marcar como divulgado" — o filtro "Ainda não divulgados" ajuda a
   montar a fila de posts.

## Agente coletor de ofertas (navegador)
Em `agente/` (definição em `agente/coletor-ofertas-ml.md`): um agente do Claude que, com a sua conta de afiliado
logada no Chrome, pesquisa cada categoria no Mercado Livre, abre o link de ofertas da categoria, pega os
5 primeiros produtos, clica em **Compartilhar** na barra preta de Afiliados (link `meli.la`) e grava produto,
fotos (só os links) e dados de afiliado no banco. Para rodar, peça ao Claude: "rode o agente coletor-ofertas-ml".
Detalhes em `agente/README.md`.

## Instagram: responder comentários com o link (Painel → Instagram)
Quem comenta a palavra-chave (padrão “quero”) num post cadastrado recebe o link do produto **por direct**
(Private Reply da Meta) e uma resposta curta no comentário (“Te mandei no direct 📩”). O link passa pelo `ir.php`,
então os cliques vindos do Instagram também são contados.

- Post com 1 produto → manda ele. Vários produtos → “quero 2” manda o 2º; só “quero” manda a lista.
- Só responde comentários feitos depois do cadastro do post, uma vez por comentário, até 7 dias (limite da Meta).
- Conta precisa ser **profissional** (Criador ou Empresa) e **pública**.

Configuração:
1. Crie um app em developers.facebook.com, adicione **Instagram → API setup with Instagram login**.
2. Em Admin → Instagram: cole o **Instagram app secret**, gere o token da sua conta no app da Meta e cole em “Access token”.
3. Ligue cada post aos IDs dos produtos na própria tela.

Duas formas de receber os comentários:
- **Cron (funciona já, acesso padrão):** a cada 5 min — também renova o token (vale 60 dias)
  ```
  */5 * * * * php /caminho/achadosmeli/cron/instagram.php
  ```
- **Webhook (tempo real):** `https://SEU-DOMINIO/instagram/webhook.php` com o Verify token mostrado no painel,
  campo `comments`. A Meta só envia com o app em modo **Live** e **Acesso Avançado** (análise do app + verificação da empresa).

## Instagram: publicar Reels de um produto (Painel → Reels)
O painel `admin/reels.php` publica um Reels pela **API oficial do Instagram** (Content Publishing):
1. O agente `agente/baixar-video-ml.md` lê o anúncio do ML e abre o painel com os dados.
2. O vídeo é gravado no navegador (`assets/reels.js`): 1080×1920, card do produto, selo "💬 Comente QUERO" e áudio.
3. Ao clicar em **Publicar no Instagram**, o MP4 vai em partes de 1,5 MB para `assets/reels/` (a Meta exige uma URL
   pública). O PHP cria o contêiner `REELS`, espera o processamento e chama `media_publish`. Depois disso, apaga o MP4.
4. O Reels fica ligado ao produto em `ig_posts`. Quem comentar "quero" recebe o link no direct.
5. O que não terminar na hora é concluído pelo `cron/instagram.php`.

Requisitos:
- O token conectado em Admin → Instagram precisa da permissão de publicar:
  - `instagram_content_publish`, para token `EAA…` (login do Facebook);
  - `instagram_business_content_publish`, para token `IGAA…` (login do Instagram).
- O botão "Testar permissão de publicar" do painel confere isso.
- Limite da Meta: 100 publicações pela API a cada 24 h.
- Histórico em `ig_reels`.

## Insights (Painel → Insights)
Cada clique no `ir.php` grava uma linha em `click_events` (a tabela é criada sozinha no primeiro acesso):
origem (`site`, `instagram`, `social`, `legado`…), onde o card estava (`home-grade`, `home-prateleira`,
`home-oferta-do-dia`, `home-banner`, `categoria-grade`, `busca-grade`, `direct`), post do Instagram, aparelho e um
visitante anônimo (hash diário, sem guardar IP). Cliques feitos logado no painel ficam marcados e não entram nos números.

O painel `admin/insights.php` mostra, para hoje / 7 / 30 / 90 dias: cliques e visitantes, variação contra o período
anterior, cliques por dia e por hora, origem, aparelho e posição no site, categorias (cliques por produto ativo e coleta
do dia contra a meta), produtos mais clicados, funil do Instagram (comentários → links enviados → cliques), saúde do
catálogo, quedas de preço e produtos sem clique. No topo, leituras automáticas do tipo "pico às 21h, publique às 20h".

- Links com origem própria: `ir.php?id=123&src=whatsapp` (aceitos: site, instagram, social, whatsapp, telegram, tiktok, bio, email).
- Meta diária de coleta por categoria: setting `agente_meta_dia` (padrão 5).

## Atualizar preços automaticamente
Configure um cron na hospedagem (a cada 3h, por exemplo):
```
0 */3 * * * php /caminho/achadosmeli/cron/atualizar-precos.php
```
Anúncios pausados/encerrados no ML são desativados no site. Também dá para atualizar pelo painel
(ação em massa "Atualizar preços").

## Estrutura
```
index.php            vitrine (busca, categorias, ordenação, destaques)
produto.php          página do produto (SEO + JSON-LD)
ir.php               redirect para o link de afiliado + contagem de cliques
instagram/webhook.php webhook de comentários do Instagram
sitemap.php          sitemap para o Google
admin/               painel (produtos, buscar no ML, coleta automática, categorias, configurações)
src/                 bootstrap, banco, helpers, cliente da API do ML, coletor, layouts
agente/              agente coletor de ofertas via navegador (scripts, SQL, coletas)
cron/                coleta automática (coletar.php), atualização de preços (atualizar-precos.php) e Instagram (instagram.php)
data/                banco SQLite (bloqueado para acesso web)
assets/style.css     estilos do site e do painel
```

## Próximos passos sugeridos
- Postagem automática no Telegram (Bot API) a partir da fila "não divulgados"
- Integração com WhatsApp e publicação automática de posts no Instagram
- URLs amigáveis (`/p/slug`) via `.htaccess`
- Alertas de queda de preço usando a tabela `price_history`
