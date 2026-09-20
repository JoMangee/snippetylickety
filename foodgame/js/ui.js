export const DISPLAY_NAME = 'CJLBee';

const $ = (id) => document.getElementById(id);
function text(id, value) { const node = $(id); if (node) node.textContent = value ?? ''; }
function escape(value) { const node = document.createElement('span'); node.textContent = value ?? ''; return node.innerHTML; }
function date(value) { const parsed = new Date(value); return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString(); }
function list(id, entries, makeEntry) { const node = $(id); if (!node) return; node.replaceChildren(); if (!entries?.length) { const empty = document.createElement('p'); empty.className = 'empty'; empty.textContent = 'Nothing here yet.'; node.append(empty); return; } entries.forEach((entry) => node.insertAdjacentHTML('beforeend', makeEntry(entry))); }
function entryMarkup(entry, includePlayer = false) { const visiblePlayer = String(entry.player || '').toLowerCase() === 'cooper' ? DISPLAY_NAME : entry.player; const label = includePlayer ? `${visiblePlayer} ↗ ` : ''; return `<div class="entry"><strong>${escape(label)}${escape(entry.summary?.meal_name || entry.meal)}</strong><span class="xp">+${Number(entry.xp_gained || 0)} XP</span><small>${escape(date(entry.timestamp_utc))} ↗ rating ${escape(entry.rating)}/10${entry.new ? ' ↗ NEW FOOD' : ''}${entry.level_after ? ` ↗ level ${escape(entry.level_after)}` : ''}</small></div>`; }
export function setStatus(message, good = false) { const node = $('status'); if (!node) return; node.textContent = message; node.className = `status${good ? ' good' : ' bad'}`; }
export function setConnected(connected) { $('connection-dot')?.classList.toggle('online', connected); }
export function renderStats(data) { const stats = data?.stats || {}; text('level', stats.level ?? 1); text('total-xp', stats.total_xp ?? 0); text('streak', stats.streak ?? 0); text('tolerance', stats.spice_tolerance ?? 8); const progress = Number(stats.xp_progress ?? 0); const needed = Math.max(1, Number(stats.xp_needed ?? 10)); text('xp-copy', `${progress} / ${needed} XP until next level`); const fill = $('xp-fill'); if (fill) fill.style.width = `${Math.min(100, Math.max(0, progress / needed * 100))}%`; }
export function renderActiveBadge(label) { const badge = document.querySelector('.buff-panel .badge'); if (badge) badge.textContent = label; }
export function renderBuff(data) {
    const card = $('buff-card');
    if (!card) return;

    const effects = Array.isArray(data?.effects) ? data.effects : [];
    const inventory = Array.isArray(data?.inventory) ? data.inventory : [];

    if (!effects.length && !inventory.length) {
        card.innerHTML = '<h3>READY TO COOK</h3><p>No active effects or keyword items yet.</p>';
        return;
    }

    const effectMarkup = effects.length
        ? `<h3>🍜 ACTIVE EFFECTS</h3><div>${effects.map((effect) => {
            const value = effect.unit === 'multiplier'
                ? `${effect.value}x`
                : effect.unit === 'percent'
                    ? `${effect.value}%`
                    : effect.unit === 'item'
                        ? ''
                        : `+${effect.value}`;
            const remaining = effect.remaining_seconds === null
                ? 'persistent'
                : `${Math.max(1, Math.ceil(Number(effect.remaining_seconds) / 60))} min remaining`;
            return `<span><strong>${escape(effect.keyword)}</strong>${value ? `: ${escape(value)}` : ''} <small>${escape(remaining)}</small></span>`;
        }).join(' ')}</div>`
        : '<h3>NO ACTIVE EFFECTS</h3>';

    const inventoryMarkup = inventory.length
        ? `<h3>🎒 KEYWORD INVENTORY</h3><div>${inventory.map((keyword) => `<span>${escape(keyword)}</span>`).join(' ')}</div>`
        : '<p>No keyword items carried.</p>';

    card.innerHTML = effectMarkup + inventoryMarkup;
}

export function renderHistory(entries) { list('history', entries.slice().reverse(), (entry) => entryMarkup(entry)); }
export function renderFeed(entries) { list('feed', entries.slice().reverse(), (entry) => entryMarkup(entry, true)); }
export function renderActivity(entries) { list('activity', entries.slice().reverse(), (entry) => entryMarkup(entry, true)); }
export function setPlayerValue(value) { $('player').value = value || DISPLAY_NAME; }
export function playerValue() { return $('player')?.value.trim() || DISPLAY_NAME; }
export function apiKeyValue() { return $('api-key')?.value.trim() || ''; }
export function setApiKeyValue(value) { if ($('api-key')) $('api-key').value = value; }
export function formValues() { return { player: playerValue(), meal: $('meal').value, rating: $('rating').value, new: $('new-food').checked ? '1' : '0' }; }
export function setBusy(busy) { const button = $('meal-form button[type="submit"]'); if (button) { button.disabled = busy; button.textContent = busy ? '🍳 ROLLING…' : '🍽 LOG FOR ME'; } }
export function on(id, event, handler) { $(id)?.addEventListener(event, handler); }
export function populateMeals(meals) {
    const node = $('meal');
    if (!node) return;
    const selected = node.value;
    node.replaceChildren();
    meals.sort(function (a, b) {
        return a.name.localeCompare(b.name);
    });
    meals.forEach(function (meal) {
        const option = document.createElement('option');
        option.value = meal.id;
        option.textContent = meal.name + ' - spice ' + meal.spice + ' - XP ' + meal.xp_base;
        node.append(option);
    });
    if (Array.from(node.options).some(function (option) { return option.value === selected; })) {
        node.value = selected;
    }
}

export function toggleAddMeal(visible) {
  const panel = $('add-meal-panel');
  if (!panel) return;
  panel.hidden = !visible;
  panel.style.display = visible ? '' : 'none';
}


text('player-brand', `${DISPLAY_NAME}'S BRIGHT BITE LAB`);
document.title = `${DISPLAY_NAME}'s Foodgame`;
const playerInput = $('player');
if (playerInput) playerInput.value = DISPLAY_NAME;
