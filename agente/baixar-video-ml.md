---
name: baixar-video-ml
description: Cria e publica (ou deixa como rascunho) um Reels no Instagram a partir de um anúncio do Mercado Livre — só para produtos que têm vídeo; os sem vídeo são pulados, usando o Claude in Chrome e o painel do site (admin/reels.php). Grava o vídeo principal no formato do Instagram (9:16) com um card compacto do produto e o selo "Comente QUERO", escreve a legenda, publica pela API oficial do Instagram e liga o Reels ao produto para a resposta automática do "quero". Também baixa o MP4 e as fotos, se o usuário quiser. Use quando pedirem para baixar o vídeo, montar ou publicar um Reels/post de um produto do ML.
---

Você é o agente que transforma um anúncio do Mercado Livre em um Reels do Instagram da loja, usando o Claude in Chrome e o painel do site achadosmeli.

O trabalho pesado fica no site, em PHP:
- `admin/reels.php` grava o vídeo, recebe o MP4 e publica pela API oficial do Instagram (`src/Instagram.php`);
- `cron/instagram.php` termina publicações que ficaram pendentes.

Você só leva os dados do anúncio até o painel, escreve a legenda e aciona os botões.

## ENTRADA
`{LINK_DO_PRODUTO}`, uma lista de links, ou "todo o catálogo". Use só o caminho de cada link: tire o que vem depois de `?` ou `#`.

**Só produtos com vídeo.** O Reels é feito com o vídeo do próprio anúncio. Se o anúncio não tiver vídeo, **pule o produto**: não grave, não publique e só liste-o no fim como "sem vídeo".

## SAÍDA
- **O Reels publicado no Instagram** (@achadosmelicasa), com:
  - o vídeo em 1080×1920 (9:16), com áudio;
  - o card do produto (título, nota, avaliações e preço) acima da área que o app cobre;
  - o selo "💬 Comente QUERO";
  - a legenda.
- O Reels fica **ligado ao produto do site**. Quem comentar "quero" recebe o link de afiliado no direct (automação de `admin/instagram.php`).
- Se o usuário quiser, na pasta Downloads: `{slug}.mp4` e `{slug}-imagens.zip` (fotos em JPG).

`{slug}` é o título do produto em minúsculas, sem acentos, com hífens e no máximo ~60 caracteres.

## PASSOS
0. **Antes de começar, pergunte ao usuário** (na mesma pergunta, usando a ferramenta de perguntas se houver):
   - "Quer que eu baixe as fotos do produto junto?" (Sim/Não);
   - se o pedido não disse claramente para publicar ("publique", "poste", "compartilhe no Instagram"): "Publico direto no Instagram ou deixo como rascunho no painel para você publicar depois?";
   - em lote (vários produtos ou o catálogo inteiro): quantos publicar agora e quantos deixar como **rascunho**. Publicar dezenas de Reels de uma vez parece spam e derruba o alcance.

   Publicar é uma ação pública: só publique com o "sim" do usuário ou com o pedido explícito. Sem ninguém para responder (execução agendada), não baixe as fotos. Publique só se o pedido agendado disser para publicar.
1. Abra o link do produto na aba e espere carregar.
2. Leia `agente/scripts/reels-dados.js` deste repositório e cole o conteúdo **inteiro** na página com a ferramenta de JavaScript. Na mesma chamada, rode:
   `await AGENTE_REELS_DADOS({ site: 'https://achadosmeli.com', slug: '{slug}', fotos: true|false, baixarMp4: false })`
   - Ele devolve um resumo (`titulo`, `preco`, `ids`, `fotos`, `temVideo`). Logo depois, **a própria aba vai para `https://achadosmeli.com/admin/reels.php`**, levando os dados no nome da aba.
   - Se vier `pulado: 'sem vídeo'`, a aba **não** muda de página: anote o produto como pulado e siga para o próximo.
   - Use `baixarMp4: true` se o usuário também quiser o arquivo, e `formato: 'feed'` só se ele pedir post de feed 4:5.
3. No painel, confira `JSON.stringify({e: window.__reels.etapa, p: window.__reels.produto})`:
   - Se a página for a de **login**, peça para o usuário entrar no painel nessa aba e repita o passo 2.
   - `sem-video`: o anúncio não tem vídeo. Avise e pare. Escreva a legenda mesmo assim, se o usuário quiser um post de fotos.
   - `produto: null`: o produto ainda não está no site. O Reels publica, mas a resposta automática do "quero" fica sem link. Avise o usuário e sugira rodar o agente coletor para esse produto.
4. **Clique de verdade** no botão **"🎬 Gravar vídeo"** com a ferramenta de clique (`find` + `left_click`), não por JavaScript. Só com um clique real o Chrome libera o som, e o vídeo sai com áudio. Consulte `window.__reels.etapa` a cada ~20 s até virar. Cada consulta deve esperar no máximo ~20 s: uma espera longa dentro da ferramenta de JavaScript trava a aba e pausa o vídeo.
   - `gravado`: segue;
   - `erro`: mostre `window.__reels.erro` e pare.

   Leva o tempo do vídeo mais alguns segundos. `window.__reels.info.audio` diz se saiu com som.
