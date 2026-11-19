<x-layouts::app :title="__('users.language')">
    <div style="max-width: 480px">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3 mb-0">{{ __('users.language') }}</h1>
            <a href="{{ route('agents.index') }}" wire:navigate class="btn btn-outline-secondary btn-sm">
                ← {{ __('actions.back') }}
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ url('/profile/language') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label">{{ __('users.language') }}</label>
                        <select name="locale" class="form-select">
                            @foreach (['es', 'en', 'eu'] as $loc)
                                <option value="{{ $loc }}" @selected(auth()->user()->locale === $loc)>
                                    {{ $loc }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">
                            {{ __('profile.language_help', ['d' => 'Cambia el idioma de la interfaz. El contenido de los agentes no se traduce.']) }}
                        </div>
                    </div>
                    <button class="btn btn-primary" type="submit">{{ __('actions.save') }}</button>
                </form>
            </div>
        </div>
    </div>
</x-layouts::app>
