<?php

namespace App\Livewire\Mcp;

use App\Models\McpServer;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Index extends Component
{
    public bool $creating = false;

    public ?int $edit_id = null;

    public string $name = '';

    public string $type = 'http';

    public string $command = '';

    public string $args = '';

    public string $url = '';

    public bool $enabled = true;

    public function startCreate(): void
    {
        $this->reset('name', 'type', 'command', 'args', 'url');
        $this->edit_id = null;
        $this->enabled = true;
        $this->creating = true;
    }

    public function startEdit(int $id): void
    {
        $s = McpServer::findOrFail($id);
        $this->edit_id = $id;
        $this->name = $s->name;
        $this->type = $s->type;
        $this->command = (string) $s->command;
        $this->args = implode("\n", (array) $s->args);
        $this->url = (string) $s->url;
        $this->enabled = (bool) $s->enabled;
        $this->creating = true;
    }

    public function cancel(): void
    {
        $this->creating = false;
        $this->edit_id = null;
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:64', Rule::unique('mcp_servers', 'name')->ignore($this->edit_id)],
            'type' => ['required', Rule::in(['stdio', 'http'])],
            'command' => [Rule::requiredIf($this->type === 'stdio'), 'string', 'max:255'],
            'url' => [Rule::requiredIf($this->type === 'http'), 'url', 'max:255'],
        ]);

        $data = [
            'name' => $this->name,
            'type' => $this->type,
            'command' => $this->type === 'stdio' ? $this->command : null,
            'args' => $this->type === 'stdio'
                ? array_values(array_filter(array_map('trim', explode("\n", $this->args))))
                : null,
            'url' => $this->type === 'http' ? $this->url : null,
            'enabled' => $this->enabled,
            'created_by' => $this->edit_id ? McpServer::findOrFail($this->edit_id)->created_by : Auth::user()->id,
        ];

        if ($this->edit_id) {
            McpServer::whereKey($this->edit_id)->where('created_by', Auth::user()->id)->update($data);
        } else {
            McpServer::create($data);
        }

        $this->cancel();
        session()->flash('success', __('actions.save'));
    }

    public function toggle(int $id): void
    {
        McpServer::whereKey($id)->where('created_by', Auth::user()->id)
            ->update(['enabled' => ! McpServer::findOrFail($id)->enabled]);
    }

    public function delete(int $id): void
    {
        McpServer::whereKey($id)->where('created_by', Auth::user()->id)->delete();
    }

    public function render(): View
    {
        $servers = McpServer::withCount('agents')
            ->where('created_by', Auth::user()->id)
            ->latest('id')
            ->get();

        return view('livewire.mcp.index', [
            'servers' => $servers,
        ]);
    }
}
