import { sleep } from 'k6';
import { getEnvironment, resourceUrls } from '../../config/environments.js';
import { authenticate } from '../../helpers/authentication.js';
import { get, responseClassification } from '../../helpers/http.js';
import { check } from 'k6';

export default function () {
    const environment = getEnvironment();
    const auth = authenticate(environment);
    check(null, {
        'mixed-workload: authenticated session is verified': () => auth.authenticated,
    });
    if (!auth.authenticated) {
        console.error(`MIXED_WORKLOAD_DISABLED: ${auth.reason}`);
        return;
    }

    const roll = Math.random();
    if (roll < 0.3) {
        const response = get(environment.dashboardUrl, environment, {
            endpoint: 'admin-dashboard',
            endpoint_type: 'authenticated',
            scenario: 'mixed-workload',
            workload: 'dashboard',
        });
        check(response, {
            'mixed dashboard: HTTP 200': (r) => r.status === 200,
        });
    } else {
        const resource = resourceUrls(environment)[Math.floor(Math.random() * resourceUrls(environment).length)];
        const response = get(resource.url, environment, {
            endpoint: resource.name,
            endpoint_type: 'authenticated',
            scenario: 'mixed-workload',
            workload: 'resource-listing',
            resource: resource.name,
        });
        const classification = responseClassification(response);
        check(response, {
            'mixed resource: page loaded or permission denied': () =>
                classification === 'success' || classification === 'forbidden',
        });
    }

    sleep(environment.thinkTimeSeconds);
}
