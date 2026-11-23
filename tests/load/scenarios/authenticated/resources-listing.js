import { sleep } from 'k6';
import { getEnvironment, resourceUrls } from '../../config/environments.js';
import { authenticate } from '../../helpers/authentication.js';
import { get, responseClassification } from '../../helpers/http.js';
import { check } from 'k6';

export default function () {
    const environment = getEnvironment();
    const auth = authenticate(environment);

    if (!auth.authenticated) {
        console.error(`RESOURCE_LISTING_NOT_TESTED: ${auth.reason}`);
        check(null, {
            'resource-listing: authenticated session available': () => false,
        });
        return;
    }

    for (const resource of resourceUrls(environment)) {
        const response = get(resource.url, environment, {
            endpoint: resource.name,
            endpoint_type: 'authenticated',
            scenario: 'resources-listing',
            resource: resource.name,
        });
        const classification = responseClassification(response);
        check(response, {
            [`${resource.name}: page loaded or permission denied`]: () =>
                classification === 'success' || classification === 'forbidden',
            [`${resource.name}: authenticated response`]: () =>
                classification !== 'unauthenticated' && classification !== 'redirect',
            [`${resource.name}: no server error`]: (r) => r.status < 500,
        });
    }

    sleep(environment.thinkTimeSeconds);
}
