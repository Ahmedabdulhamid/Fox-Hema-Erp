import http from 'k6/http';

export function get(url, environment, tags = {}) {
    if (typeof tags.endpoint !== 'string' || tags.endpoint.length === 0) {
        throw new Error('Every HTTP request must provide a stable endpoint tag.');
    }

    return http.get(url, {
        timeout: environment.timeout,
        redirects: 0,
        tags,
    });
}

export function responseClassification(response) {
    if (response.status === 401) {
        return 'unauthenticated';
    }
    if (response.status === 403) {
        return 'forbidden';
    }
    if (response.status >= 300 && response.status < 400) {
        return 'redirect';
    }
    if (response.status >= 500) {
        return 'server_error';
    }
    if (response.status >= 200 && response.status < 300) {
        return 'success';
    }
    if (response.status >= 400 && response.status < 500) {
        return 'client_error';
    }
    return 'unexpected_response';
}
