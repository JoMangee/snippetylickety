import { get } from './api.js';
import { apiKeyValue, formValues, on, renderActivity, renderBuff, renderFeed, renderHistory, renderStats, setApiKeyValue, setBusy, setConnected, setStatus } from './ui.js';

const KEY_NAME = 'foodgameApiKey';
const API_PLAYER_ID = 'CJLBee';
export const MEALS = { 'noodle-masterpiece': { name: 'Noodle Masterpiece', spice: 8 }, 'scrap-mechanic-snack': { name: 'Scrap Mechanic Snack', spice: 3 }, boss: { name: 'Boss Meal', spice: 6 }, 'fruit-fuel': { name: 'Fruit Fuel', spice: 1 } };
let lastActivityId = null;

export function levelForXp(totalXp) { let level = 1; const xp = Math.max(0, Number(totalXp) || 0); while (xp >= level * level * 10) level += 1; return level; }
export function xpForMeal({ isNew = false, rating = 0, meal = '' } = {}) { return 10 + (isNew ? 15 : 0) + (Number(rating) >= 7 ? 5 : 0) + (meal === 'boss' ? 20 : 0); }
export function spiceOutcome(tolerance, spice, random = Math.random) { const tol = Math.max(0, Number(tolerance) || 0); const heat = Number(spice) || 0; if (tol >= heat) return { result: 'easy', chance: 100, heatDamage: 0, duration: 0, debuffed: false }; if (tol >= 4) return { result: 'hot', chance: Math.round(50 + (tol - 4) * 12.5), heatDamage: 0, duration: 0, debuffed: false }; if (tol >= 1) return { result: 'low-tolerance', chance: 30, heatDamage: Math.floor(random() * 6), duration: 60, debuffed: false }; return { result: 'debuffed', chance: 0, heatDamage: 0, duration: 0, debuffed: true }; }
export function noodleBuffs() { return ['highly energized (2x base energy)', 'heated engine (+70% speed, +20% acceleration)', 'energy consumption 2x', 'duration 15 minutes']; }
function savedKey() { return localStorage.getItem(KEY_NAME) || ''; }
function storeKey() { const key = apiKeyValue(); if (key) localStorage.setItem(KEY_NAME, key); return key; }
function currentPlayer() { return API_PLAYER_ID; }
async function loadAll() { const key = savedKey() || apiKeyValue(); if (!key) throw new Error('Add the API key in Settings first.'); const [stats, feed, activity] = await Promise.all([get(key, { action: 'stats', player: currentPlayer() }), get(key, { action: 'feed' }), get(key, { action: 'activity' })]); renderStats(stats); renderHistory(stats.entries || []); renderFeed(feed.feed || []); renderActivity(activity.activity || []); lastActivityId = activity.next_since || lastActivityId; setConnected(true); setStatus('Stats, history, and feed loaded.', true); }
async function pollActivity() { const key = savedKey() || apiKeyValue(); if (!key) throw new Error('Add the API key in Settings first.'); const params = { action: 'activity' }; if (lastActivityId !== null) params.since = String(lastActivityId); const result = await get(key, params); renderActivity(result.activity || []); lastActivityId = result.next_since || lastActivityId; setStatus(result.activity?.length ? `Found ${result.activity.length} newer entry${result.activity.length === 1 ? 'y' : 'yes'}.` : 'No newer activity ...', true); }
async function saveKey() { const key = storeKey(); if (!key) throw new Error('Paste an API key first.'); await loadAll(); }
async function logMeal(event) { event.preventDefault(); setBusy(true); try { const key = savedKey() || apiKeyValue(); if (!key) throw new Error('Add the API key in Settings first.'); const result = await get(key, { action: 'log', ...formValues(), player: API_PLAYER_ID }); renderStats(result); renderHistory(result.entries || []); renderBuff(result.entry); setConnected(true); lastActivityId = result.entry?.id || lastActivityId; setStatus(`Logged! +${result.xp_gained} XP ↗ level ${result.stats.level}.`, true); } catch (error) { setConnected(false); setStatus(error.message); } finally { setBusy(false); } }
function handle(action) { return () => action().catch((error) => { setConnected(false); setStatus(error.message); }); }
setApiKeyValue(savedKey());
on('save-key', 'click', handle(saveKey));
on('refresh', 'click', handle(loadAll));
on('poll-activity', 'click', handle(pollActivity));
on('meal-form', 'submit', logMeal);
if (savedKey()) handle(loadAll)();
