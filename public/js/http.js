// Every page's calls to its own API go in the page's language and with the session: the CSRF token (Laravel's XSRF-TOKEN cookie) on changes, and a
// signed-out session goes back to the sign-in page. Loaded before the pages' scripts (partials/theme-head).
(() => {
  const send = window.fetch.bind(window);
  const token = () => decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/) || [])[1] || '');
  window.fetch = async (input, init = {}) => {
    const url = new URL(typeof input === 'string' ? input : input.url, location.href);
    if (url.origin !== location.origin) return send(input, init);
    const headers = new Headers(init.headers || (typeof input === 'string' ? undefined : input.headers));
    const method = (init.method || 'GET').toUpperCase();
    if (!['GET', 'HEAD'].includes(method) && token()) headers.set('X-XSRF-TOKEN', token());
    headers.set('X-Requested-With', 'XMLHttpRequest');
    if (!headers.has('Accept-Language')) headers.set('Accept-Language', document.documentElement.lang);   // messages in the page's language
    const res = await send(input, { ...init, headers, credentials: 'same-origin' });
    if (res.status === 401) location.href = '/login';
    return res;
  };
})();
