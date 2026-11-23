import { check } from 'k6';

export default function () {
    check(null, {
        'livewire: interaction scenario explicitly disabled': () => false,
    });
    console.error('LIVEWIRE_SCENARIO_DISABLED: No fresh browser-derived Livewire snapshot or verified update protocol was available. Use Playwright-assisted discovery in a later step.');
}
