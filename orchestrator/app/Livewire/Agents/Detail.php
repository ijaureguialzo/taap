<?php

namespace App\Livewire\Agents;

use App\Agents\AgentManager;
use App\Agents\Timeline;
use App\Enums\AgentStatus;
use App\Enums\FsMountMode;
use App\Jobs\BridgeApplyConfig;
use App\Models\Agent;
use App\Models\Model;
use App\Models\User;
use App\Services\FileSystemException;
use App\Services\FilesystemService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Detail extends Component
{
    public Agent $agent;

    public string $system_prompt = '';

    public bool $internet_enabled = false;

    public bool $auto_restart = false;

    public ?int $model_id = null;

    /** Estado en vivo (canal Reverb) para el badge del header. */
    public ?string $liveStatus = null;

    /* Carpetas del FS compartido (shared-filesystem 7.1) */

    /** Directorio que muestra el picker (notacion virtual del sujeto). */
    public string $fsBrowse = '/';

    /** Ruta a añadir (virtual del sujeto: el propietario navega desde su home). */
    public ?string $newFsPath = null;

    public string $newFsMode = 'read_write';

    /** @var list<array{name: string, type: string, size: int, mtime: string}> */
    public array $fsEntries = [];

    public ?string $fsError = null;

    public function mount(Agent $agent): void
    {
        $user = Auth::user();
        if (! $user->isAdmin() && $agent->owner_id !== $user->id) {
            abort(404);
        }

        $this->agent = $agent;
        $this->system_prompt = (string) $agent->system_prompt;
        $this->internet_enabled = $agent->internet_enabled;
        $this->auto_restart = $agent->auto_restart;
        $this->model_id = $agent->model_id;
        $this->liveStatus = $agent->status->value;
        $this->fsRefreshBrowse();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function agentEvent(array $payload): void
    {
        if (($payload['agent_id'] ?? null) != $this->agent->id) {
            return;
        }
        if ($payload['type'] === 'status.change') {
            $this->liveStatus = $payload['payload']['status'] ?? $this->liveStatus;
        }
    }

    public function lifecycle(string $action): void
    {
        $manager = app(AgentManager::class);

        try {
            $this->agent = match ($action) {
                'start' => $manager->start($this->agent),
                'stop' => $manager->stop($this->agent),
                'restart' => $manager->restart($this->agent),
                default => $this->agent,
            };
        } catch (\Throwable $e) {
            report($e);
            session()->flash('error', $e->getMessage());
        }

        $this->agent->refresh();
        $this->liveStatus = $this->agent->status->value;
    }

    public function saveConfig(): void
    {
        $this->validate([
            'system_prompt' => ['nullable', 'string', 'max:20000'],
            'model_id' => ['nullable', 'exists:models,id'],
        ]);

        $configChanged = $this->agent->system_prompt !== ($this->system_prompt ?: null)
            || $this->agent->model_id !== ($this->model_id ?? null);

        $this->agent->system_prompt = $this->system_prompt ?: null;
        $this->agent->auto_restart = $this->auto_restart;
        if (($this->model_id ?? 0) > 0) {
            $this->agent->model_id = $this->model_id;
        }
        $this->agent->save();

        // Model/prompt changed: the agent applies it without losing the
        // session (Pi/Hermes restart + resume; Claude on the next turn).
        if ($configChanged) {
            BridgeApplyConfig::dispatch($this->agent->id);
        }

        // Si cambia el internet: hay que recrear el contenedor (red de egress).
        if ($this->internet_enabled !== $this->agent->internet_enabled) {
            $this->agent->internet_enabled = $this->internet_enabled;
            $this->agent->save();
            try {
                $this->agent = app(AgentManager::class)->provision($this->agent);
            } catch (\Throwable $e) {
                session()->flash('error', $e->getMessage());
            }
        }

        session()->flash('success', __('actions.save'));
    }

    public function delete(): void
    {
        $manager = app(AgentManager::class);

        try {
            $manager->terminate($this->agent);
            $this->agent->delete(); // soft delete (la fila)
        } catch (\Throwable $e) {
            report($e);
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->redirectRoute('agents.index', navigate: true);
    }

    /* ================= carpetas del FS compartido (7.1) ================= */

    /**
     * El picker navega desde la raiz del SUJETO (FilesystemService):
     * el propietario desde su home (`/` = su <fs_slug>); el admin desde la
     * raiz del arbol. La notacion virtual es la misma que usa la pagina de
     * Archivos; al guardar se traduce al path canónico desde la raiz /fs.
     */
    public function fsRefreshBrowse(): void
    {
        $user = Auth::user();

        try {
            $listing = app(FilesystemService::class)->list($user, $this->fsBrowse === '' ? '/' : $this->fsBrowse);
            $this->fsBrowse = $listing['path'];
            $this->fsEntries = $listing['entries'];
            $this->fsError = null;
        } catch (FileSystemException $e) {
            $this->fsError = $e->getMessage();
            $this->fsEntries = [];
        }
    }

    public function fsBrowseTo(string $dir): void
    {
        $this->fsBrowse = $dir;
        $this->fsRefreshBrowse();
    }

    /** El picker "selecciona" el directorio en el que esta parado. */
    public function fsSelectDir(): void
    {
        $this->newFsPath = $this->fsBrowse;
    }

    /**
     * Añade (o actualiza) una carpeta en la lista del agente y aplica el
     * cambio reiniciando el agente (flujo 6.5): los bind mounts son
     * inmutables tras el create, la sesion se conserva en el volumen.
     */
    public function addFsMount(): void
    {
        $user = Auth::user();
        $input = trim((string) $this->newFsPath);

        if ($input === '') {
            $this->fsError = __('agents.fs_path_required');

            return;
        }

        // El resolve contiene el path en la raiz del sujeto: el propietario
        // no puede salirse de su home (un `../otro-usuario/x` -> 404); el
        // admin puede apuntar a cualquier parte del arbol.
        try {
            $physical = app(FilesystemService::class)->resolve($user, $input);
        } catch (FileSystemException $e) {
            $this->fsError = $user->isAdmin() ? $e->getMessage() : __('agents.fs_outside_home');

            return;
        }

        if (! is_dir($physical)) {
            $this->fsError = __('agents.fs_not_dir');

            return;
        }

        $mountPath = $this->mountPathFor($user, $input);
        $this->agent->fsMounts()->updateOrCreate(
            ['path' => $mountPath],
            ['mode' => $this->newFsMode],
        );

        $this->newFsPath = null;
        $this->fsError = null;
        $this->applyFsChange();
        session()->flash('success', __('agents.fs_saved'));
    }

    public function updateFsMode(string $id, string $mode): void
    {
        FsMountMode::from($mode); // valida el valor
        $this->agent->fsMounts()->whereKey($id)->update(['mode' => $mode]);
        $this->applyFsChange();
        session()->flash('success', __('agents.fs_saved'));
    }

    public function removeFsMount(string $id): void
    {
        $this->agent->fsMounts()->whereKey($id)->delete();
        $this->applyFsChange();
        session()->flash('success', __('agents.fs_removed'));
    }

    /** Aplica el cambio de mounts reiniciando (6.5) sin perder la sesion. */
    protected function applyFsChange(): void
    {
        try {
            app(AgentManager::class)->restart($this->agent->refresh());
            session()->flash('warning', __('agents.fs_apply_note'));
        } catch (\Throwable $e) {
            report($e);
            session()->flash('error', $e->getMessage());
        }
    }

    /**
     * La notacion virtual del sujeto al path canónico desde la raiz /fs
     * (la convencion de agent_fs_mounts, sin el prefijo /fs): el admin ya
     * navega desde la raiz; el propietario desde su home (<fs_slug>).
     */
    protected function mountPathFor(User $user, string $virtual): string
    {
        $v = $virtual;
        // Si el path no empieza en '/' ni en './' se antepone el separador
        // (un 'proyectos' relativo a la home es '/proyectos').
        if ($v !== '' && $v[0] !== '/' && ! str_starts_with($v, './')) {
            $v = '/'.$v;
        }
        $fromRoot = $user->isAdmin()
            ? $v
            : '/'.((string) $user->fs_slug).$v;
        $path = '/'.ltrim($fromRoot, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public function render(): View
    {
        $user = Auth::user();
        $canManage = $user->isAdmin() || $user->id === $this->agent->owner_id;

        $status = $this->agent->status;
        if ($this->liveStatus) {
            try {
                $status = AgentStatus::fromString($this->liveStatus);
            } catch (\Throwable) {
                // valor desconocido: mantener el de BD
            }
        }

        // Modelos compatibles con el runtime del agente (spec
        // model-management: los de formato incompatible quedan fuera).
        $types = [];
        $formats = $this->agent->runtime->modelFormats();
        if (in_array('anthropic', $formats, true)) {
            $types[] = 'anthropic';
        }
        if (in_array('openai', $formats, true) || $formats === []) {
            $types = array_merge(['openai', 'other'], $types);
        }
        $models = Model::with('provider')
            ->whereHas('provider', fn ($q) => $q->whereIn('type', $types))
            ->get()
            ->keyBy('id');
        // El modelo actual se mantiene en la lista aunque haya cambiado de
        // formato (que se vea lo que usa el agente).
        if ($this->agent->model && ! $models->has($this->agent->model->id)) {
            $this->agent->model->loadMissing('provider');
            $models[$this->agent->model->id] = $this->agent->model;
        }

        // 7.2: lineas de tiempo (ventanas de trabajo, design D6).
        $windows = app(Timeline::class)->windows($this->agent->id);

        // Carpetas del FS compartido (7.1): canonicas desde la raiz /fs;
        // "externa" = fuera de la home del propietario del agente.
        $owner = $this->agent->owner;
        $home = '/'.((string) $owner->fs_slug);
        $fsMounts = $this->agent->fsMounts()
            ->orderBy('path')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'path' => $m->path,
                'display' => '/fs'.$m->path,
                'mode' => $m->mode instanceof FsMountMode ? $m->mode->value : (string) $m->mode,
                'external' => ! ($m->path === $home || str_starts_with($m->path, $home.'/')),
            ])
            ->values()
            ->all();

        // Capacidades declaradas en el registro, por grupo (design D12)
        $capabilities = ['format' => [], 'session' => null, 'apply' => null];
        foreach ($this->agent->runtime->capabilities() as $cap) {
            $group = match ($cap) {
                'openai', 'anthropic' => 'format',
                'native', 'turn' => 'session',
                'restart', 'next-turn' => 'apply',
                default => null,
            };
            if ($group === null) {
                continue;
            }
            if ($group === 'format') {
                $capabilities['format'][] = $cap; // runtimes duales declaran los dos
            } else {
                $capabilities[$group] = $cap;
            }
        }
        $capabilities['format'] = $capabilities['format'] === [] ? null : $capabilities['format'];

        return view('livewire.agents.detail', [
            'status' => $status,
            'canManage' => $canManage,
            'models' => $models,
            'windows' => $windows,
            'capabilities' => $capabilities,
            'fsMounts' => $fsMounts,
        ]);
    }
}
