import { check } from 'k6';

export function checkStatus(response, name, expectedStatus = 200) {
    return check(response, {
        [`${name}: status ${expectedStatus}`]: (r) => r.status === expectedStatus,
        [`${name}: no 5xx response`]: (r) => r.status < 500,
    });
}

export function checkContent(response, name, pattern) {
    return check(response, {
        [`${name}: expected content`]: (r) => pattern.test(r.body || ''),
    });
}
