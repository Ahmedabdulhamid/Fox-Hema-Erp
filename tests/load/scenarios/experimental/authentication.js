import { sleep } from 'k6';
import { getEnvironment } from '../../config/environments.js';
import { authenticate } from '../../helpers/authentication.js';
import { check } from 'k6';

export default function () {
    const environment = getEnvironment();
    const auth = authenticate(environment);

    check(null, {
        'authentication: real HTTP login protocol is verified': () => auth.authenticated,
    });

    if (!auth.authenticated) {
        console.error(`AUTHENTICATION_DISABLED: ${auth.reason}`);
    }

    sleep(environment.thinkTimeSeconds);
}
