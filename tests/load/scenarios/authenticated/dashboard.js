import { sleep } from 'k6';
import { getEnvironment } from '../../config/environments.js';
import { authenticate } from '../../helpers/authentication.js';
import { get, responseClassification } from '../../helpers/http.js';
import { check } from 'k6';

export default function () {
    const environment = getEnvironment();
    const auth = authenticate(environment);

    if (!auth.authenticated) {
        console.error(`DASHBOARD_NOT_TESTED: ${auth.reason}`);
        check(null, {
            'dashboard: authenticated session available': () => false,
        });
        return;
    }

    const response = get(environment.dashboardUrl, environment, {
        endpoint: 'admin-dashboard',
        scenario: 'dashboard',
        endpoint_type: 'authenticated',
    });
    const classification = responseClassification(response);
    check(response, {
        'dashboard: HTTP 200': (r) => r.status === 200,
        'dashboard: not redirected': () => classification !== 'redirect',
    });
    sleep(environment.thinkTimeSeconds);
}
