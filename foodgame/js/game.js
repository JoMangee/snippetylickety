import { get, post } from './api.js';
import { apiKeyValue, formValues, on, playerValue as uiPlayerValue, populateMeals, renderActivity, renderBuff, renderFeed, renderHistory, renderStats, setApiKeyValue, setBusy, setConnected, setPlayerValue, setStatus } from './ui.js';

const KEY_NAME = 'foodgameApiKey';
const PLAYER_NAME = 'foodgamePlayer';
function savedKey() { return localStorage.getItem(KEY_NAME) || ''; }
function storeKey() { const key = apiKeyValue(); if (key) localStorage.setItem(KEY_NAME, key); return key; }
function savedPlayer() { return localStorage.getItem(PLAYER_NAME) || 'CJLBee'; }
function playerValue() { return uiPlayerValue().trim() || savedPlayer(); }
function storePlayer() { const player = playerValue() || 'CJLBee'; localStorage.setItem(PLAYER_NAME, player); return player; }
function currentPlayer() { return playerValue(); }

export const MEALS = { 'noodle-masterpiece': { name: 'Noodle Masterpiece', spice: 8 }, 'scrap-mechanic-snack': { name: 'Scrap Mechanic Snack', spice: 3 }, boss: { name: 'Boss Meal', spice: 6 }, 'fruit-fuel': { name: 'Fruit Fuel', spice: 1 } };
function fallbackMealList() {
  return Object.entries(MEALS).map(function (entry) {
    const id = entry[0];
    const meal = entry[1];
    return { id: id, name: meal.name, spice: meal.spice, xp_base: 10 };
  });
}
function renderMealList(meals) {
  populateMeals(Array.isArray(meals) && meals.length ? meals : fallbackMealList());
}
async function loadMeals() {
  const key = savedKey() || apiKeyValue();
  if (!key) {
    renderMealList([]);
    return;
  }
  try {
    const result = await get(key, { action: 'meals' });
    renderMealList(result.meals);
  } catch (error) {
    renderMealList([]);
    setStatus('Could not load meals; using offline meals.');
  }
}

async function addMeal(event) {
  event.preventDefault();
  const form = document.getElementById('add-meal-form');
  const submit = form?.querySelector('button[type="submit"]');
  const name = document.getElementById('add-meal-name')?.value.trim() || '';
  const spice = document.getElementById('add-meal-spice')?.value.trim() || '';
  const xpBase = document.getElementById('add-meal-xp-base')?.value.trim() || '';
  const id = name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 64);
if (!name || name.length > 120 || !id || !/^(?:0|[1-9][0-9])$/.test(spice) || Number(spice) > 10 || (xpBase !== '' && !/^(?:0|[1-9][0-9])$/.test(xpBase))) {
    setStatus('Enter a valid meal name, spice, and optional XP base.');
    return;
  }
  const key = savedKey() || apiKeyValue();
  if (!key) {
    setStatus('Add the API key in Settings first.');
    return;
  }
if (submit) submit.disabled = true;
  try {
    const params = { action: 'meals_add', id: id, name: name, spice: spice };
    if (xpBase !== '') params.xp_base = xpBase;
    const result = await post(key, params);
    renderMealList(result.meals);
    document.getElementById('meal').value = id;
    form.reset();
    setStatus('Meal added.', true);
  } catch (error) {
    setStatus(error.message);
  } finally {
    if (submit) submit.disabled = false;
  }
}


let lastActivityId = null;

export function levelForXp(totalXp) { let level = 1; const xp = Math.max(0, Number(totalXp) || 0); while (xp >= level * level * 10) level += 1; return level; }
export function xpForMeal({ isNew = false, rating = 0, meal = '' } = {}) { return 10 + (isNew ? 15 : 0) + (Number(rating) >= 7 ? 5 : 0) + (meal === 'boss' ? 20 : 0); }
export function spiceOutcome(tolerance, spice, random = Math.random) { const tol = Math.max(0, Number(tolerance) || 0); const heat = Number(spice) || 0; if (tol >= heat) return { result: 'easy', chance: 100, heatDamage: 0, duration: 0, debuffed: false }; if (tol >= 4) return { result: 'hot', chance: Math.round(50 + (tol - 4) * 12.5), heatDamage: 0, duration: 0, debuffed: false }; if (tol >= 1) return { result: 'low-tolerance', chance: 30, heatDamage: Math.floor(random() * 6), duration: 60, debuffed: false }; return { result: 'debuffed', chance: 0, heatDamage: 0, duration: 0, debuffed: true }; }
export function noodleBuffs() { return ['highly energized (2x base energy)', 'heated engine (+70% speed, +20% acceleration)', 'energy consumption 2x', 'duration 15 minutes']; }
async function loadAll() { const key = savedKey() || apiKeyValue(); if (!key) throw new Error('Add the API key in Settings first.'); const [stats, feed, activity] = await Promise.all([get(key, { action: 'stats', player: currentPlayer() }), get(key, { action: 'feed' }), get(key, { action: 'activity' })]); renderStats(stats); renderHistory(activity.entries || []); renderFeed(feed.entries || []); renderActivity(activity.entries || []); lastActivityId = activity.next_since || lastActivityId; setConnected(true); setStatus('Stats, history, and feed loaded.', true); }
async function pollActivity() { const key = savedKey() || apiKeyValue(); if (!key) throw new Error('Add the API key in Settings first.'); const params = { action: 'activity' }; if (lastActivityId !== null) params.since = String(lastActivityId); const result = await get(key, params); renderActivity(result.activity || []); lastActivityId = result.next_since || lastActivityId; setStatus(result.activity?.length ? `Found ${result.activity.length} newer entry${result.activity.length === 1 ? 'y' : 'yes'}.` : 'No newer activity ...', true); }
async function saveKey() { const key = storeKey(); if (!key) throw new Error('Paste an API key first.'); storePlayer(); await loadMeals(); await loadAll(); }
async function logMeal(event) { event.preventDefault(); setBusy(true); try { const key = savedKey() || apiKeyValue(); if (!key) throw new Error('Add the API key in Settings first.'); storePlayer(); const result = await get(key, { action: 'log', ...formValues(), player: playerValue() }); await loadAll(); setConnected(true); const level = result.stats?.level; setStatus(level === undefined ? 'Logged!' : 'Logged! +' + result.xp_gained + ' XP level ' + level + '.', true); } catch (error) { setConnected(false); setStatus(error.message); } finally { setBusy(false); } }
function handle(action) { return () => action().catch((error) => { setConnected(false); setStatus(error.message); }); }
setApiKeyValue(savedKey());
setPlayerValue(savedPlayer());
on('save-key', 'click', handle(saveKey));
on('refresh', 'click', handle(async function () { await loadMeals(); await loadAll(); }));
on('poll-activity', 'click', handle(pollActivity));
on('meal-form', 'submit', logMeal);
on('add-meal-form', 'submit', addMeal);
on('show-add-meal', 'change', function (event) { toggleAddMeal(event.target.checked); });
handle(async function () { await loadMeals(); if (savedKey()) await loadAll(); })();

