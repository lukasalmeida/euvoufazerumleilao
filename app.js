const homeView = document.querySelector('#home-view');
const roomView = document.querySelector('#room-view');
const roomContent = document.querySelector('#room-content');
const toast = document.querySelector('#toast');
const storageKey = 'leilao-de-bolso-player';
let identity = JSON.parse(localStorage.getItem(storageKey) || 'null');
let currentState = null;
let mode = 'create';
let toastTimer;
let pollTimer;
let itemsSubmitting = false;

async function api(action, payload = {}) {
  const response = await fetch('api.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action, ...identity, ...payload }),
  });
  const result = await response.json();
  if (!response.ok || !result.ok) {
    const error = new Error(result.error || 'Não foi possível concluir a ação.');
    error.duplicateItems = result.duplicateItems || [];
    throw error;
  }
  return result;
}

function notify(message) {
  toast.textContent = message;
  toast.classList.add('is-visible');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => toast.classList.remove('is-visible'), 3200);
}

function escapeHtml(value) {
  return String(value).replace(/[&<>"']/g, (character) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  })[character]);
}

function normalizeItemName(value) {
  return value.trim().toLocaleLowerCase('pt-BR');
}

function duplicateItemNames(names) {
  const existingNames = new Set((currentState?.items || [])
    .filter((item) => item.owner_name !== currentState?.me?.name)
    .map((item) => normalizeItemName(item.name)));
  const seenNames = new Set();
  const duplicates = new Set();
  for (const name of names) {
    const normalizedName = normalizeItemName(name);
    if (existingNames.has(normalizedName) || seenNames.has(normalizedName)) duplicates.add(name);
    seenNames.add(normalizedName);
  }
  return [...duplicates];
}

function markDuplicateItemFields(names, duplicateNames, message) {
  const duplicates = new Set(duplicateNames.map(normalizeItemName));
  for (const [index, input] of [...roomContent.querySelectorAll('[data-item-name]')].entries()) {
    input.value = names[index] || '';
    const isDuplicate = duplicates.has(normalizeItemName(input.value));
    const errorField = input.nextElementSibling;
    input.setAttribute('aria-invalid', String(isDuplicate));
    errorField.textContent = isDuplicate ? 'Este item já foi cadastrado. Informe outro.' : '';
    errorField.hidden = !isDuplicate;
  }
  notify(message);
}

function setIdentity(roomCode, playerId) {
  identity = { roomCode, playerId };
  localStorage.setItem(storageKey, JSON.stringify(identity));
}

function showHome() {
  clearInterval(pollTimer);
  homeView.hidden = false;
  roomView.hidden = true;
  identity = null;
  localStorage.removeItem(storageKey);
}

function phaseName(phase) {
  return ({ lobby: 'LOBBY / PREPARAÇÃO', items: 'LOBBY / SEUS ITENS', auction: 'LEILÃO / AO VIVO', finished: 'RODADA / ENCERRADA' })[phase];
}

function render(state) {
  currentState = state;
  homeView.hidden = true;
  roomView.hidden = false;
  document.querySelector('#phase-label').textContent = phaseName(state.room.phase);
  document.querySelector('#room-title').textContent = state.room.theme || 'Sala de leilão';
  document.querySelector('#room-subtitle').textContent = `${state.players.length} ${state.players.length === 1 ? 'jogador' : 'jogadores'} · ${state.room.startingCredits.toLocaleString('pt-BR')} créditos iniciais`;
  document.querySelector('#copy-code').textContent = state.room.code;
  roomContent.innerHTML = renderPhase(state);
}

function renderPlayers(state) {
  return `<div class="player-list">${state.players.map((player, index) => `
    <div class="player-row">
      <span class="player-index">${String(index + 1).padStart(2, '0')}</span>
      <span class="player-avatar">${escapeHtml(player.name.slice(0, 1).toUpperCase())}</span>
      <span class="player-name">${escapeHtml(player.name)}${player.is_me ? '<small>VOCÊ</small>' : ''}${player.is_host ? '<small>ANFITRIÃO</small>' : ''}</span>
      <span class="player-ready">${player.item_count}/5 itens</span>
    </div>`).join('')}</div>`;
}

function renderLobby(state) {
  const host = state.me.isHost;
  return `<div class="lobby-grid">
    <section class="content-section">
      <div class="section-title"><div><span class="step-tag">01 / SALA</span><h2>Quem chegou</h2></div><span class="count-pill">${state.players.length} ${state.players.length === 1 ? 'PESSOA' : 'PESSOAS'}</span></div>
      ${renderPlayers(state)}
      <div class="invite-note"><span class="invite-icon">↗</span><p>Convide o grupo com o código <strong>${state.room.code}</strong>. Todos precisam estar na mesma rede.</p></div>
    </section>
    <section class="content-section theme-section">
      <span class="step-tag">02 / TEMA DO LEILÃO</span><h2>O que vai a pregão?</h2>
      ${host ? `<form id="theme-form" class="inline-form"><label class="sr-only" for="theme-input">Tema do leilão</label><input id="theme-input" maxlength="60" value="${escapeHtml(state.room.theme)}" placeholder="Ex.: coisas que cabem numa mochila" required><button class="button button-outline" type="submit">Salvar tema</button></form>
        <label class="setting-toggle"><span><strong>Crédito para quem vende</strong><small>Desligado, os créditos do lance saem do saldo e não são repassados.</small></span><input id="seller-credit-toggle" type="checkbox" role="switch" ${state.room.sellerReceivesCredits ? 'checked' : ''}></label>
        <button id="open-items" class="button button-dark button-full" ${!state.room.theme ? 'disabled' : ''}>Abrir cadastro dos itens <span>→</span></button>` : `<div class="waiting-theme"><span class="waiting-dot"></span><p>${state.room.theme ? `Tema escolhido: <strong>${escapeHtml(state.room.theme)}</strong>` : 'Aguardando o anfitrião definir o tema.'}</p></div>`}
      <p class="section-footnote">Cada pessoa começa com <strong>${state.room.startingCredits.toLocaleString('pt-BR')} créditos</strong> e leva cinco itens para o leilão.</p>
    </section>
  </div>`;
}

function renderItems(state) {
  const existing = state.items.filter((item) => item.owner_name === state.me.name);
  const submitted = existing.length === 5;
  return `<div class="setup-grid">
    <section class="content-section">
      <div class="section-title"><div><span class="step-tag">02 / SUA VITRINE</span><h2>Escolha cinco itens</h2></div><span class="count-pill">${submitted ? '5/5 PRONTO' : '0/5 ITENS'}</span></div>
      <p class="section-copy">Tema: <strong>${escapeHtml(state.room.theme)}</strong>. Defina os cinco itens que irão a leilão para todos.</p>
      <form id="items-form" class="items-form">${Array.from({ length: 5 }, (_, index) => {
        const item = existing[index];
        return `<div class="item-input-row"><span class="item-number">${String(index + 1).padStart(2, '0')}</span><div class="item-field"><input name="item-name-${index}" data-item-name maxlength="60" placeholder="Nome do item" value="${escapeHtml(item?.name || '')}" required aria-invalid="false" ${submitted ? 'disabled' : ''}><small class="item-field-error" id="item-error-${index}" hidden></small></div></div>`;
      }).join('')}
        ${submitted ? '<div class="saved-state"><span>✓</span> Seus cinco itens estão na lista.</div>' : '<button class="button button-dark" type="submit">Salvar meus itens <span>→</span></button>'}
      </form>
    </section>
    <aside class="content-section roster-section"><span class="step-tag">PRONTIDÃO</span><h2>Todo mundo pronto?</h2>${renderPlayers(state)}
      ${state.me.isHost ? `<button id="start-auction" class="button button-dark button-full" ${state.players.some((player) => player.item_count !== 5) ? 'disabled' : ''}>Começar o leilão <span>→</span></button>` : '<p class="waiting-message"><span class="waiting-dot"></span> O leilão começa quando todos cadastrarem.</p>'}
      ${state.me.isHost && state.players.some((player) => player.item_count !== 5) ? '<p class="field-hint">O botão libera quando todos tiverem 5 itens.</p>' : ''}
    </aside>
  </div>`;
}

function renderAuction(state) {
  const item = state.currentItem;
  if (!item) return '<section class="content-section"><p>Preparando o próximo item...</p></section>';
  const minimum = Math.max(1, Number(item.current_bid) * (item.buyer_id ? 2 : 1));
  const seconds = Math.max(0, Number(item.deadline) - Math.floor(Date.now() / 1000));
  const canBid = state.me.credits >= minimum;
  return `<div class="auction-layout">
    <section class="auction-lot">
      <div class="lot-topline"><span class="step-tag">LOTE ${String(item.position + 1).padStart(2, '0')} / ${state.items.length}</span><span class="timer ${seconds <= 8 ? 'timer-urgent' : ''}" data-deadline="${item.deadline}">${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}</span></div>
      <p class="lot-category">${escapeHtml(state.room.theme)}</p><h2>${escapeHtml(item.name)}</h2><p class="lot-owner">De <strong>${escapeHtml(item.owner_name)}</strong></p>
      <div class="bid-display"><span>${item.buyer_name ? 'LANCE ATUAL' : 'SEM LANCES'}</span><strong>${Number(item.current_bid).toLocaleString('pt-BR')} <small>CR</small></strong><p>${item.buyer_name ? `na frente: ${escapeHtml(item.buyer_name)}` : 'o primeiro lance começa em 1 crédito'}</p></div>
      <div class="lot-progress"><span style="width:${Math.max(0, Math.min(100, (seconds / 25) * 100))}%"></span></div>
    </section>
    <aside class="bid-panel"><span class="step-tag">SUA CARTEIRA</span><div class="wallet-amount">${state.me.credits.toLocaleString('pt-BR')} <small>CR</small></div><p>Seu saldo disponível</p>
      <form id="bid-form" class="bid-form"><label for="bid-amount">Seu lance</label><div class="bid-input"><input id="bid-amount" type="number" min="${minimum}" max="${state.me.credits}" step="1" value="${minimum}" required ${!canBid ? 'disabled' : ''}><span>CR</span></div><p class="field-hint">Próximo lance: <strong>${minimum.toLocaleString('pt-BR')} CR</strong> ou mais</p><button class="button button-dark button-full" type="submit" ${!canBid ? 'disabled' : ''}>Dar lance <span>↗</span></button>${!canBid ? '<p class="field-hint">Saldo insuficiente para cobrir o próximo lance.</p>' : ''}</form>
      <div class="auction-players">${state.players.map((player) => `<div><span>${escapeHtml(player.name)}${player.is_me ? ' (você)' : ''}</span><strong>${player.credits.toLocaleString('pt-BR')} CR</strong></div>`).join('')}</div>
    </aside>
  </div>${state.me.isHost ? '<button id="end-auction" class="button button-outline">Encerrar leilão</button>' : ''}`;
}

function renderFinished(state) {
  const sold = state.items.filter((item) => item.status === 'sold').length;
  const ranking = [...state.players].sort((a, b) => b.credits - a.credits);
  return `<section class="results-section"><div class="results-heading"><span class="step-tag">RODADA FINALIZADA</span><h2>O martelo bateu.</h2><p>${sold} ${sold === 1 ? 'item vendido' : 'itens vendidos'} de ${state.items.length} lotes.</p></div>
    <div class="results-grid"><div class="content-section"><span class="step-tag">PLACAR FINAL</span><div class="ranking-list">${ranking.map((player, index) => `<div class="ranking-row"><span class="rank-number">${String(index + 1).padStart(2, '0')}</span><span class="rank-name">${escapeHtml(player.name)}${player.is_me ? ' · VOCÊ' : ''}</span><strong>${player.credits.toLocaleString('pt-BR')} <small>CR</small></strong></div>`).join('')}</div></div>
      <div class="content-section"><span class="step-tag">ITENS ARREMATADOS</span><div class="inventory-list">${state.items.filter((item) => item.status === 'sold').map((item) => `<div class="inventory-row"><span>${escapeHtml(item.name)}<small>${escapeHtml(item.buyer_name)} levou de ${escapeHtml(item.owner_name)}</small></span><strong>${Number(item.current_bid).toLocaleString('pt-BR')} CR</strong></div>`).join('') || '<p class="field-hint">Nenhum item foi vendido nesta rodada.</p>'}</div></div></div>
    ${state.me.isHost ? '<button id="restart-session" class="button button-dark">Reiniciar sessão <span>↻</span></button>' : ''}
    <button id="leave-room" class="button button-outline">Voltar ao início</button>
  </section>`;
}

function renderPhase(state) {
  if (state.room.phase === 'lobby') return renderLobby(state);
  if (state.room.phase === 'items') return renderItems(state);
  if (state.room.phase === 'auction') return renderAuction(state);
  return renderFinished(state);
}

async function refresh(force = false) {
  if (!identity) return;
  try {
    const result = await api('state');
    const hasUnsavedItems = roomContent.querySelector('#items-form')
      && [...roomContent.querySelectorAll('[data-item-name]')].some((input) => input.value.trim() !== '');
    if (!force && (itemsSubmitting || hasUnsavedItems)) return;
    if (!force && roomContent.contains(document.activeElement) && document.activeElement.matches('input, textarea, select')) return;
    render(result.state);
  } catch (error) {
    notify(error.message);
    showHome();
  }
}

document.querySelectorAll('.mode-button').forEach((button) => button.addEventListener('click', () => {
  mode = button.dataset.mode;
  document.querySelectorAll('.mode-button').forEach((entry) => {
    const active = entry === button;
    entry.classList.toggle('is-active', active);
    entry.setAttribute('aria-selected', String(active));
  });
  document.querySelector('#create-fields').hidden = mode !== 'create';
  document.querySelector('#join-fields').hidden = mode !== 'join';
  document.querySelector('#entry-submit-label').textContent = mode === 'create' ? 'Criar sala' : 'Entrar na sala';
}));

document.querySelector('#entry-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const name = document.querySelector('#player-name').value.trim();
  try {
    const result = mode === 'create'
      ? await api('create', { name, credits: Number(document.querySelector('#starting-credits').value) })
      : await api('join', { name, roomCode: document.querySelector('#room-code').value.trim() });
    setIdentity(result.roomCode, result.playerId);
    if (!pollTimer) pollTimer = setInterval(() => refresh(), 1500);
    await refresh(true);
  } catch (error) {
    notify(error.message);
  }
});

document.querySelector('#copy-code').addEventListener('click', async () => {
  try {
    await navigator.clipboard.writeText(currentState.room.code);
    notify('Código copiado.');
  } catch {
    notify(`Código da sala: ${currentState.room.code}`);
  }
});

roomContent.addEventListener('submit', async (event) => {
  event.preventDefault();
  const savingItems = event.target.id === 'items-form';
  const submittedItemNames = savingItems
    ? Array.from({ length: 5 }, (_, index) => event.target.elements[`item-name-${index}`].value.trim())
    : [];
  if (savingItems) itemsSubmitting = true;
  try {
    if (event.target.id === 'theme-form') {
      await api('theme', { theme: document.querySelector('#theme-input').value.trim() });
      notify('Tema atualizado.');
    }
    if (event.target.id === 'items-form') {
      const duplicates = duplicateItemNames(submittedItemNames);
      if (duplicates.length) {
        markDuplicateItemFields(submittedItemNames, duplicates, 'Há itens repetidos ou já cadastrados nesta sala. Escolha outros nomes.');
        itemsSubmitting = false;
        return;
      }
      await api('save_items', { items: submittedItemNames.map((name) => ({ name })) });
      notify('Seus cinco itens foram salvos.');
    }
    if (event.target.id === 'bid-form') {
      await api('bid', { amount: Number(document.querySelector('#bid-amount').value) });
      notify('Lance registrado.');
    }
    if (savingItems) itemsSubmitting = false;
    await refresh(true);
  } catch (error) {
    if (savingItems && (error.duplicateItems?.length || error.message.includes('itens repetidos'))) {
      const duplicates = error.duplicateItems?.length ? error.duplicateItems : duplicateItemNames(submittedItemNames);
      markDuplicateItemFields(submittedItemNames, duplicates, error.message);
      itemsSubmitting = false;
      return;
    }
    if (savingItems) itemsSubmitting = false;
    notify(error.message);
    await refresh(true);
  }
});

roomContent.addEventListener('input', (event) => {
  if (!event.target.matches('[data-item-name]')) return;
  event.target.setAttribute('aria-invalid', 'false');
  event.target.nextElementSibling.hidden = true;
});

roomContent.addEventListener('change', async (event) => {
  if (event.target.id !== 'seller-credit-toggle') return;
  try {
    await api('seller_credit_mode', { enabled: event.target.checked });
    notify(event.target.checked ? 'Quem vende receberá os créditos.' : 'Os créditos não serão repassados a quem vende.');
    await refresh(true);
  } catch (error) {
    notify(error.message);
    await refresh(true);
  }
});

roomContent.addEventListener('click', async (event) => {
  const button = event.target.closest('button');
  if (!button) return;
  if (button.id === 'leave-room') return showHome();
  if (!['open-items', 'start-auction', 'end-auction', 'restart-session'].includes(button.id)) return;
  try {
    if (button.id === 'open-items') await api('open_items');
    if (button.id === 'start-auction') await api('start_auction');
    if (button.id === 'end-auction') await api('end_auction');
    if (button.id === 'restart-session') await api('restart_session');
    await refresh(true);
  } catch (error) {
    notify(error.message);
    await refresh(true);
  }
});

document.querySelector('#room-code').addEventListener('input', (event) => {
  event.target.value = event.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
});

if (identity?.roomCode && identity?.playerId) {
  refresh();
  pollTimer = setInterval(() => refresh(), 1500);
} else {
  showHome();
}