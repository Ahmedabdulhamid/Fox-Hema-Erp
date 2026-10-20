<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, ['ar', 'en'], true), 404);
        $request->session()->put('locale', $locale);
        $returnTo = $request->input('return_to', '/admin');
        if (! is_string($returnTo) || ! preg_match('~^/(?!/)[a-zA-Z0-9/_.-]*(?:\?[^\r\n\\\\]*)?$~D', $returnTo)) {
            $returnTo = '/admin';
        }

        return redirect($returnTo)->withCookie(cookie('locale', $locale, 60 * 24 * 365));
    }
}
