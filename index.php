<?php
declare(strict_types=1);
$isUsableIpv4 = static fn(string $address): bool =>
  filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
  && !str_starts_with($address, '127.')
  && !str_starts_with($address, '169.254.')
  && $address !== '0.0.0.0';
$interfaceIps = [];
if (function_exists('net_get_interfaces')) {
  foreach (net_get_interfaces() as $interface) {
    if (empty($interface['up'])) continue;
    foreach ($interface['unicast'] ?? [] as $unicast) {
      $address = $unicast['address'] ?? '';
      if ($isUsableIpv4($address)) $interfaceIps[] = $address;
    }
  }
}
$hostnameIp = gethostbyname(gethostname());
$serverIp = '';
foreach (array_unique([$_SERVER['SERVER_ADDR'] ?? '', $hostnameIp, ...$interfaceIps]) as $candidateIp) {
  if ($isUsableIpv4($candidateIp)) {
    $serverIp = $candidateIp;
    break;
  }
}
?><!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#f4eee3">
  <title>Eu vou fazer um leilão</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
  <script src="app.js?v=<?= filemtime(__DIR__ . '/app.js') ?>" defer></script>
</head>
<body data-server-ip="<?= htmlspecialchars($serverIp, ENT_QUOTES, 'UTF-8') ?>">
  <header class="topbar">
    <a class="brand" href="./" aria-label="Eu vou fazer um leilão, início">
      <span class="brand-mark" aria-hidden="true">E</span>
      <span>EU VOU FAZER<span class="brand-light"> UM LEILÃO</span></span>
    </a>
    <span class="network-status"><span></span> SALA LOCAL</span>
  </header>

  <main id="app" class="page-shell">
    <section id="home-view" class="home-layout">
      <div class="intro">
        <p class="eyebrow">UMA RODADA ENTRE AMIGOS</p>
        <h1>Boas ideias<br>batem o <em>martelo.</em></h1>
        <p class="intro-copy">Crie uma sala, escolha o tema e descubra quem sabe dar o lance certo.</p>
        <div class="auction-stamp" aria-hidden="true">
          <span class="stamp-top">ABERTO</span>
          <span class="stamp-gavel">⌁</span>
          <span class="stamp-bottom">AO MELHOR LANCE</span>
        </div>
      </div>

      <section class="entry-panel" aria-labelledby="entry-title">
        <div class="panel-heading">
          <span class="step-tag">01 / ENTRADA</span>
          <h2 id="entry-title">Vamos jogar?</h2>
          <p>Escolha como quer entrar na rodada.</p>
        </div>
        <form id="entry-form">
          <label for="player-name">Seu nome</label>
          <input id="player-name" name="name" maxlength="24" autocomplete="nickname" placeholder="Como te chamam?" required>
          <div class="mode-switch" role="tablist" aria-label="Tipo de sala">
            <button type="button" class="mode-button is-active" data-mode="create" role="tab" aria-selected="true">Criar sala</button>
            <button type="button" class="mode-button" data-mode="join" role="tab" aria-selected="false">Entrar com código</button>
          </div>
          <div id="create-fields" class="mode-fields">
            <label for="starting-credits">Créditos iniciais por pessoa</label>
            <div class="input-with-unit"><input id="starting-credits" type="number" min="20" max="100000" step="1" value="1000" required><span>CR</span></div>
            <p class="field-hint">O anfitrião define o saldo igual para todo mundo.</p>
          </div>
          <div id="join-fields" class="mode-fields" hidden>
            <label for="room-code">Código da sala</label>
            <input id="room-code" maxlength="6" placeholder="EX.: K7M4Q" autocapitalize="characters" autocomplete="off">
          </div>
          <button class="button button-dark button-full" type="submit"><span id="entry-submit-label">Criar sala</span><span aria-hidden="true">↗</span></button>
          <p class="privacy-note">Sua sala fica disponível na rede local deste computador.</p>
        </form>
      </section>
    </section>

    <section id="room-view" class="room-view" hidden>
      <div class="room-header">
        <div>
          <p class="eyebrow" id="phase-label">LOBBY / PREPARAÇÃO</p>
          <h1 id="room-title">Sua sala</h1>
          <p class="room-subtitle" id="room-subtitle"></p>
        </div>
        <div class="room-code-block"><span>CÓDIGO DA SALA</span><button id="copy-code" class="room-code" type="button" title="Compartilhar sala"></button></div>
      </div>
      <div id="room-content" class="room-content"></div>
    </section>
    <dialog id="share-dialog" class="share-dialog" aria-labelledby="share-title">
      <div class="share-dialog-header">
        <div><span class="step-tag">CONVITE DA SALA</span><h2 id="share-title">Chame o grupo</h2></div>
        <button id="close-share" class="icon-button" type="button" aria-label="Fechar convite" title="Fechar">×</button>
      </div>
      <p class="share-copy">Compartilhe o código ou envie o link para entrar na sala.</p>
      <div class="share-code-row"><strong id="share-code"></strong><button id="copy-room-code" class="button button-outline" type="button">Copiar código</button></div>
      <div class="share-actions">
        <a id="share-whatsapp" class="button button-dark" target="_blank" rel="noopener noreferrer">WhatsApp <span aria-hidden="true">↗</span></a>
        <button id="share-link" class="button button-outline" type="button">Compartilhar link <span aria-hidden="true">↗</span></button>
      </div>
      <div class="share-qr-block">
        <img id="share-qr" alt="QR code para entrar nesta sala" width="176" height="176">
        <p>Aponte a câmera para entrar</p>
      </div>
    </dialog>
    <div id="toast" class="toast" role="status" aria-live="polite"></div>
  </main>
  <footer class="site-footer"><span>EU VOU FAZER UM LEILÃO</span><span>JOGO LOCAL · FEITO PARA JOGAR JUNTO</span></footer>
</body>
</html>