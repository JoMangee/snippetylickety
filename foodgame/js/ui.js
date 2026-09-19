const $ = (id) => document.getElementById(id);
function text(id, value) { const node = $(id); if (node) node.textContent = value ?? ''; }
function escape(value) { const node = document.createElement('span'); node.textContent = value ?? ''; return node.innerHTML; }
function date(value) { const parsed = new Date(value); return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString(); }
function list(id, entries, makeEntry) { const node = $(id); if (!node) return; node.replaceChildren(); if (!entries?.length) { const empty = document.createElement('p'); empty.className = 'empty'; empty.textContent = 'Nothing here yet.'; node.append(empty); return; } entries.forEach((entry) => node.insertAdjacentHTML('beforeend', makeEntry(entry))); }
function entryMarkup(entry, includePlayer = false) { const label = includePlayer ? `${entry.player} · ` : ''; return `<div class="entry"><strong>${escape(label)}${escape(entry.summary?.meal_name || entry.meal)} <span class="xp">+${Number(entry.xp_gained || 0)} XP</span></strong><small>${escape(date(entry.timestamp_utc))} · rating ${escape(entry.rating)}/10${entry.new ? ' · NEW FOOD' : ''}${entry.level_after ? ` · level ${escape(entry.level_after)}` : ''}</small></div>`; }
export function setStatus(message, good = false) { const node = $('status'); if (!node) return; node.textContent = message; node.className = `status${good ? ' good' : ' bad'}`; }
export function setConnected(connected) { $('connection-dot')?.classList.toggle('online', connected); }
export function renderStats(data) { const stats = data?.stats || {}; text('level', stats.level ?? 1); text('total-xp', stats.total_xp ?? 0); text('streak', stats.streak ?? 0); text('tolerance', stats.spice_tolerance ?? 8); const progress = Number(stats.xp_progress ?? 0); const needed = Math.max(1, Number(stats.xp_needed ?? 10)); text('xp-copy', `${progress} / ${needed} XP until next level`); const fill = $('xp-fill'); if (fill) fill.style.width = `${Math.min(100, Math.max(0, progress / needed * 100))}%`; }
export function renderBuff(entry) { const card = $('buff-card'); if (!card) return; const summary = entry?.summary; if (!summary) { card.innerHTML = '<p>Ready to cook! Noodle Masterpiece is spice 8 and lasts 15 minutes when the roll succeeds.</p>'; return; } const buffs = summary.buffs_applied || []; card.innerHTML = buffs.length ? `<h3>🎉 BUFFS ONLINE</h3><p>Exact canon roll succeeded: ${escape(summary.spice_result)}.</p><div class="pill-row">${buffs.map((buff) => `<span class="pill">${escape(buff)}</span>`).join('')}</div>` : `<h3>READY TO COOK</h3><p>Spice result: ${escape(summary.spice_result || 'debuffed')}. Buff chance: ${escape(summary.buff_chance_percent ?? 0)}%.</p>`; }
export function renderHistory(entries) { list('history', entries, (entry) => entryMarkup(entry)); }
export function renderFeed(entries) { list('feed', entries, (entry) => entryMarkup(entry, true)); }
export function renderActivity(entries) { list('activity', entries, (entry) => entryMarkup(entry, true)); }
export function playerValue() { return $('player')?.value.trim() || 'Cooper'; }
export function apiKeyValue() { return $('api-key')?.value.trim() || ''; }
export function setApiKeyValue(value) { if ($('api-key')) $('api-key').value = value; }
export function formValues() { return { player: playerValue(), meal: $('meal').value, rating: $('rating').value, new: $('new-food').checked ? '1' : '0' }; }
export function setBusy(busy) { const button = $('meal-form button[type="submit"]'); if (button) { button.disabled = busy; button.textContent = busy ? '🍜 ROLLING…' : '🍜 LOG FOR ME'; } }
export function on(id, event, handler) { $(id)?.addEventListener(event, handler); }
