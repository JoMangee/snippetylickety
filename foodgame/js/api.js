const API_BASE_URL = 'https://bbb.mesh.net.nz/foodgame/api.php';

/** Small GET-only client. The key is deliberately added to every request. */
export async function get(key, params = {}) {
  const token = String(key || '').trim();
  if (!token) throw new Error('Add the API key in Settings first.');
  const query = new URLSearchParams({ ...params, key: token });
  const response = await fetch(`${API_BASE_URL}?${query.toString()}`, { method: 'GET',
    headers: { Accept: 'application/json' },
    credentials: 'same-origin' });
  let body;
  try { body = await response.json(); } catch { throw new Error('The API did not return JSON.'); }
  if (!response.ok || body?.ok !== true) {
    const error = new Error(body?.error === 'unauthorized' ? 'Unauthorized: check the API key.' : (body?.error || `API error ${response.status}`));
    error.code = body?.error || `http_${response.status}`; error.status = response.status; throw error;
  }
  return body;
}
