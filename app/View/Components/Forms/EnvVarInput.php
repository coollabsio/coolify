<?php

namespace App\View\Components\Forms;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\Component;
use Visus\Cuid2\Cuid2;

class EnvVarInput extends Component
{
    public ?string $modelBinding = null;

    public ?string $htmlId = null;

    public array $scopeUrls = [];

    public function __construct(
        public ?string $id = null,
        public ?string $name = null,
        public ?string $type = 'text',
        public ?string $value = null,
        public ?string $label = null,
        public bool $required = false,
        public bool $disabled = false,
        public bool $readonly = false,
        public ?string $helper = null,
        public bool $allowToPeak = true,
        public string $defaultClass = 'input',
        public string $autocomplete = 'off',
        public ?int $minlength = null,
        public ?int $maxlength = null,
        public bool $autofocus = false,
        public ?string $canGate = null,
        public mixed $canResource = null,
        public bool $autoDisable = true,
        public array $availableVars = [],
        public ?string $projectUuid = null,
        public ?string $environmentUuid = null,
    ) {
        // Handle authorization-based disabling
        if ($this->canGate && $this->canResource && $this->autoDisable) {
            $hasPermission = Gate::allows($this->canGate, $this->canResource);

            if (! $hasPermission) {
                $this->disabled = true;
            }
        }
    }

    public function render(): View|Closure|string
    {
        // Store original ID for wire:model binding (property name)
        $this->modelBinding = $this->id;

        if (is_null($this->id)) {
            $this->id = new Cuid2;
            // Don't create wire:model binding for auto-generated IDs
            $this->modelBinding = 'null';
        }
        // Generate unique HTML ID by adding random suffix
        // This prevents duplicate IDs when multiple forms are on the same page
        if ($this->modelBinding && $this->modelBinding !== 'null') {
            // Use original ID with random suffix for uniqueness
            $uniqueSuffix = new Cuid2;
            $this->htmlId = $this->modelBinding.'-'.$uniqueSuffix;
        } else {
            $this->htmlId = (string) $this->id;
        }

        if (is_null($this->name)) {
            $this->name = $this->modelBinding !== 'null' ? $this->modelBinding : (string) $this->id;
        }

        if ($this->type === 'password') {
            $this->defaultClass = $this->defaultClass.'  pr-[2.8rem]';
        }

        $this->scopeUrls = [
            'team' => route('shared-variables.team.index'),
            'project' => route('shared-variables.project.index'),
            'environment' => $this->projectUuid && $this->environmentUuid
                ? route('shared-variables.environment.show', [
                    'project_uuid' => $this->projectUuid,
                    'environment_uuid' => $this->environmentUuid,
                ])
                : route('shared-variables.environment.index'),
            'default' => route('shared-variables.index'),
        ];

        return view('components.forms.env-var-input');
    }
}
