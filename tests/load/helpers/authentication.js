export function authenticate(environment) {
    return {
        supported: false,
        authenticated: false,
        reason: `Authentication has not been verified for environment "${environment.baseUrl}". Protected pages are disabled; no credentials, cookies, CSRF values, snapshots, or requests are created by this helper.`,
    };
}
