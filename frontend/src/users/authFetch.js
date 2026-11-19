/**
 * Same-origin API calls for team services. The browser never receives a
 * service secret or creates its own identity token.
 *
 * The central User Access session cookie is sent to Vite's service proxies.
 * State-changing requests also forward the Laravel CSRF token for validation
 * at the central auth service.
 */
export async function fetchWithAuth(url, options = {}) {
  const method = (options.method || 'GET').toUpperCase();
  const headers = new Headers(options.headers || {});
  headers.set('Accept', 'application/json');

  if (!['GET', 'HEAD'].includes(method)) {
    const csrfResponse = await fetch('/laravel/csrf-token', {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    if (!csrfResponse.ok) throw new Error('Could not obtain session token.');
    const data = await csrfResponse.json();
    if (!data.csrf_token) throw new Error('Missing session token.');
    headers.set('X-CSRF-TOKEN', data.csrf_token);
  }

  return fetch(url, { ...options, method, headers, credentials: 'same-origin' });
}
