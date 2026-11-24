<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Livewire\Agents;
use App\Livewire\Files;
use App\Livewire\Mcp;
use App\Livewire\Models;
use App\Livewire\Providers;
use App\Livewire\Users;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

// Autenticación (paginas Blade con Livewire para el form)
Route::middleware('guest')->group(function () {
    Route::get('login', fn () => view('pages.auth.login'))->name('login');
    Route::post('login', [LoginController::class, 'login'])->name('login.store');
    Route::get('register', fn () => view('pages.auth.register'))->name('register');
    Route::post('register', [RegisterController::class, 'register'])->name('register.store');
});

Route::post('logout', [LoginController::class, 'logout'])
    ->middleware('auth')->name('logout');

Route::middleware(['auth'])->group(function () {
    Route::redirect('dashboard', '/agents')->name('dashboard');

    Route::get('agents', Agents\Index::class)->name('agents.index');
    Route::get('agents/create', Agents\Create::class)->name('agents.create');
    Route::get('agents/{agent}', Agents\Detail::class)->name('agents.show');

    Route::get('models', Models\Index::class)->name('models.index');
    Route::get('providers', Providers\Index::class)->name('providers.index');
    Route::get('mcp', Mcp\Index::class)->name('mcp.index');

    Route::get('files', Files\Index::class)->name('files.index');

    Route::get('users', Users\Index::class)
        ->middleware('role:admin')->name('users.index');

    Route::get('profile/language', fn () => view('pages.profile.language'))->name('profile.language');
    Route::post('profile/language', function (Request $request) {
        $data = $request->validate(['locale' => ['required', 'in:es,en,eu']]);
        $request->user()->forceFill(['locale' => $data['locale']])->save();
        session()->put('locale', $data['locale']);

        return redirect()->route('profile.language')->with('success', __('actions.save'));
    });
});
