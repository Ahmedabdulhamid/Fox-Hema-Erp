import { getEnvironment } from './config/environments.js';
import { getProfile, validateProfileSafety } from './config/profiles.js';
import { thresholds } from './config/thresholds.js';
import smoke from './scenarios/public/smoke.js';
import loginPage from './scenarios/public/login-page.js';
import authentication from './scenarios/experimental/authentication.js';
import dashboard from './scenarios/authenticated/dashboard.js';
import resourcesListing from './scenarios/authenticated/resources-listing.js';
import livewireInteractions from './scenarios/experimental/livewire-interactions.js';
import mixedWorkload from './scenarios/authenticated/mixed-workload.js';

const environment = getEnvironment();
const safety = validateProfileSafety(environment.testProfile, environment.highLoadConfirmation);
const safeProfile = safety.profile || getProfile('smoke');
const scenarioDefinitions = {
    smoke: { category: 'public', enabled: true, handler: smoke },
    'login-page': { category: 'public', enabled: true, handler: loginPage },
    authentication: { category: 'experimental', enabled: false, handler: authentication },
    dashboard: { category: 'authenticated', enabled: false, handler: dashboard },
    'resources-listing': { category: 'authenticated', enabled: false, handler: resourcesListing },
    'mixed-workload': { category: 'authenticated', enabled: false, handler: mixedWorkload },
    'livewire-interactions': { category: 'experimental', enabled: false, handler: livewireInteractions },
};
const selectedScenario = scenarioDefinitions[environment.scenario];

export const options = {
    scenarios: {
        load_test: {
            executor: 'ramping-vus',
            gracefulRampDown: safeProfile.rampDown,
            exec: 'runSelectedScenario',
            startVUs: 0,
            stages: [
                { duration: safeProfile.rampUp, target: safeProfile.vus },
                { duration: safeProfile.steady, target: safeProfile.vus },
                { duration: safeProfile.rampDown, target: 0 },
            ],
        },
    },
    thresholds,
};

export function setup() {
    if (!safety.allowed) {
        throw new Error(safety.reason);
    }
    if (!selectedScenario) {
        throw new Error(`Unknown LOAD_SCENARIO "${environment.scenario}".`);
    }
    if (!selectedScenario.enabled) {
        throw new Error(
            `LOAD_SCENARIO "${environment.scenario}" is disabled. Authentication has not been verified; no request was sent.`,
        );
    }
    return {
        runId: environment.runId,
        scenario: environment.scenario,
        category: selectedScenario.category,
        profile: environment.testProfile,
        vus: safety.profile.vus,
        baseUrl: environment.baseUrl,
    };
}

export function runSelectedScenario(data) {
    if (!selectedScenario || !selectedScenario.enabled) {
        throw new Error(`LOAD_SCENARIO "${environment.scenario}" is not enabled.`);
    }
    if (
        data.runId !== environment.runId ||
        data.scenario !== environment.scenario ||
        data.profile !== environment.testProfile
    ) {
        throw new Error('k6 run metadata does not match the setup configuration.');
    }
    selectedScenario.handler();
}

export function handleSummary(summary) {
    const metadata = {
        runId: environment.runId,
        scenario: environment.scenario,
        category: selectedScenario ? selectedScenario.category : 'unknown',
        profile: environment.testProfile,
        virtualUsers: safeProfile.vus,
        baseUrl: environment.baseUrl,
        generatedAt: new Date().toISOString(),
    };
    const report = {
        metadata,
        metrics: summary.metrics,
        root_group: summary.root_group,
    };
    const data = JSON.stringify(report, null, 2);
    const text = [
        `Run ID: ${environment.runId}`,
        `Scenario: ${environment.scenario}`,
        `Category: ${metadata.category}`,
        `Profile: ${environment.testProfile}`,
        `Virtual users: ${safeProfile.vus}`,
        `Base URL: ${environment.baseUrl}`,
        `Generated at: ${metadata.generatedAt}`,
        '',
        'No application business operations are included in this scenario.',
    ].join('\n');

    return {
        [`tests/load/reports-${environment.runId}-summary.json`]: data,
        [`tests/load/reports-${environment.runId}-summary.txt`]: text,
        stdout: text,
    };
}
