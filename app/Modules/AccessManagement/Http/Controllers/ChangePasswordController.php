<?php

namespace App\Modules\AccessManagement\Http\Controllers;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ChangePasswordController extends Controller
{
    public function edit(): View
    {
        return view('access-management::change-password');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'confirmed', Password::min(12)->uncompromised()],
        ]);
        /** @var User $user */
        $user = $request->user();
        $user->forceFill([
            'password' => Hash::make($data['password']),
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();
        $request->session()->regenerate();
        app(AuditLogger::class)->record('password_changed', $user, actor: $user);

        return redirect('/admin')->with('status', __('Password changed successfully.'));
    }
}
