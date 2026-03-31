<form wire:submit.prevent='submit' class="flex flex-col gap-4 pb-2">
    <div>
        <div class="flex gap-2">
            <h2>Service Stack</h2>
            @if (isDev())
                <div>{{ $service->compose_parsing_version }}</div>
            @endif
            <x-forms.button canGate="update" :canResource="$service" wire:target='submit'
                type="submit">Save</x-forms.button>
            @can('update', $service)
                <x-modal-input buttonTitle="Edit Compose File" title="Edit Docker Compose" :closeOutside="false">
                    <livewire:project.service.edit-compose serviceId="{{ $service->id }}" />
                </x-modal-input>
                @if ($this->isLaravelRootkitStack())
                    <x-forms.button
                        type="button"
                        wire:click="rebuildFrontendAssets"
                        wire:loading.attr="disabled"
                        wire:target="rebuildFrontendAssets"
                    >
                        Rebuild CSS/JS
                    </x-forms.button>
                    <x-forms.button
                        type="button"
                        wire:click="verifyFrontendAssets"
                        wire:loading.attr="disabled"
                        wire:target="verifyFrontendAssets"
                    >
                        Verificar Assets
                    </x-forms.button>
                    <x-forms.button
                        type="button"
                        wire:click="deployLaravelChanges"
                        wire:loading.attr="disabled"
                        wire:target="deployLaravelChanges"
                    >
                        Deploy cambios
                    </x-forms.button>
                    <x-forms.button
                        type="button"
                        wire:click="runLaravelMigrations"
                        wire:loading.attr="disabled"
                        wire:target="runLaravelMigrations"
                    >
                        Run migrations
                    </x-forms.button>
                    <x-forms.button
                        type="button"
                        wire:click="runLaravelMaintenanceCommand('clear-config-and-cache')"
                        wire:loading.attr="disabled"
                        wire:target="runLaravelMaintenanceCommand('clear-config-and-cache')"
                    >
                        Clear config/cache
                    </x-forms.button>
                    <x-forms.button
                        type="button"
                        wire:click="runLaravelMaintenanceCommand('config-cache')"
                        wire:loading.attr="disabled"
                        wire:target="runLaravelMaintenanceCommand('config-cache')"
                    >
                        Config cache
                    </x-forms.button>
                    <x-forms.button
                        type="button"
                        wire:click="runLaravelMaintenanceCommand('queue-restart')"
                        wire:loading.attr="disabled"
                        wire:target="runLaravelMaintenanceCommand('queue-restart')"
                    >
                        Queue restart
                    </x-forms.button>
                    <x-forms.button
                        type="button"
                        wire:click="runLaravelMaintenanceCommand('queue-work-once')"
                        wire:loading.attr="disabled"
                        wire:target="runLaravelMaintenanceCommand('queue-work-once')"
                    >
                        Run queue worker
                    </x-forms.button>
                @endif
            @endcan
        </div>
        <div>Configuration</div>
    </div>
    @if ($this->isLaravelRootkitStack() && $assetActionOutput !== '')
        <div class="rounded border border-coolgray-300 dark:border-coolgray-700 p-3">
            <div class="mb-2 text-sm font-semibold">Asset Command Output</div>
            <pre class="whitespace-pre-wrap break-words text-xs">{{ $assetActionOutput }}</pre>
        </div>
    @endif
    <div class="flex gap-2">
        <x-forms.input canGate="update" :canResource="$service" id="name" required label="Service Name"
            placeholder="My super WordPress site" />
        <x-forms.input canGate="update" :canResource="$service" id="description" label="Description" />
    </div>
    <div class="w-96">
        <x-forms.checkbox canGate="update" :canResource="$service" instantSave id="connectToDockerNetwork"
            label="Connect To Predefined Network"
            helper="By default, you do not reach the Coolify defined networks.<br>Starting a docker compose based resource will have an internal network. <br>If you connect to a Coolify defined network, you maybe need to use different internal DNS names to connect to a resource.<br><br>For more information, check <a class='underline dark:text-white' target='_blank' href='https://coolify.io/docs/knowledge-base/docker/compose#connect-to-predefined-networks'>this</a>." />
    </div>
    @if ($fields->has('SERVICE_GITHUB_REPO_URL'))
        <div class="w-full">
            <x-forms.input canGate="update" :canResource="$service" id="fields.SERVICE_GITHUB_REPO_URL.value"
                label="GitHub Repository URL" placeholder="https://github.com/owner/repository.git"
                helper="Public repository URL used to clone your Laravel project." wire:change="saveGithubRepoUrl" />
        </div>
    @endif
    @if ($this->isLaravelRootkitStack() && $fields->has('SERVICE_GITHUB_BRANCH'))
        <div class="w-full max-w-sm space-y-2">
            @if (count($githubBranches) > 0)
                <x-forms.select canGate="update" :canResource="$service" id="fields.SERVICE_GITHUB_BRANCH.value"
                    label="Git Branch" wire:change="saveGithubBranch"
                    helper="Detected branches from GitHub repository URL.">
                    @foreach ($githubBranches as $branch)
                        <option value="{{ $branch }}">{{ $branch }}</option>
                    @endforeach
                </x-forms.select>
            @else
                <x-forms.input canGate="update" :canResource="$service" id="fields.SERVICE_GITHUB_BRANCH.value"
                    label="Git Branch" placeholder="main"
                    helper="Branch to deploy from the GitHub repository."
                    wire:change="saveGithubBranch" />
            @endif
            <x-forms.button
                type="button"
                wire:click="loadGithubBranches"
                wire:loading.attr="disabled"
                wire:target="loadGithubBranches"
            >
                Detectar ramas
            </x-forms.button>
        </div>
    @endif
    @if ($this->isLaravelRootkitStack() && $fields->has('SERVICE_GITHUB_TOKEN'))
        <div class="w-full max-w-sm">
            <x-forms.input canGate="update" :canResource="$service" type="password" id="fields.SERVICE_GITHUB_TOKEN.value"
                label="GitHub Token"
                helper="Opcional para repos privados. Se usa para detectar ramas y hacer fetch autenticado."
                wire:change="saveGithubToken" />
        </div>
    @endif
    @if ($fields->has('SERVICE_PHP_VERSION'))
        <div class="w-full max-w-xs">
            <x-forms.select canGate="update" :canResource="$service" id="fields.SERVICE_PHP_VERSION.value"
                label="PHP Version" wire:change="savePhpVersion" helper="Runtime version for Laravel container.">
                <option value="7.4">7.4</option>
                <option value="8.1">8.1</option>
                <option value="8.2">8.2</option>
                <option value="8.3">8.3</option>
                <option value="8.4">8.4</option>
            </x-forms.select>
        </div>
    @endif
    @if ($fields->count() > 0)
        <div>
            <h3>Service Specific Configuration</h3>
        </div>
        <div class="grid grid-cols-2 gap-2">
            @foreach ($fields as $serviceName => $field)
                @if ($serviceName === 'SERVICE_GITHUB_REPO_URL' || $serviceName === 'SERVICE_GITHUB_BRANCH' || $serviceName === 'SERVICE_GITHUB_TOKEN' || $serviceName === 'SERVICE_PHP_VERSION' || $serviceName === 'SERVICE_URL_LARAVEL' || $serviceName === 'SERVICE_URL_NGINX' || $serviceName === 'SERVICE_URL_NGINX_80' || $serviceName === 'SERVICE_FQDN_NGINX' || $serviceName === 'SERVICE_FQDN_NGINX_80')
                    @continue
                @endif
                <div class="flex items-center gap-2"><span
                        class="font-bold">{{ data_get($field, 'serviceName') }}</span>{{ data_get($field, 'name') }}
                    @if (data_get($field, 'customHelper'))
                        <x-helper helper="{{ data_get($field, 'customHelper') }}" />
                    @else
                        <x-helper helper="Variable name: {{ $serviceName }}" />
                    @endif
                </div>
                <x-forms.input canGate="update" :canResource="$service"
                    type="{{ data_get($field, 'isPassword') ? 'password' : 'text' }}"
                    required="{{ str(data_get($field, 'rules'))?->contains('required') }}"
                    id="fields.{{ $serviceName }}.value"></x-forms.input>
            @endforeach
        </div>
    @endif
</form>