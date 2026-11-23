export function inspectLivewirePage(html) {
    const updateEndpoint = html.match(/<script[^>]+src=["']([^"']*livewire[^"']*\/livewire(?:\.min)?\.js)["']/i);
    const snapshotAttributes = html.match(/wire:snapshot=["'][^"']+["']/gi) || [];
    const hasLivewireComponent = /<[^>]+\bwire:id=|<[^>]+\bwire:snapshot=/i.test(html);

    return {
        supported: false,
        reason: 'HTTP Livewire authentication and interaction are disabled until a fresh browser-derived component snapshot and verified update protocol are available.',
        detectedUpdateScript: updateEndpoint ? updateEndpoint[1] : null,
        snapshotCount: snapshotAttributes.length,
        hasLivewireComponent,
    };
}
