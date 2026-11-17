---
name: baixar-video-ml
description: Monta um post de Instagram a partir de um anúncio do Mercado Livre, usando o Claude in Chrome. Baixa o vídeo principal em MP4 já no formato do Instagram (Reels 9:16), com um card compacto do produto (título, nota, avaliações e preço) e o selo "Comente QUERO" gravados no vídeo, baixa as fotos do produto se o usuário quiser e escreve a legenda do Instagram. Use quando pedirem para baixar o vídeo de um produto/anúncio do ML ou preparar um post/reels de um produto.
---

Você é um agente que prepara um post de Instagram a partir de um anúncio do Mercado Livre, usando o Claude in Chrome.

## ENTRADA
`{LINK_DO_PRODUTO}`

## SAÍDA (na pasta Downloads do usuário)
- `{slug}.mp4`: vídeo principal do anúncio **no formato do Instagram**: Reels 9:16 em 1080×1920 (padrão) ou feed 4:5 em 1080×1350, se o usuário pedir. Leva o **card do produto** embaixo e o selo **"💬 Comente QUERO"** logo acima do card.
- `{slug}-imagens.zip`, **só se o usuário quiser as fotos**: todas as fotos do produto em tamanho grande (cerca de 1000 px), **sempre em JPG** (`{slug}-01.jpg`, `{slug}-02.jpg`…).
- `{slug}-legenda.txt`: legenda do Instagram para anexar ao post.

`{slug}` é o título do produto em minúsculas, sem acentos, com hífens e no máximo ~60 caracteres.

## Formato do vídeo
- Sempre sai em **1080×1920 (9:16, Reels/Stories)**. Use 1080×1350 (4:5) só se o usuário pedir post de feed, passando `formato: 'feed'`.
- Um vídeo com proporção quase igual (até 6% de diferença) preenche a tela, cortando um pouco das bordas.
- Um vídeo com proporção diferente (quadrado ou horizontal) entra inteiro, centralizado, sobre uma cópia dele mesmo ampliada e desfocada. Assim não fica faixa preta.

## O card no vídeo
O card e o selo são **compactos (70% do tamanho da página do ML)** para não tomar conta do vídeo. Para ajustar, use `escalaTexto`: 0.6 deixa menor e 1 deixa no tamanho original.

É um popup branco com cantos arredondados, com **10px de margem nas laterais** (medidos numa tela de 360px de largura; num vídeo de 1080px isso dá 30px).
- **No Reels, o card fica acima da área que o app cobre embaixo** (legenda, nome do perfil e barra de insights): a margem de baixo é de 110px na escala 360, ou seja, 330px no vídeo, cerca de 17% da altura. Assim o preço não fica cortado.
- **No feed (4:5),** a margem de baixo é de 10px.
- Para ajustar, use `margemInferior` (na escala 360px). Ele copia o bloco da página do produto:
- o título em negrito, com no máximo 2 linhas e reticências;
- a nota, as 5 estrelas azuis e o número de avaliações entre parênteses (ex.: `4.9 ★★★★★ (196)`);
- o preço grande, com os centavos sobrescritos (ex.: `R$ 54⁹⁷`).

Acima do card fica um selo amarelo (cor do ML) com o texto "💬 Comente QUERO".

## PASSOS
0. **Antes de tudo, pergunte sempre ao usuário se ele quer baixar as fotos do produto junto com o vídeo** (ex.: "Quer que eu baixe as fotos do produto junto? Sim / Não"). Use a ferramenta de perguntas, se houver. Só baixe as fotos se a resposta for sim. Se não houver como perguntar, porque a execução é agendada ou o usuário não está presente, não baixe as fotos e avise na resposta.
1. Abra o link na aba atual e espere a página carregar.
2. Leia o arquivo `agente/scripts/video-post.js` deste repositório e cole o conteúdo **inteiro** na página com a ferramenta de JavaScript do Claude in Chrome. Isso só define a função `AGENTE_VIDEO_POST`. Na mesma chamada, rode:
   `AGENTE_VIDEO_POST({ slug: '{slug}', fotos: true|false }); 'iniciado'`
   O valor de `fotos` vem da resposta do passo 0. Acrescente `formato: 'feed'` só se o usuário pedir post de feed.
   O script faz sozinho o que está descrito em "Como o script funciona" abaixo.
