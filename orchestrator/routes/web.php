<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Livewire\Agents;
use App\Livewire\Profile;
use App\Livewire\Files;
use App\Livewire\Mcp;
use App\Livewire\Models;
use App\Livewire\Providers;
use App\Livewire\Audit;
use App\Livewire\Health;
use App\Livewire\Usage;
use App\Livewire\Users;
use App\Services\FileSystemException;
use App\Services\FilesystemService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

// Autenticación (paginas Blade con Livewire para el form)
Route::middleware('guest')->group(function () {
    Route::get('login', fn () => view('pages.auth.login'))->name('login');
    Route::post('login', [LoginController::class, 'login'])->name('login.store');

    Route::middleware('registration.enabled')->group(function () {
        Route::get('register', fn () => view('pages.auth.register'))->name('register');
        Route::post('register', [RegisterController::class, 'register'])->name('register.store');
    });
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

    // Descarga web (cookie): fichero -> stream; carpeta -> .zip (shared-filesystem 5.2).
    Route::get('files/download', function (Request $request) {
        $data = $request->validate(['path' => ['required', 'string', 'max:2048']]);
        $fs = app(FilesystemService::class);
        $user = $request->user();

        try {
            $physical = $fs->resolve($user, $data['path'], follow: true);

            if (is_dir($physical)) {
                return $fs->zip($user, $data['path']);
            }

            return $fs->download($user, $data['path']);
        } catch (FileSystemException $e) {
            abort($e->status);
        }
    })->name('files.download');
    Route::get('users', Users\Index::class)
        ->middleware('role:admin')->name('users.index');
    Route::get('audit', Audit\Index::class)
        ->middleware('role:admin')->name('audit.index');
    Route::get('usage', Usage\Index::class)
        ->middleware('role:admin')->name('usage.index');
    Route::get('health', Health\Index::class)
        ->middleware('role:admin')->name('health.index');

    Route::get('profile', Profile::class)->name('profile.edit');
});
