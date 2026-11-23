export const profiles = {
    smoke: { vus: 1, rampUp: '5s', steady: '30s', rampDown: '5s' },
    baseline: { vus: 5, rampUp: '30s', steady: '2m', rampDown: '30s' },
    light: { vus: 10, rampUp: '1m', steady: '3m', rampDown: '1m' },
    medium: { vus: 25, rampUp: '2m', steady: '5m', rampDown: '1m' },
    heavy: { vus: 50, rampUp: '3m', steady: '8m', rampDown: '2m' },
    stress: { vus: 100, rampUp: '5m', steady: '10m', rampDown: '3m' },
    extreme: { vus: 200, rampUp: '8m', steady: '12m', rampDown: '5m' },
};

export function getProfile(name) {
    if (!Object.prototype.hasOwnProperty.call(profiles, name)) {
        return null;
    }
    return profiles[name];
}

export function validateProfileSafety(name, confirmation) {
    const profile = getProfile(name);
    if (profile === null) {
        return {
            allowed: false,
            profile: null,
            reason: `Unknown TEST_PROFILE "${name}". Valid profiles: smoke, baseline, light, medium, heavy, stress, extreme.`,
        };
    }
    if (profile.vus <= 10) {
        return { allowed: true, profile };
    }
    if (!confirmation) {
        return {
            allowed: false,
            profile,
            reason: `Profile "${name}" uses ${profile.vus} VUs. Set CONFIRM_HIGH_LOAD=I_CONFIRM_HIGH_LOAD only after explicit approval.`,
        };
    }
    return { allowed: true, profile };
}
