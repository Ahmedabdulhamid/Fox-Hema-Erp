import { sleep } from 'k6';
import { getEnvironment } from '../../config/environments.js';
import { get } from '../../helpers/http.js';
import { checkContent, checkStatus } from '../../helpers/checks.js';

export default function () {
    const environment = getEnvironment();
    const response = get(environment.loginUrl, environment, {
        endpoint: 'admin-login',
        scenario: 'login-page',
        endpoint_type: 'public',
    });
    checkStatus(response, 'login-page');
    checkContent(response, 'login-page', /wire:model=["']data\.email["'][\s\S]*wire:model=["']data\.password["']/i);
    sleep(environment.thinkTimeSeconds);
}
