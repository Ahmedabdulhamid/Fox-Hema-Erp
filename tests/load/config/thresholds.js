function configuredNumber(name, fallback, minimum, maximum) {
    const value = Number(__ENV[name] || fallback);
    if (!Number.isFinite(value) || value < minimum || value > maximum) {
        throw new Error(`${name} must be a number between ${minimum} and ${maximum}.`);
    }
    return value;
}

export const thresholds = {
    http_req_failed: [`rate<${configuredNumber('HTTP_FAILURE_THRESHOLD', 0.05, 0, 1)}`],
    http_req_duration: [
        `p(95)<${configuredNumber('P95_THRESHOLD_MS', 3000, 1, 3600000)}`,
        `p(99)<${configuredNumber('P99_THRESHOLD_MS', 5000, 1, 3600000)}`,
    ],
    checks: [`rate>${configuredNumber('CHECKS_PASS_THRESHOLD', 0.95, 0, 1)}`],
};
