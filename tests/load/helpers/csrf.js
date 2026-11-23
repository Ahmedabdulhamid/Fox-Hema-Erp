export function extractCsrfToken(html) {
    const meta = html.match(/<meta[^>]+name=["']csrf-token["'][^>]+content=["']([^"']+)["']/i);
    if (meta) {
        return meta[1];
    }

    const hidden = html.match(/<input[^>]+name=["']_token["'][^>]+value=["']([^"']+)["']/i);
    if (hidden) {
        return hidden[1];
    }

    const livewireToken = html.match(/["']?_token["']?\s*[:=]\s*["']([^"']+)["']/i);
    return livewireToken ? livewireToken[1] : null;
}
