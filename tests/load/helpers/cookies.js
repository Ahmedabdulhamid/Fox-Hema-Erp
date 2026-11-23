import http from 'k6/http';

export function getVuCookieJar() {
    return http.cookieJar();
}

export function clearCookies(jar, baseUrl) {
    if (typeof jar.clear === 'function') {
        jar.clear(baseUrl);
    }
}
