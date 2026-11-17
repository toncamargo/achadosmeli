<?php
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/layout.php';

$site = setting('site_name', 'Achados Meli');
// Canal de contato para assuntos de privacidade: setting "privacy_email" ou o primeiro e-mail de BRAIN_EMAILS do .env
$email = trim((string)setting('privacy_email', ''));
if ($email === '') $email = trim(explode(',', (string)env('BRAIN_EMAILS', ''))[0] ?? '');
$updated = '17 de novembro de 2026';

site_header([
    'title' => 'Política de Privacidade',
    'description' => 'Como o ' . $site . ' trata dados pessoais e cookies: o que é coletado, para quê, com quem é compartilhado e como exercer seus direitos (LGPD).',
    'canonical' => url('politica-de-privacidade.php'),
]);
?>
<main class="wrap">
  <article class="panel legal">
    <h1>Política de Privacidade</h1>
    <p class="muted">Última atualização: <?= e($updated) ?></p>

    <p>Esta política explica como o <b><?= e($site) ?></b> (“nós”) trata dados pessoais de quem visita o site
      <b><?= e(rtrim(preg_replace('#^https?://#', '', url('')), '/')) ?></b> e de quem interage com nossos perfis nas redes sociais,
      de acordo com a Lei Geral de Proteção de Dados (Lei nº 13.709/2018 — LGPD).</p>

    <div class="legal-box">
      <b>Resumo:</b> você não precisa criar conta nem informar dados para usar o site. Não vendemos dados pessoais.
      Usamos cookies técnicos e de anúncios do Google, contamos quantos cliques cada produto recebe (sem identificar quem clicou)
      e, se você comentar em um post nosso no Instagram pedindo um produto, usamos seu nome de usuário para te enviar o link.
      As compras acontecem no Mercado Livre, que tem a própria política de privacidade.
    </div>

    <h2>1. Quem somos</h2>
    <p>O <?= e($site) ?> é um site independente que reúne ofertas do Mercado Livre e participa do Programa de Afiliados do
      Mercado Livre. Não vendemos produtos, não recebemos pagamentos e não fazemos entregas. Somos o controlador dos dados
      tratados neste site, nos termos da LGPD.</p>

    <h2>2. Quais dados tratamos</h2>
    <h3>2.1 Ao navegar no site</h3>
    <ul>
      <li><b>Cliques em produtos:</b> quando você clica em um produto, registramos apenas que aquele produto recebeu mais um clique.
        Não guardamos quem clicou, seu IP nem outro dado que identifique você.</li>
      <li><b>Buscas:</b> o que você digita na busca é usado só para mostrar resultados e sugestões na hora; não guardamos o histórico de buscas.</li>
      <li><b>Registros técnicos do servidor:</b> como todo site, nosso provedor de hospedagem registra automaticamente dados técnicos
        de acesso (endereço IP, navegador, página acessada, data e hora), usados para segurança e funcionamento do serviço.</li>
    </ul>

    <h3>2.2 Cookies e tecnologias parecidas</h3>
    <ul>
      <li><b>Cookie de sessão (necessário):</b> um cookie técnico que mantém o site funcionando corretamente. Ele não é usado para
        publicidade e é apagado quando você fecha o navegador.</li>
      <li><b>Google AdSense (publicidade):</b> exibimos anúncios do Google. O Google e seus parceiros usam cookies para mostrar anúncios,
        inclusive anúncios personalizados com base nas suas visitas a este e a outros sites, e para medir o desempenho dos anúncios.
        Saiba mais em <a href="https://policies.google.com/technologies/ads?hl=pt-BR" target="_blank" rel="noopener">Como o Google usa cookies em publicidade</a>.
        Você pode desativar os anúncios personalizados em
        <a href="https://myadcenter.google.com/?hl=pt-BR" target="_blank" rel="noopener">Minha Central de Anúncios do Google</a>
        ou, para vários fornecedores, em <a href="https://www.aboutads.info/choices/" target="_blank" rel="noopener">aboutads.info</a>.</li>
      <li><b>Google Fonts:</b> as fontes do site são carregadas dos servidores do Google, que recebem o endereço IP do seu navegador
        para entregar os arquivos.</li>
    </ul>
    <p>Você pode bloquear ou apagar cookies nas configurações do seu navegador. Bloquear os cookies de publicidade não impede o uso do site.</p>

    <h3>2.3 Ao clicar em um produto (Mercado Livre)</h3>
    <p>Ao clicar em um produto, você é levado ao site do Mercado Livre por um link de afiliado, que informa ao Mercado Livre que a visita
      veio do <?= e($site) ?> para que possamos receber comissão, sem custo extra para você. A partir daí, o tratamento dos seus dados
      (cadastro, compra, pagamento e entrega) é feito pelo Mercado Livre, conforme a
      <a href="https://www.mercadolivre.com.br/ajuda/993" target="_blank" rel="noopener">Declaração de Privacidade do Mercado Livre</a>.
      Não recebemos seus dados de compra; o programa de afiliados nos informa apenas os totais de vendas e comissões.</p>

    <h3>2.4 No Instagram</h3>
    <p>Se você comentar a palavra indicada em um post do nosso perfil no Instagram para receber o link de um produto, recebemos pela
      plataforma do Instagram (Meta) o seu <b>nome de usuário</b>, o <b>texto do comentário</b> e os identificadores do comentário e do post.
      Usamos esses dados apenas para te enviar o link por mensagem direta, responder ao seu comentário e evitar envios repetidos.
      Não usamos esses dados para outras finalidades nem os compartilhamos com terceiros além da própria Meta.</p>

    <h2>3. Para que usamos os dados e com qual base legal</h2>
    <table class="legal-tbl">
      <tr><th>Finalidade</th><th>Base legal (LGPD, art. 7º)</th></tr>
      <tr><td>Manter o site funcionando e seguro (cookie de sessão, registros do servidor)</td><td>Legítimo interesse</td></tr>
      <tr><td>Saber quais produtos interessam mais (contagem de cliques, sem identificar pessoas)</td><td>Legítimo interesse</td></tr>
      <tr><td>Enviar o link do produto pedido no Instagram</td><td>Atendimento a pedido seu (procedimentos preliminares a pedido do titular)</td></tr>
      <tr><td>Exibir anúncios do Google e medir seu desempenho</td><td>Legítimo interesse e, quando exigido, consentimento</td></tr>
      <tr><td>Cumprir obrigações legais e atender autoridades</td><td>Cumprimento de obrigação legal</td></tr>
    </table>

    <h2>4. Com quem compartilhamos</h2>
    <p>Não vendemos nem alugamos dados pessoais. Os dados podem ser tratados pelos fornecedores que tornam o site possível:</p>
    <ul>
      <li><b>Hostinger</b> — hospedagem do site e do banco de dados;</li>
      <li><b>Google</b> — anúncios (AdSense) e fontes (Google Fonts);</li>
      <li><b>Meta (Instagram)</b> — recebimento de comentários e envio de mensagens no Instagram;</li>
      <li><b>Mercado Livre</b> — destino dos links de afiliado e programa de afiliados.</li>
    </ul>
    <p>Alguns desses fornecedores podem armazenar dados fora do Brasil, com as garantias previstas na LGPD. Também podemos compartilhar dados
      quando a lei exigir ou por ordem de autoridade competente.</p>

    <h2>5. Por quanto tempo guardamos</h2>
    <p>Guardamos os dados apenas pelo tempo necessário para as finalidades descritas acima ou para cumprir obrigações legais.
      Os registros de comentários do Instagram servem para evitar envios repetidos e podem ser apagados a qualquer momento a seu pedido.
      Os cookies de publicidade seguem os prazos definidos pelo Google.</p>

    <h2>6. Seus direitos</h2>
    <p>Pela LGPD, você pode, a qualquer momento:</p>
    <ul>
      <li>confirmar se tratamos dados seus e ter acesso a eles;</li>
      <li>corrigir dados incompletos, inexatos ou desatualizados;</li>
      <li>pedir a anonimização, o bloqueio ou a eliminação de dados desnecessários ou tratados em desacordo com a lei;</li>
      <li>pedir a eliminação dos dados tratados com base no seu consentimento e revogar o consentimento;</li>
      <li>saber com quem compartilhamos seus dados;</li>
      <li>se opor a um tratamento feito com base no legítimo interesse;</li>
      <li>apresentar reclamação à Autoridade Nacional de Proteção de Dados (<a href="https://www.gov.br/anpd" target="_blank" rel="noopener">ANPD</a>).</li>
    </ul>
    <p>Para exercer seus direitos, fale com a gente pelo canal indicado no item 10. Responderemos o mais rápido possível, no prazo previsto em lei.</p>

    <h2>7. Segurança</h2>
    <p>Adotamos medidas técnicas razoáveis para proteger os dados, como conexão criptografada (HTTPS), acesso restrito ao painel de
      administração e coleta mínima de dados. Nenhum sistema é totalmente imune a falhas, mas trabalhamos para reduzir riscos e
      comunicaremos incidentes relevantes conforme a lei.</p>

    <h2>8. Crianças e adolescentes</h2>
    <p>O site não é direcionado a menores de 18 anos e não coletamos intencionalmente dados de crianças e adolescentes.</p>

    <h2>9. Mudanças nesta política</h2>
    <p>Podemos atualizar esta política para refletir mudanças no site ou na lei. A data da última atualização fica sempre no topo desta página.</p>

    <h2>10. Contato</h2>
    <?php if ($email !== ''): ?>
      <p>Dúvidas, pedidos ou reclamações sobre privacidade e dados pessoais: <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a>.</p>
    <?php else: ?>
      <p>Dúvidas, pedidos ou reclamações sobre privacidade e dados pessoais: fale com a gente pelas nossas redes sociais.</p>
    <?php endif; ?>
  </article>
</main>
<?php site_footer();
