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
            @endcan
        </div>
        <div>Configuration</div>
    </div>
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
    @php
        $websiteUrlFieldKey = $fields->has('SERVICE_URL_NGINX_80') ? 'SERVICE_URL_NGINX_80' : 'SERVICE_URL_LARAVEL';
    @endphp
    @if ($fields->has($websiteUrlFieldKey))
        <div class="w-full">
            <x-forms.input canGate="update" :canResource="$service" id="fields.{{ $websiteUrlFieldKey }}.value"
                label="Website URL" placeholder="Introduzca la url de la pagina"
                helper="Public URL routed by Coolify for your Laravel app. You can write only the domain."
                wire:change="saveServiceUrl" />
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
                @if ($serviceName === 'SERVICE_GITHUB_REPO_URL' || $serviceName === 'SERVICE_PHP_VERSION' || $serviceName === 'SERVICE_URL_LARAVEL' || $serviceName === 'SERVICE_URL_NGINX_80')
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