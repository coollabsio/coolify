<div x-data="{
    initLoadingCompose: $wire.entangle('initLoadingCompose'),
    canUpdate: @js(auth()->user()->can('update', $application)),
    shouldDisable() {
        return this.initLoadingCompose || !this.canUpdate;
    }
}">
    <form wire:submit='submit' class="application-settings-form flex flex-col">
        <x-unsaved-bar action="submit" />
        {{-- Temporarily hidden: the "Compose parser" dev hint and the "View details"
             resource-details modal trigger. --}}
        @if ($buildPack === 'dockercompose')
            <div class="mb-4 flex flex-wrap items-center justify-end gap-2">
                <x-forms.button canGate="update" :canResource="$application" wire:target='initLoadingCompose'
                    x-on:click="$wire.dispatch('loadCompose', false)">
                    {{ $application->docker_compose_raw ? 'Reload compose' : 'Load compose' }}
                </x-forms.button>
            </div>
        @endif
        <div class="application-settings-grid flex flex-col gap-6">
            <x-application.settings-section id="application-details-section" title="Application details" helper="Name the application and choose the build strategy Coolify should use to deploy it." class="application-details-card">
            <div class="grid gap-4">
                <x-forms.input x-bind:disabled="shouldDisable()" id="name" label="Name" required />
                <x-forms.input x-bind:disabled="shouldDisable()" id="description" label="Description" />
            </div>

            </x-application.settings-section>

            <x-application.settings-section id="public-access-section" title="Public access" helper="Connect public domains and control how incoming requests are redirected between www and non-www.">
            @if ($buildPack === 'dockercompose')
                @if (
                    !is_null($parsedServices) &&
                        count($parsedServices) > 0 &&
                        !$application->settings->is_raw_compose_deployment_enabled)
                    @php
                        $hasNonDatabaseService = collect(data_get($parsedServices, 'services', []))
                            ->contains(fn($service) => !isDatabaseImage(data_get($service, 'image')));
                    @endphp
                    @if ($hasNonDatabaseService)
                        <div class="flex flex-col gap-4">
                            @foreach (data_get($parsedServices, 'services') as $serviceName => $service)
                                @if (!isDatabaseImage(data_get($service, 'image')))
                                    <div wire:key="compose-domain-{{ str($serviceName)->slug() }}"
                                        class="flex flex-col gap-2 sm:flex-row sm:items-end">
                                        <x-forms.input
                                            helper="You can specify one domain with path or more with comma. You can specify a port to bind the domain to.<br><br><span class='text-helper'>Example</span><br>- https://app.coolify.io,https://cloud.coolify.io/dashboard<br>- https://app.coolify.io/api/v3<br>- https://app.coolify.io:3000 -> app.coolify.io will point to port 3000 inside the container.<br>- https://app.coolify.io:8080/api -> app.coolify.io/api will point to port 8080 inside the container."
                                            label="{{ $serviceName }}"
                                            id="parsedServiceDomains.{{ str($serviceName)->replace('-', '_')->replace('.', '_') }}.domain"
                                            x-bind:disabled="shouldDisable()"></x-forms.input>
                                        @can('update', $application)
                                            <x-forms.button wire:click="generateDomain('{{ $serviceName }}')">
                                                Generate domain
                                            </x-forms.button>
                                        @endcan
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @else
                        <p class="text-sm text-neutral-500 dark:text-fg-dim">No public services were detected in this compose file.</p>
                    @endif
                @else
                    <p class="text-sm text-neutral-500 dark:text-fg-dim">Domains are managed directly in the raw compose file.</p>
                @endif
            @endif
            @if ($buildPack !== 'dockercompose')
                @if ($application->settings->is_container_label_readonly_enabled == false)
                    <x-empty size="sm" title="Public access is managed through labels"
                        description="Container labels are managed manually for this application. Switch label management back to Coolify to edit them here.">
                        <x-slot:icon>
                            <x-reicon name="globe" class="size-8" />
                        </x-slot:icon>
                        <x-slot:contents>
                            <button type="button" class="button"
                                @click="document.getElementById('container-labels-section')?.scrollIntoView({ behavior: 'smooth', block: 'start' })">
                                Go to Container labels
                            </button>
                        </x-slot:contents>
                    </x-empty>
                @else
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                        <div class="w-full">
                            <label class="flex w-fit items-center gap-1.5">
                                URLs
                                <x-helper
                                    helper="Add one domain per entry — press Enter (or type a comma) to add it. You can specify a path and a port to bind the domain to.<br><br><span class='text-helper'>Example</span><br>- https://app.coolify.io/api/v3<br>- https://app.coolify.io:3000 -> app.coolify.io will point to port 3000 inside the container.<br>- https://app.coolify.io:8080/api -> app.coolify.io/api will point to port 8080 inside the container." />
                            </label>
                            <div class="chip-input" x-data="{
                                raw: @entangle('fqdn'),
                                entry: '',
                                get domains() {
                                    return (this.raw ?? '').split(',').map((domain) => domain.trim()).filter(Boolean);
                                },
                                commit(list) {
                                    this.raw = list.join(',');
                                },
                                addValue(value) {
                                    value = value.trim().replace(/,+$/, '');
                                    if (!value) return;
                                    const list = this.domains;
                                    if (!list.includes(value)) {
                                        list.push(value);
                                        this.commit(list);
                                    }
                                },
                                add() {
                                    const value = this.entry;
                                    this.entry = '';
                                    this.addValue(value);
                                },
                                onInput() {
                                    if (this.entry.includes(',')) {
                                        const parts = this.entry.split(',');
                                        this.entry = parts.pop().trim();
                                        parts.forEach((part) => this.addValue(part));
                                    }
                                },
                                remove(index) {
                                    const list = this.domains;
                                    list.splice(index, 1);
                                    this.commit(list);
                                },
                                onKeydown(event) {
                                    if (event.key === ',') {
                                        event.preventDefault();
                                        this.add();
                                    } else if (event.key === 'Backspace' && this.entry === '') {
                                        this.remove(this.domains.length - 1);
                                    }
                                }
                            }" @click="$refs.domainEntry.focus()">
                                <template x-for="(domain, index) in domains" :key="domain">
                                    <span class="chip">
                                        <span x-text="domain"></span>
                                        <button type="button" class="chip-remove" x-show="canUpdate"
                                            :aria-label="'Remove ' + domain" @click.stop="remove(index)">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                                stroke-width="2" stroke="currentColor" class="size-3">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </span>
                                </template>
                                <input x-ref="domainEntry" x-model="entry"
                                    :placeholder="domains.length === 0 ? 'https://coolify.io' : ''"
                                    autocomplete="off" spellcheck="false" @input="onInput()"
                                    @keydown.enter.prevent.stop="add()" x-on:keydown="onKeydown($event)"
                                    @blur="add()" x-bind:disabled="!canUpdate" />
                            </div>
                        </div>
                        @can('update', $application)
                            <x-forms.button wire:click="getWildcardDomain">Generate domain</x-forms.button>
                        @endcan
                    </div>
                    <div class="application-settings-domain-direction application-direction-options mt-5">
                        <div class="grid w-full gap-4 sm:grid-cols-2">
                            <x-forms.listbox id="redirect" label="Domain redirection" :options="[
                                ['value' => 'www', 'label' => 'Redirect non-www to www'],
                                ['value' => 'non-www', 'label' => 'Redirect www to non-www'],
                                ['value' => 'both', 'label' => 'Allow www & non-www'],
                            ]" x-bind:disabled="!canUpdate" />
                            <x-forms.listbox :wire="false" value="https" label="Protocol redirection" :options="[
                                ['value' => 'https', 'label' => 'Redirect HTTP to HTTPS'],
                                ['value' => 'none', 'label' => 'Allow HTTP & HTTPS'],
                            ]" x-bind:disabled="!canUpdate" />
                        </div>
                    </div>
                @endif
            @endif
            </x-application.settings-section>

            <x-application.settings-section id="build-pipeline-section" title="Build pipeline" helper="Commands, directories and options used while building the application.">
            @if (!$application->dockerfile && $application->build_pack !== 'dockerimage')
                <div class="application-build-pack-options mb-5 border-b border-neutral-200 pb-5 dark:border-white/[0.07]">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-forms.listbox id="buildPack" label="Build strategy" live :options="[
                            ['value' => 'nixpacks', 'label' => 'Nixpacks'],
                            ['value' => 'railpack', 'label' => 'Railpack (beta)'],
                            ['value' => 'static', 'label' => 'Static'],
                            ['value' => 'dockerfile', 'label' => 'Dockerfile'],
                            ['value' => 'dockercompose', 'label' => 'Compose'],
                        ]" x-bind:disabled="shouldDisable()" />
                        @if ($isStatic || $buildPack === 'static')
                            <x-forms.listbox id="staticImage" label="Web server" required :options="[
                                ['value' => 'nginx:alpine', 'label' => 'nginx:alpine'],
                                ['value' => 'apache:alpine', 'label' => 'apache:alpine', 'disabled' => true],
                            ]" x-bind:disabled="!canUpdate" />
                        @endif
                    </div>
                </div>
            @endif
            @if ($application->could_set_build_commands() || ($isStatic && $buildPack !== 'static'))
                <div class="mb-5 w-full border-b border-neutral-200 pb-5 dark:border-white/[0.07]">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-forms.listbox id="siteType" label="Site type" onChange="setSiteType" :options="[
                            ['value' => 'dynamic', 'label' => 'Dynamic'],
                            ['value' => 'static', 'label' => 'Static'],
                            ['value' => 'spa', 'label' => 'SPA (single-page application)'],
                        ]"
                            helper="Static: the final build assets are served as a static site. SPA: a static site with single-page-app routing."
                            x-bind:disabled="!canUpdate" />
                    </div>
                </div>
            @endif
            <div class="flex flex-col gap-5">
                @if ($application->build_pack === 'dockerimage')
                    <p class="text-sm text-neutral-500 dark:text-fg-dim">Nothing to build — this application deploys a prebuilt Docker image.</p>
                @else
                    <div class="flex flex-col gap-5">
                        @if ($buildPack === 'dockercompose')
                            <div class="flex flex-col gap-2"
                                @can('update', $application) x-init="$wire.dispatch('loadCompose', true)" @endcan>
                                <div x-data="{
                                    baseDir: @entangle('baseDirectory'),
                                    composeLocation: @entangle('dockerComposeLocation'),
                                    normalizePath(path) {
                                        if (!path || path.trim() === '') return '/';
                                        path = path.trim();
                                        path = path.replace(/\/+$/, '');
                                        if (!path.startsWith('/')) {
                                            path = '/' + path;
                                        }
                                        return path;
                                    },
                                    normalizeBaseDir() {
                                        this.baseDir = this.normalizePath(this.baseDir);
                                    },
                                    normalizeComposeLocation() {
                                        this.composeLocation = this.normalizePath(this.composeLocation);
                                    }
                                }" class="grid gap-4 lg:grid-cols-2">
                                    <x-forms.input x-bind:disabled="shouldDisable()" placeholder="/"
                                        label="Base directory"
                                        helper="Directory to use as root. Useful for monorepos." x-model="baseDir"
                                        @blur="normalizeBaseDir()" />
                                    <x-forms.input x-bind:disabled="shouldDisable()"
                                        placeholder="/docker-compose.yaml"
                                        label="Docker compose location"
                                        helper="It is calculated together with the Base Directory:<br><span class='dark:text-warning'>{{ Str::start($baseDirectory . $dockerComposeLocation, '/') }}</span>"
                                        x-model="composeLocation" @blur="normalizeComposeLocation()" />
                                </div>
                                <div class="w-full sm:w-96">
                                    <x-forms.checkbox instantSave id="isPreserveRepositoryEnabled"
                                        label="Preserve repository during deployment"
                                        helper="Git repository (based on the base directory settings) will be copied to the deployment directory."
                                        x-bind:disabled="shouldDisable()" />
                                </div>
                                <div class="pt-4">The following commands are for advanced use cases.
                                    Only
                                    modify them if you
                                    know what are
                                    you doing.</div>
                                <div class="grid gap-4 lg:grid-cols-2">
                                    <x-forms.input x-bind:disabled="shouldDisable()"
                                        placeholder="docker compose build" id="dockerComposeCustomBuildCommand"
                                        helper="The compose file path (<span class='dark:text-warning'>-f</span> flag) and environment variables (<span class='dark:text-warning'>--env-file</span> flag) are automatically injected based on your Base Directory and Docker Compose Location settings. You can override by providing your own <span class='dark:text-warning'>-f</span> or <span class='dark:text-warning'>--env-file</span> flags.<br><br>If you use this, you need to specify paths relatively and should use the same compose file in the custom command, otherwise the automatically configured labels / etc won't work.<br><br>Example usage: <span class='dark:text-warning'>docker compose build</span>"
                                        label="Custom build command" />
                                    <x-forms.input x-bind:disabled="shouldDisable()"
                                        placeholder="docker compose up -d" id="dockerComposeCustomStartCommand"
                                        helper="The compose file path (<span class='dark:text-warning'>-f</span> flag) and environment variables (<span class='dark:text-warning'>--env-file</span> flag) are automatically injected based on your Base Directory and Docker Compose Location settings. You can override by providing your own <span class='dark:text-warning'>-f</span> or <span class='dark:text-warning'>--env-file</span> flags.<br><br>If you use this, you need to specify paths relatively and should use the same compose file in the custom command, otherwise the automatically configured labels / etc won't work.<br><br>Example usage: <span class='dark:text-warning'>docker compose up -d</span>"
                                        label="Custom start command" />
                                </div>
                                @if ($this->dockerComposeCustomBuildCommand)
                                    <div wire:key="docker-compose-build-preview">
                                        <x-forms.input readonly value="{{ $this->dockerComposeBuildCommandPreview }}"
                                            label="Final build command (preview)"
                                            helper="This shows the actual command that will be executed with auto-injected flags." />
                                    </div>
                                @endif
                                @if ($this->dockerComposeCustomStartCommand)
                                    <div wire:key="docker-compose-start-preview">
                                        <x-forms.input readonly value="{{ $this->dockerComposeStartCommandPreview }}"
                                            label="Final start command (preview)"
                                            helper="This shows the actual command that will be executed with auto-injected flags." />
                                    </div>
                                @endif
                                @if ($this->application->is_github_based() && !$this->application->is_public_repository())
                                    <div class="pt-4">
                                        <x-forms.textarea
                                            helper="Order-based pattern matching to filter Git webhook deployments. Supports wildcards (*, **, ?) and negation (!). Last matching pattern wins."
                                            placeholder="services/api/**" id="watchPaths" label="Watch paths"
                                            x-bind:disabled="shouldDisable()" />
                                    </div>
                                @endif
                            </div>
                        @else
                            <div x-data="{
                                baseDir: @entangle('baseDirectory'),
                                dockerfileLocation: @entangle('dockerfileLocation'),
                                normalizePath(path) {
                                    if (!path || path.trim() === '') return '/';
                                    path = path.trim();
                                    path = path.replace(/\/+$/, '');
                                    if (!path.startsWith('/')) {
                                        path = '/' + path;
                                    }
                                    return path;
                                },
                                normalizeBaseDir() {
                                    this.baseDir = this.normalizePath(this.baseDir);
                                },
                                normalizeDockerfileLocation() {
                                    this.dockerfileLocation = this.normalizePath(this.dockerfileLocation);
                                }
                            }" class="grid gap-4 lg:grid-cols-2">
                                <x-forms.input placeholder="/"
                                    label="Base directory" helper="Directory to use as root. Useful for monorepos."
                                    x-bind:disabled="!canUpdate" x-model="baseDir" @blur="normalizeBaseDir()" />
                                @if ($buildPack === 'dockerfile' && !$application->dockerfile)
                                    <x-forms.input placeholder="/Dockerfile"
                                        label="Dockerfile location"
                                        helper="It is calculated together with the Base Directory:<br><span class='dark:text-warning'>{{ Str::start($application->base_directory . $application->dockerfile_location, '/') }}</span>"
                                        x-bind:disabled="!canUpdate" x-model="dockerfileLocation"
                                        @blur="normalizeDockerfileLocation()" />
                                @endif

                                @if ($buildPack === 'dockerfile')
                                    <x-forms.input id="dockerfileTargetBuild" label="Docker build stage target"
                                        helper="Useful if you have multi-staged dockerfile."
                                        x-bind:disabled="!canUpdate" />
                                @endif
                                @if ($application->could_set_build_commands())
                                    @if ($application->settings->is_static)
                                        <x-forms.input placeholder="/dist" id="publishDirectory"
                                            label="Publish directory" required x-bind:disabled="!canUpdate" />
                                    @else
                                        <x-forms.input placeholder="/" id="publishDirectory"
                                            label="Publish directory" x-bind:disabled="!canUpdate" />
                                    @endif
                                @endif

                            </div>
                            @if ($this->application->is_github_based() && !$this->application->is_public_repository())
                                <div class="pb-4">
                                    <x-forms.textarea
                                        helper="Order-based pattern matching to filter Git webhook deployments. Supports wildcards (*, **, ?) and negation (!). Last matching pattern wins."
                                        placeholder="src/pages/**" id="watchPaths" label="Watch paths"
                                        x-bind:disabled="!canUpdate" />
                                </div>
                            @endif
                            @if ($application->could_set_build_commands() && ($buildPack === 'nixpacks' || $buildPack === 'railpack'))
                                <div class="grid gap-4 lg:grid-cols-3">
                                    <x-forms.input helper="If you modify this, you probably need to have a {{ $buildPack === 'railpack' ? 'railpack.json' : 'nixpacks.toml' }}"
                                        id="installCommand" label="Install command" x-bind:disabled="!canUpdate" />
                                    <x-forms.input helper="If you modify this, you probably need to have a {{ $buildPack === 'railpack' ? 'railpack.json' : 'nixpacks.toml' }}"
                                        id="buildCommand" label="Build command" x-bind:disabled="!canUpdate" />
                                    <x-forms.input helper="If you modify this, you probably need to have a {{ $buildPack === 'railpack' ? 'railpack.json' : 'nixpacks.toml' }}"
                                        id="startCommand" label="Start command" x-bind:disabled="!canUpdate" />
                                </div>
                            @endif
                            @if ($buildPack !== 'dockercompose')
                                @php
                                    $hasBuildServers = \App\Models\Server::buildServers(currentTeam()->id)->exists();
                                    $buildServerOptions = [
                                        ['value' => false, 'label' => 'Deployment server'],
                                        $hasBuildServers
                                            ? ['value' => true, 'label' => 'Available build server (auto-select)']
                                            : ['value' => true, 'label' => 'No build servers connected', 'disabled' => true],
                                    ];
                                @endphp
                                <div class="grid gap-4 pt-2 sm:grid-cols-2">
                                    <x-forms.listbox id="isBuildServerEnabled" label="Builder selection"
                                        onChange="instantSave" :options="$buildServerOptions"
                                        helper="Build your application on a dedicated build server. If several build servers are connected, Coolify picks an available one automatically. More info in the <a href='https://coolify.io/docs/knowledge-base/server/build-server' class='underline' target='_blank'>documentation</a>."
                                        x-bind:disabled="!canUpdate" />
                                </div>
                            @endif
                        @endif
                    </div>
                @endif
            </div>
            @if ($isStatic || $buildPack === 'static')
                <div class="mt-5 border-t border-neutral-200 pt-5 dark:border-white/[0.07]">
                    <div class="mb-1.5 flex items-center justify-between gap-3">
                        <label class="flex w-fit items-center gap-1.5" style="margin-bottom: 0">
                            Custom Nginx configuration
                            <x-helper helper="You can add custom Nginx configuration here." />
                        </label>
                        @can('update', $application)
                            <x-modal-confirmation title="Confirm Nginx Configuration Generation?"
                                buttonTitle="Generate default"
                                submitAction="generateNginxConfiguration('{{ $application->settings->is_spa ? 'spa' : 'static' }}')"
                                :actions="[
                                    'This will overwrite your current custom Nginx configuration.',
                                    'The default configuration will be generated based on your application type (' .
                                    ($application->settings->is_spa ? 'SPA' : 'static') .
                                    ').',
                                ]" />
                        @endcan
                    </div>
                    <x-forms.textarea id="customNginxConfiguration"
                        placeholder="Empty means default configuration will be used." rows="10"
                        monacoEditorLanguage="nginx" useMonacoEditor x-bind:disabled="!canUpdate" />
                </div>
            @endif
            @if ($buildPack === 'dockercompose')
                <div x-data="{ showRaw: true }">
                    <div class="flex items-center gap-2">
                        <h3>Docker Compose</h3>
                        <x-forms.button x-show="{{ $application->settings->is_raw_compose_deployment_enabled ? 'false' : 'true' }}"
                            @click.prevent="showRaw = !showRaw"
                            x-text="showRaw ? 'Show deployable compose' : 'Show raw compose'"></x-forms.button>
                    </div>
                    @if ($application->settings->is_raw_compose_deployment_enabled)
                        <x-forms.textarea rows="10" readonly id="dockerComposeRaw"
                            label="Docker compose content (applicationId: {{ $application->id }})"
                            helper="You need to modify the docker compose file in the git repository."
                            monacoEditorLanguage="yaml" useMonacoEditor />
                    @else
                        @if ((int) $application->compose_parsing_version >= 3)
                            <div x-show="showRaw">
                                <x-forms.textarea rows="10" readonly id="dockerComposeRaw"
                                    label="Docker compose content (raw)"
                                    helper="You need to modify the docker compose file in the git repository."
                                    monacoEditorLanguage="yaml" useMonacoEditor />
                            </div>
                        @endif
                        <div x-show="showRaw === false">
                            <x-forms.textarea rows="10" readonly id="dockerCompose"
                                label="Docker compose content"
                                helper="You need to modify the docker compose file in the git repository."
                                monacoEditorLanguage="yaml" useMonacoEditor />
                        </div>
                    @endif
                    <div class="w-full sm:w-96">
                        <x-forms.checkbox label="Escape special characters in labels?"
                            helper="By default, $ (and other chars) is escaped. So if you write $ in the labels, it will be saved as $$.<br><br>If you want to use env variables inside the labels, turn this off."
                            id="isContainerLabelEscapeEnabled" instantSave
                            x-bind:disabled="!canUpdate"></x-forms.checkbox>
                        {{-- <x-forms.checkbox label="Readonly labels"
                            helper="Labels are readonly by default. Readonly means that edits you do to the labels could be lost and Coolify will autogenerate the labels for you. If you want to edit the labels directly, disable this option. <br><br>Be careful, it could break the proxy configuration after you restart the container as Coolify will now NOT autogenerate the labels for you (ofc you can always reset the labels to the coolify defaults manually)."
                            id="isContainerLabelReadonlyEnabled" instantSave></x-forms.checkbox> --}}
                    </div>
                </div>
            @endif
            @if ($application->dockerfile)
                <x-forms.textarea label="Dockerfile" id="dockerfile" monacoEditorLanguage="dockerfile"
                    useMonacoEditor rows="6" x-bind:disabled="!canUpdate"> </x-forms.textarea>
            @endif
            </x-application.settings-section>
            @if ($buildPack !== 'dockercompose')
                <x-application.settings-section id="container-image-section" title="Container image" helper="Configure the Docker image used for this application and where the built image is pushed.">
                @if ($application->destination->server->isSwarm())
                    @if ($application->build_pack !== 'dockerimage')
                        <div>Docker Swarm requires the image to be available in a registry. More info <a
                                class="underline" href="https://coolify.io/docs/knowledge-base/docker/registry"
                                target="_blank">here</a>.</div>
                    @endif
                @endif
                <div class="grid gap-4 lg:grid-cols-2">
                    @if ($application->build_pack === 'dockerimage')
                        @if ($application->destination->server->isSwarm())
                            <x-forms.input required id="dockerRegistryImageName" label="Image" placeholder="nginx"
                                x-bind:disabled="!canUpdate" />
                            <x-forms.input id="dockerRegistryImageTag" label="Tag" placeholder="alpine"
                                helper="Enter a tag (e.g., 'latest', 'v1.2.3') or SHA256 hash (e.g., 'sha256-59e02939b1bf39f16c93138a28727aec520bb916da021180ae502c61626b3cf0')"
                                x-bind:disabled="!canUpdate" />
                        @else
                            <x-forms.input id="dockerRegistryImageName" label="Image" placeholder="nginx"
                                x-bind:disabled="!canUpdate" />
                            <x-forms.input id="dockerRegistryImageTag" label="Tag" placeholder="alpine"
                                helper="Enter a tag (e.g., 'latest', 'v1.2.3') or SHA256 hash (e.g., 'sha256-59e02939b1bf39f16c93138a28727aec520bb916da021180ae502c61626b3cf0')"
                                x-bind:disabled="!canUpdate" />
                        @endif
                    @else
                        @if (
                            $application->destination->server->isSwarm() ||
                                $application->additional_servers->count() > 0 ||
                                $application->settings->is_build_server_enabled)
                            <x-forms.input id="dockerRegistryImageName" required label="Image"
                                placeholder="ghcr.io/your-org/your-app" x-bind:disabled="!canUpdate" />
                            <x-forms.input id="dockerRegistryImageTag"
                                helper="If set, it will tag the built image with this tag too. <br><br>Example: If you set it to 'latest', it will push the image with the commit sha tag + with the latest tag."
                                placeholder="latest" label="Tag"
                                x-bind:disabled="!canUpdate" />
                        @else
                            <x-forms.input id="dockerRegistryImageName"
                                helper="Empty means it won't push the image to a docker registry. Pre-tag the image with your registry url if you want to push it to a private registry (default: Dockerhub). <br><br>Example: ghcr.io/myimage"
                                placeholder="ghcr.io/your-org/your-app"
                                label="Image" x-bind:disabled="!canUpdate" />
                            <x-forms.input id="dockerRegistryImageTag"
                                placeholder="latest"
                                helper="If set, it will tag the built image with this tag too. <br><br>Example: If you set it to 'latest', it will push the image with the commit sha tag + with the latest tag."
                                label="Tag" x-bind:disabled="!canUpdate" />
                        @endif
                    @endif
                </div>
                </x-application.settings-section>
            @endif

            @if ($buildPack !== 'dockercompose')
                <x-application.settings-section id="networking-section" title="Networking" helper="Ports the container exposes, host port mappings and internal network aliases.">
                @if ($this->detectedPortInfo)
                    @if ($this->detectedPortInfo['isEmpty'])
                        <div
                            class="flex items-start gap-2 p-4 mb-4 text-sm rounded-lg bg-warning-50 dark:bg-warning-900/20 text-warning-800 dark:text-warning-300 border border-warning-200 dark:border-warning-800">
                            <svg class="w-5 h-5 shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                    d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z"
                                    clip-rule="evenodd" />
                            </svg>
                            <div>
                                <span class="font-semibold">PORT environment variable detected
                                    ({{ $this->detectedPortInfo['port'] }})</span>
                                <p class="mt-1">Your Ports Exposes field is empty. Consider setting it to
                                    <strong>{{ $this->detectedPortInfo['port'] }}</strong> to ensure the proxy routes
                                    traffic
                                    correctly.
                                </p>
                            </div>
                        </div>
                    @elseif (!$this->detectedPortInfo['matches'])
                        <div
                            class="flex items-start gap-2 p-4 mb-4 text-sm rounded-lg bg-warning-50 dark:bg-warning-900/20 text-warning-800 dark:text-warning-300 border border-warning-200 dark:border-warning-800">
                            <svg class="w-5 h-5 shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                    d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z"
                                    clip-rule="evenodd" />
                            </svg>
                            <div>
                                <span class="font-semibold">PORT mismatch detected</span>
                                <p class="mt-1">Your PORT environment variable is set to
                                    <strong>{{ $this->detectedPortInfo['port'] }}</strong>, but it's not in your Ports
                                    Exposes
                                    configuration. Ensure they match for proper proxy routing.
                                </p>
                            </div>
                        </div>
                    @else
                        <div
                            class="flex items-start gap-2 p-4 mb-4 text-sm rounded-lg bg-blue-50 dark:bg-blue-900/20 text-blue-800 dark:text-blue-300 border border-blue-200 dark:border-blue-800">
                            <svg class="w-5 h-5 shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                    d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a.75.75 0 000 1.5h.253a.25.25 0 01.244.304l-.459 2.066A1.75 1.75 0 0010.747 15H11a.75.75 0 000-1.5h-.253a.25.25 0 01-.244-.304l.459-2.066A1.75 1.75 0 009.253 9H9z"
                                    clip-rule="evenodd" />
                            </svg>
                            <div>
                                <span class="font-semibold">PORT environment variable configured</span>
                                <p class="mt-1">Your PORT environment variable
                                    ({{ $this->detectedPortInfo['port'] }}) matches
                                    your Ports Exposes configuration.</p>
                            </div>
                        </div>
                    @endif
                @endif
                @if ((empty($portsExposes) || $portsExposes === '0') && !empty($fqdn))
                    <x-callout type="info" title="No ports exposed" class="mb-4">
                        This application does not expose any ports and will not be reachable through the proxy or your domains.
                        This behavior is normal for background workers, bots, or scheduled tasks.
                        If your application needs to handle HTTP traffic, please specify the port(s) it listens on.
                    </x-callout>
                @endif
                <div class="grid gap-4 lg:grid-cols-[14rem_16rem_minmax(0,1fr)]">
                    @if ($isStatic || $buildPack === 'static')
                        <x-forms.input id="portsExposes" label="Ports exposes" readonly
                            x-bind:disabled="!canUpdate" />
                    @else
                        @if ($application->settings->is_container_label_readonly_enabled === false)
                            <x-forms.input placeholder="3000,3001" id="portsExposes" label="Ports exposes" readonly
                                helper="Readonly labels are disabled. You can set the ports manually in the labels section."
                                x-bind:disabled="!canUpdate" />
                        @else
                            <x-forms.input placeholder="3000,3001" id="portsExposes" label="Ports exposes"
                                helper="A comma separated list of ports your application uses. The first port will be used as default healthcheck port if nothing defined in the Healthcheck menu. Be sure to set this correctly."
                                x-bind:disabled="!canUpdate" />
                        @endif
                    @endif
                    @if (!$application->destination->server->isSwarm())
                        <x-forms.input placeholder="3000:3000" id="portsMappings" label="Port mappings"
                            helper="A comma separated list of ports you would like to map to the host system. Useful when you do not want to use domains.<br><br><span class='inline-block font-bold dark:text-warning'>Format:</span> host:container<br><br><span class='inline-block font-bold dark:text-warning'>Example:</span> 3000:3000,3002:3002<br><br>Rolling update is not supported if you have a port mapped to the host."
                            x-bind:disabled="!canUpdate" />
                    @endif
                    @if (!$application->destination->server->isSwarm())
                        <x-forms.input id="customNetworkAliases" label="Network aliases"
                            helper="A comma separated list of custom network aliases you would like to add for container in Docker network.<br><br><span class='inline-block font-bold dark:text-warning'>Example:</span><br>api.internal,api.local"
                            wire:model="customNetworkAliases" x-bind:disabled="!canUpdate" />
                    @endif
                </div>
                </x-application.settings-section>

                <x-application.settings-section id="runtime-section" title="Runtime" helper="Options applied to the container when it starts.">
                    <x-forms.input
                        helper="You can add custom docker run options that will be used when your container is started.<br>Note: Not all options are supported, as they could mess up Coolify's automation and could cause bad experience for users.<br><br>Check the <a class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/docker/custom-commands'>docs.</a>"
                        placeholder="--cap-add SYS_ADMIN --device=/dev/fuse --security-opt apparmor:unconfined --ulimit nofile=1024:1024 --tmpfs /run:rw,noexec,nosuid,size=65536k --hostname=myapp"
                        id="customDockerRunOptions" label="Custom Docker options" x-bind:disabled="!canUpdate" />
                </x-application.settings-section>

                <x-application.settings-section id="security-section" title="Security" helper="Protect this application with authentication at the proxy level.">
                    @if ($application->settings->is_container_label_readonly_enabled == false)
                    <x-empty size="sm" title="Authentication is managed through labels"
                        description="Authentication is managed via manual proxy labels. Switch label management back to Coolify to configure it here.">
                        <x-slot:icon>
                            <x-reicon name="admin" class="size-8" />
                        </x-slot:icon>
                        <x-slot:contents>
                            <button type="button" class="button"
                                @click="document.getElementById('container-labels-section')?.scrollIntoView({ behavior: 'smooth', block: 'start' })">
                                Go to Container labels
                            </button>
                        </x-slot:contents>
                    </x-empty>
                    @else
                    <div class="grid w-full items-end gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]">
                        <x-forms.listbox id="isHttpBasicAuthEnabled" label="Authentication" onChange="instantSave"
                            helper="HTTP Basic Authentication adds the required authentication labels to the proxy."
                            :options="[
                                ['value' => false, 'label' => 'None'],
                                ['value' => true, 'label' => 'HTTP Basic Authentication'],
                            ]" x-bind:disabled="!canUpdate" />
                        <div class="hidden sm:block"></div>
                        <button type="button" class="button invisible hidden sm:inline-flex" tabindex="-1"
                            aria-hidden="true">Remove user</button>
                    </div>
                    @if ($application->is_http_basic_auth_enabled)
                        <div class="mt-5 border-t border-neutral-200 pt-5 dark:border-white/[0.07]"
                            x-data="{ extraCredentials: [] }">
                            <div class="grid w-full items-end gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]">
                                <x-forms.input id="httpBasicAuthUsername" label="Username" required
                                    x-bind:disabled="!canUpdate" />
                                <x-forms.input id="httpBasicAuthPassword" type="password" label="Password" required
                                    x-bind:disabled="!canUpdate" />
                                <button type="button" class="button" disabled
                                    title="The default user cannot be removed">
                                    Remove user
                                </button>
                            </div>
                            <template x-for="(credential, index) in extraCredentials" :key="index">
                                <div class="mt-4 grid w-full items-end gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]">
                                    <div>
                                        <label class="flex items-center gap-1">Username</label>
                                        <input class="input" x-model="credential.username" autocomplete="off">
                                    </div>
                                    <div x-data="{ reveal: false }">
                                        <label class="flex items-center gap-1">Password</label>
                                        <div class="relative">
                                            <input class="input pr-10" :type="reveal ? 'text' : 'password'"
                                                x-model="credential.password" autocomplete="new-password">
                                            <button type="button"
                                                class="flex absolute inset-y-0 right-0 items-center pr-2 cursor-pointer dark:hover:text-white"
                                                aria-label="Toggle password visibility" @click="reveal = !reveal">
                                                <x-reicon name="eye" x-show="!reveal" class="size-[18px]" />
                                                <x-reicon name="eye-off" x-cloak x-show="reveal" class="size-[18px]" />
                                            </button>
                                        </div>
                                    </div>
                                    <button type="button" class="button" @click="extraCredentials.splice(index, 1)">
                                        Remove user
                                    </button>
                                </div>
                            </template>
                            @can('update', $application)
                                <button type="button" class="button mt-4"
                                    @click="extraCredentials.push({ username: '', password: '' })">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                        stroke-width="2" stroke="currentColor" class="size-4">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                    </svg>
                                    Add user
                                </button>
                            @endcan
                        </div>
                    @endif
                    @endif
                </x-application.settings-section>
            @endif

            <x-application.settings-section id="deployment-lifecycle-section" title="Deployment lifecycle" helper="Optional commands executed right before and after each deployment.">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="flex flex-col gap-4">
                    <x-forms.input x-bind:disabled="shouldDisable()" placeholder="php artisan migrate"
                        id="preDeploymentCommand" label="Pre-deployment"
                        helper="An optional script or command to execute in the existing container before the deployment begins.<br>It is always executed with 'sh -c', so you do not need add it manually." />
                    @if ($buildPack === 'dockercompose')
                        <x-forms.input x-bind:disabled="shouldDisable()" id="preDeploymentCommandContainer"
                            label="Container name"
                            helper="The name of the container to execute within. You can leave it blank if your application only has one container." />
                    @endif
                </div>
                <div class="flex flex-col gap-4">
                    <x-forms.input x-bind:disabled="shouldDisable()" placeholder="php artisan migrate"
                        id="postDeploymentCommand" label="Post-deployment"
                        helper="An optional script or command to execute in the newly built container after the deployment completes.<br>It is always executed with 'sh -c', so you do not need add it manually." />
                    @if ($buildPack === 'dockercompose')
                        <x-forms.input x-bind:disabled="shouldDisable()" id="postDeploymentCommandContainer"
                            label="Container name"
                            helper="The name of the container to execute within. You can leave it blank if your application only has one container." />
                    @endif
                </div>
            </div>
            </x-application.settings-section>

            @if ($buildPack !== 'dockercompose')
                <x-application.settings-section id="container-labels-section" title="Container labels" helper="Inspect or override the labels used by the proxy and runtime.">
                <div class="grid w-full gap-4 sm:grid-cols-2">
                    <x-forms.listbox id="isContainerLabelReadonlyEnabled" label="Label management"
                        onChange="instantSave"
                        helper="When Coolify manages the labels, they are regenerated automatically and manual edits can be lost.<br><br>If you edit them yourself, be careful: a wrong label set can break the proxy configuration after a restart (you can always reset to the Coolify defaults)."
                        :options="[
                            ['value' => true, 'label' => 'Managed by Coolify (auto-generated)'],
                            ['value' => false, 'label' => 'Managed manually (edit labels yourself)'],
                        ]" x-bind:disabled="!canUpdate" />
                    <x-forms.listbox id="isContainerLabelEscapeEnabled" label="Special characters"
                        onChange="instantSave"
                        helper="By default, $ (and other special characters) are escaped — writing $ saves it as $$.<br><br>Keep them unescaped if you want to use environment variables inside the labels."
                        :options="[
                            ['value' => true, 'label' => 'Escape special characters ($ becomes $$)'],
                            ['value' => false, 'label' => 'Keep unescaped (allow env variables)'],
                        ]" x-bind:disabled="!canUpdate" />
                </div>
                <div class="mt-5 border-t border-neutral-200 pt-5 dark:border-white/[0.07]">
                    <div class="mb-1.5 flex items-center justify-between gap-3">
                        <label class="flex w-fit items-center gap-1.5" style="margin-bottom: 0">Active labels</label>
                        @can('update', $application)
                            <x-modal-confirmation title="Confirm Labels Reset to Coolify Defaults?"
                                buttonTitle="Reset to defaults" submitAction="resetDefaultLabels(true)"
                                :actions="[
                                    'All your custom proxy labels will be lost.',
                                    'Proxy labels (traefik, caddy, etc) will be reset to the coolify defaults.',
                                ]" confirmationText="{{ $application->fqdn . '/' }}"
                                confirmationLabel="Please confirm the execution of the actions by entering the Application URL below"
                                shortConfirmationLabel="Application URL" :confirmWithPassword="false"
                                step2ButtonText="Permanently Reset Labels" />
                        @endcan
                    </div>
                    @if ($application->settings->is_container_label_readonly_enabled)
                        <x-forms.textarea readonly disabled rows="15" id="customLabels"
                            monacoEditorLanguage="ini" useMonacoEditor x-bind:disabled="!canUpdate"></x-forms.textarea>
                    @else
                        <x-forms.textarea rows="15" id="customLabels"
                            monacoEditorLanguage="ini" useMonacoEditor x-bind:disabled="!canUpdate"></x-forms.textarea>
                    @endif
                </div>
                </x-application.settings-section>
            @endif
        </div>
    </form>

    <x-domain-conflict-modal :conflicts="$domainConflicts" :showModal="$showDomainConflictModal" confirmAction="confirmDomainUsage" />

    @script
        <script>
            $wire.$on('loadCompose', (isInit = true) => {
                // Only load compose file if user has permission (this event should only be dispatched when authorized)
                $wire.initLoadingCompose = true;
                $wire.loadComposeFile(isInit);
            });
        </script>
    @endscript
</div>
