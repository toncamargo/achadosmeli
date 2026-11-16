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

## Fluxo de uso
1. **Buscar no ML**: pesquise por palavra-chave, marque os produtos e importe numa categoria.
   Ou cole links de produtos do ML em "Importar por link".
2. **Link de afiliado**: o ML não tem API pública para gerar link de afiliado. Para cada produto,
   abra-o no ML logado na sua conta de afiliado, gere o link (`meli.la/...`) pela barra de afiliados
   e cole na tela **Editar** do produto. O painel mostra quantos produtos estão "sem link afiliado".
3. **Divulgar**: na tela Editar há um texto pronto (preço, desconto, cupom, frete) com botões de
   copiar / WhatsApp / Telegram e "Marcar como divulgado" — o filtro "Ainda não divulgados" ajuda a
   montar a fila de posts.

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
sitemap.php          sitemap para o Google
admin/               painel (produtos, buscar no ML, categorias, configurações)
src/                 bootstrap, banco, helpers, cliente da API do ML, layouts
cron/                atualização de preços via linha de comando
data/                banco SQLite (bloqueado para acesso web)
assets/style.css     estilos do site e do painel
```

## Próximos passos sugeridos
- Postagem automática no Telegram (Bot API) a partir da fila "não divulgados"
- Integração com WhatsApp/Instagram para publicar os achados
- URLs amigáveis (`/p/slug`) via `.htaccess`
- Alertas de queda de preço usando a tabela `price_history`