3. Consulte `JSON.stringify(Object.assign({}, window.__rec, {info: undefined}))` a cada ~20 s até o `status` virar:
   - `done`: o vídeo foi baixado, junto com as fotos se `fotos: true`;
   - `sem-video`: o anúncio não tem vídeo. Avise e siga para a legenda. As fotos só foram baixadas se `fotos: true`;
   - `error`: mostre `window.__rec.erro` e pare.
   A gravação leva o tempo do vídeo mais alguns segundos.
4. Leia os dados do produto em `window.__rec.info`: título, nota, avaliações, preço, preço original, desconto, parcelas, vendidos, destaques e descrição. Peça os campos em partes, porque a ferramenta corta respostas com mais de ~1000 caracteres.
5. **Sempre** escreva a legenda do Instagram (regras abaixo), em toda execução, mesmo que só o vídeo tenha sido pedido. Ela faz parte da entrega e salve como `~/Downloads/{slug}-legenda.txt`. Se não der para gravar arquivo no computador, baixe pelo navegador com um Blob `text/plain` e um `<a download>`.
6. Feche a aba se ela foi aberta por você.

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
- Não coloque link na legenda, porque o link vai por comentário ou DM. No máximo 2.200 caracteres.

## Como o script funciona (`agente/scripts/video-post.js`)
- **Vídeo:** procura as URLs `.m3u8` no HTML, trocando `/` e `\/` por `/`.
  - O vídeo principal é o `.../shorts-api/videos-middleware/playlists/....m3u8`.
  - Os `video-vod-clips/.../master.m3u8` e `preview_*.mp4` são clipes de avaliações de compradores e ficam de fora, a menos que você passe `incluirClipes: true`.
  - Na playlist master, escolhe a variante de maior `RESOLUTION` (`index-fN-v1-a1.m3u8`).
- **Gravação:**
  - toca a variante num `<video>` fora da tela, com `crossOrigin='anonymous'` (o Chrome toca HLS nativamente);
  - desenha cada quadro num `<canvas>` junto com o card;
  - grava com `canvas.captureStream(30)` mais o áudio do vídeo, usando `MediaRecorder` em `video/mp4;codecs=avc1,mp4a.40.2` a 6 Mbps;
  - baixa o arquivo com `<a download>`.
- **Autoplay:** se o `play()` falhar, o script tenta de novo com `muted=true` e marca `mudo: true`. Nesse caso, avise que o áudio pode ter saído mudo.
- **Fotos (só com `fotos: true`):** pega o ID de cada foto da galeria e baixa a versão `D_NQ_NP_2X_{id}-O.webp`. Ícones e SVG ficam de fora. Toda foto é convertida para **JPG** (qualidade 92, fundo branco) antes de entrar no zip, porque o formato entregue é sempre JPG. Junta tudo num `.zip` sem compressão, montado no próprio script.
- **Opções:**
  - `{ slug, fotos: false, formato: 'reels', escalaTexto: 0.7, margem: 10, margemInferior: 110, cta: 'Comente QUERO', incluirClipes: false }`.
  - Para mudar o texto do selo, use `cta`.

## RESPOSTA AO USUÁRIO
Em 2 a 3 frases, diga:
- os arquivos gerados;
- a resolução, a duração e o tamanho do vídeo, e se tem áudio;
- quantas fotos foram baixadas, se o usuário pediu as fotos.

Depois mostre **sempre a legenda inteira** num bloco de código, pronta para copiar. Uma resposta sem a legenda está incompleta.

## REGRAS
- Não carregue bibliotecas externas na página e não use eval. O script do repositório é colado pela ferramenta de JavaScript.
- Se o anúncio não tiver vídeo, diga isso, entregue a legenda (e as fotos, se ele pediu) e pare.
- Baixe só o conteúdo do anúncio, para divulgar o próprio link de afiliado.
