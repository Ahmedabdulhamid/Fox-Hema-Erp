import { sleep } from 'k6';
import { getEnvironment } from '../../config/environments.js';
import { get } from '../../helpers/http.js';
import { checkContent, checkStatus } from '../../helpers/checks.js';

export default function () {
    const environment = getEnvironment();
    const loginResponse = get(environment.loginUrl, environment, {
        endpoint: 'admin-login',
        scenario: 'smoke',
        endpoint_type: 'public',
    });
    checkStatus(loginResponse, 'smoke-login');
    checkContent(loginResponse, 'smoke-login', /wire:model=["']data\.email["'][\s\S]*wire:model=["']data\.password["']/i);

    sleep(environment.thinkTimeSeconds);
}
