<?php

use App\Modules\AccessManagement\Http\Controllers\ChangePasswordController;
use App\Modules\AccessManagement\Http\Controllers\LocationContextController;
use App\Modules\AccessManagement\Http\Middleware\EnforceAccountSecurity;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', EnforceAccountSecurity::class])->prefix('admin')->group(function (): void {
    Route::post('location', LocationContextController::class)->name('access-management.location.update');
    Route::get('password/change', [ChangePasswordController::class, 'edit'])->name('access-management.password.edit');
    Route::put('password/change', [ChangePasswordController::class, 'update'])->name('access-management.password.update');
});