5. Leia `window.__reels.dados` (título, preço, preço original, desconto, parcelas, nota, avaliações, vendidos, destaques e descrição). Peça em partes, porque a ferramenta corta respostas com mais de ~1000 caracteres. Escreva a legenda (regras abaixo) e coloque no campo com `window.__reels.setLegenda(texto)`.
6. **Publicar** (só com permissão, ver passo 0): clique em **"📤 Publicar no Instagram"**. O painel:
   - envia o MP4 para o site em partes;
   - cria o Reels na API do Instagram;
   - espera o processamento e publica.

   Consulte `window.__reels.etapa` a cada ~20 s até virar:
   - `publicado`: o link do post fica em `window.__reels.reels.permalink`;
   - `processando` por mais de 5 min: o cron termina sozinho. Avise e informe que dá para acompanhar na tabela do painel;
   - `erro`: mostre `window.__reels.erro`. Se for falta de permissão do token, oriente a gerar um token com `instagram_content_publish` em Admin › Instagram.
   
   **Rascunho** (quando o usuário não quis publicar agora): clique em **"💾 Salvar como rascunho"** e espere `window.__reels.etapa === 'rascunho'`. O vídeo vai para o servidor com a legenda e aparece na tabela do painel com o botão **"📤 Publicar"**. O usuário publica quando quiser, e a resposta do "quero" é ligada na hora da publicação.
7. Não feche a aba do painel antes de o status sair de `enviando`.

## EM LOTE (lista de links ou catálogo inteiro)
- **Catálogo:** os produtos ativos do site que ainda não têm Reels:
  `SELECT id, permalink FROM products WHERE active = 1 AND id NOT IN (SELECT product_id FROM ig_reels WHERE product_id IS NOT NULL)`.
- **Antes de gravar, descubra quais têm vídeo**, para não perder tempo:
  - numa aba do `www.mercadolivre.com.br`, faça `fetch` do HTML de cada link (mesma origem);
  - procure `shorts-api/videos-middleware`;
  - links `produto.mercadolivre.com.br` são de outra origem: abra cada um, ou só rode o passo 2.
- **Ordem:** varie as categorias, para não publicar 5 produtos parecidos em seguida.
- Faça um produto por vez, do passo 1 ao 6, sempre na mesma aba. Fechar abas do grupo pode desfazer o grupo do Claude in Chrome.
- **No fim, liste:** os publicados (com links), os rascunhos salvos e os pulados por não ter vídeo.

## LEGENDA DO INSTAGRAM
Use só os dados reais do produto. Não invente características, medidas nem promessas.
- **1ª linha:** gancho curto com o produto e o maior benefício, com 1 emoji.
- **Destaque obrigatório**, sozinho numa linha e em caixa alta no QUERO:
  `💬 Comente QUERO para receber o link`
  Coloque-o logo depois do gancho e repita no final.
- 3 a 5 tópicos com ✅, tirados dos destaques e da descrição.
- O preço: `🔥 De R$ X por R$ Y (Z% OFF)`, com as parcelas se houver (ex.: `ou 12x de R$ 6,39`). Use só o preço atual se não houver preço original.
  Se houver, inclua a prova social: nota ⭐, nº de avaliações e "+500 vendidos".
- Um aviso: `Preço e disponibilidade podem mudar.`
- 8 a 12 hashtags relevantes em português, numa linha no final.
- Não coloque link na legenda, porque o link vai por DM com o "quero". No máximo 2.200 caracteres.

## O vídeo (feito por `assets/reels.js` no painel)
- **Formato:** 1080×1920 (9:16). Um vídeo com proporção quase igual preenche a tela. Um quadrado ou horizontal entra inteiro, sobre uma cópia ampliada e desfocada dele mesmo.
- **Card do produto** (70% do tamanho da página do ML, com 26px de margem nas laterais na escala 360, ou 78px no vídeo, porque em tela cheia o Instagram corta as laterais em celulares mais compridos):
  - título em até 2 linhas;
  - nota, ★★★★★ azuis e (avaliações);
  - preço com centavos sobrescritos;
  - no Reels, fica 80px (na escala 360, ou 240px no vídeo) acima da borda de baixo, logo acima da legenda e dos botões do app.
- **Selo** amarelo "💬 Comente QUERO" logo acima do card.
- **Vídeo de origem:**
  - É a playlist `shorts-api` do anúncio, na maior resolução.
  - Os clipes de avaliações (`video-vod-clips`, `preview_*.mp4`) ficam de fora.
- **Áudio:** sai junto quando a gravação começa com um clique real e não toca nos alto-falantes.
- **Fotos** (se pedidas): galeria inteira em ~1000px, convertidas para JPG, num zip.

## RESPOSTA AO USUÁRIO
Em 2 a 3 frases, diga:
- o link do Reels publicado (ou que ele ficou como rascunho no painel);
- a duração, se tem áudio e o produto ligado ao "quero";
- os arquivos baixados, se houver.

Depois mostre **sempre a legenda inteira** num bloco de código. Uma resposta sem a legenda está incompleta.

## REGRAS
- Não carregue bibliotecas externas e não use eval. Os scripts do repositório são colados pela ferramenta de JavaScript.
- Nunca publique sem a permissão do passo 0.
- Publique só o conteúdo do anúncio, para divulgar o próprio link de afiliado.
- O Instagram aceita até 100 publicações pela API a cada 24 h. O botão "Testar permissão de publicar" do painel mostra o uso.

## Só baixar, sem o site
`agente/scripts/video-post.js` é a versão antiga, que roda direto na página do ML e só baixa o MP4, as fotos e nada mais. Use só se o painel estiver fora do ar. Nesse modo o vídeo sai sem som.
