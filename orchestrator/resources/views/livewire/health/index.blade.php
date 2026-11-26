<div class="health" x-data>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0"><x-icon name="activity" class="me-2" />{{ __('health.title') }}</h1>
        <div class="d-flex gap-2">
            <span class="badge bg-secondary">{{ __('health.auto_refresh') }}</span>
            <a href="{{ route('agents.index') }}" wire:navigate class="btn btn-outline-secondary btn-sm">← {{ __('actions.back') }}</a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <h2 class="h6">{{ __('health.agents_by_status') }}</h2>
                    @if ($summary['agents_by_status'] === [])
                        <p class="small text-muted mb-0">{{ __('health.no_agents') }}</p>
                    @else
                        <ul class="list-unstyled small mb-0">
                            @foreach ($summary['agents_by_status'] as $status => $count)
                                <li class="d-flex justify-content-between border-bottom py-1">
                                    <code>{{ $status }}</code>
                                    <strong>{{ $count }}</strong>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <h2 class="h6">{{ __('health.providers') }}</h2>
                    @forelse ($summary['providers'] as $p)
                        <div class="d-flex align-items-center justify-content-between border-bottom py-1 small">
                            <span>{{ $p['name'] }}</span>
                            <span>
                                <span class="badge bg-{{ $p['status'] === 'ok' ? 'success' : 'danger' }}">{{ $p['status'] }}</span>
                                <span class="text-muted ms-2">{{ $p['last_checked_at'] ? \Illuminate\Support\Carbon::parse($p['last_checked_at'])->diffForHumans() : __('health.unchecked') }}</span>
                            </span>
                        </div>
                    @empty
                        <p class="small text-muted mb-0">{{ __('health.no_providers') }}</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">{{ __('health.notifications') }}</h2>
            <p class="small text-muted mb-2">{{ __('health.notifications_hint') }}</p>
            @if ($lastNotifications === [])
                <p class="small text-muted mb-0">{{ __('health.no_notifications') }}</p>
            @else
                <ul class="list-unstyled small mb-0">
                    @foreach ($lastNotifications as $n)
                        <li class="d-flex justify-content-between border-bottom py-1">
                            <span>{{ $n['agent_name'] ?? '#'.$n['agent_id'] }}</span>
                            <span>
                                <span class="badge bg-{{ $n['status'] === 'sent' ? 'success' : 'warning' }}">{{ $n['status'] }}</span>
                                <span class="text-muted ms-2">{{ $n['at'] ? \Illuminate\Support\Carbon::parse($n['at'])->diffForHumans() : '' }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h2 class="h6">{{ __('health.recent_failures') }}</h2>
            @if ($summary['recent_failures'] === [])
                <p class="small text-muted mb-0">{{ __('health.no_failures') }}</p>
            @else
                <ul class="list-unstyled small mb-0">
                    @foreach ($summary['recent_failures'] as $f)
                        <li class="border-bottom py-2">
                            <div class="d-flex justify-content-between">
                                <strong>{{ $f['name'] ?? $f['agent_id'] }}</strong>
                                <span class="text-muted text-nowrap me-2">{{ $f['at'] ? \Illuminate\Support\Carbon::parse($f['at'])->diffForHumans() : '' }}</span>
                            </div>
                            <div class="text-muted"><code>{{ $f['runtime'] }}</code> — {{ $f['cause'] }}</div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>

<script>
// Auto-refresh ligero: recarga la vista cada 10 s (vista admin).
setInterval(() => window.location.reload(), 10000);
</script>
