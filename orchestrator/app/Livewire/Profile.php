<?php

namespace App\Livewire;

use App\Support\UserLifecycle;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Profile extends Component
{
    public string $name = '';

    public string $locale = 'es';

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $user = auth()->user();
        $this->name = $user->name;
        $this->locale = (string) $user->locale;
    }

    public function save(): void
    {
        $user = auth()->user();

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'locale' => ['required', Rule::in(['es', 'en', 'eu'])],
        ];

        if ($this->password !== '') {
            $rules['password'] = ['required', 'string', Password::min(8), 'confirmed'];
            $rules['current_password'] = ['required', 'string', 'current_password'];
        }

        $this->validate($rules);

        $user->forceFill(['name' => $this->name, 'locale' => $this->locale])->save();
        session()->put('locale', $this->locale);
        app()->setLocale($this->locale);

        if ($this->password !== '') {
            app(UserLifecycle::class)->changePassword(
                $user,
                $this->password,
                keepSessionId: session()->getId(),
            );
            $this->reset(['current_password', 'password', 'password_confirmation']);
        }

        session()->flash('success', __('profile.saved'));
    }

    public function render(): View
    {
        return view('livewire.profile.index', [
            'user' => auth()->user(),
        ]);
    }
}
