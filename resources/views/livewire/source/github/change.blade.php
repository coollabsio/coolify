<div>
    @if (data_get($github_app, 'app_id'))
        <form wire:submit='submit'>
            <div class="flex flex-col sm:flex-row sm:items-center gap-2">
                <h1>{{ __('security.github_app') }}</h1>
                <div class="flex gap-2">
                    @if (data_get($github_app, 'installation_id'))
                        <x-forms.button canGate="update" :canResource="$github_app" type="submit">{{ __('common.save') }}</x-forms.button>
                    @endif
                    @can('delete', $github_app)
                        @if ($applications->count() > 0)
                            <x-modal-confirmation title="{{ __('modal.confirm_github_app_deletion') }}" isErrorButton buttonTitle="{{ __('button.delete') }}"
                                submitAction="delete" :actions="[__('security.github_app_deletion_action')]" confirmationText="{{ data_get($github_app, 'name') }}"
                                confirmationLabel="{{ __('modal.github_app_name_confirmation') }}"
                                shortConfirmationLabel="{{ __('modal.github_app_name') }}" :confirmWithPassword="false"
                                step2ButtonText="{{ __('button.permanently_delete') }}" />
                        @else
                            <x-modal-confirmation title="{{ __('modal.confirm_github_app_deletion') }}" isErrorButton buttonTitle="{{ __('button.delete') }}"
                                submitAction="delete" :actions="[__('security.github_app_deletion_action')]"
                                confirmationLabel="{{ __('security.github_app_name_confirmation_label') }}"
                                shortConfirmationLabel="{{ __('modal.github_app_name') }}"
                                confirmationText="{{ data_get($github_app, 'name') }}" :confirmWithPassword="false"
                                step2ButtonText="{{ __('button.permanently_delete') }}" />
                        @endif
                    @endcan
                </div>
            </div>
            <div class="subtitle">{{ __('security.github_app_private_desc') }}</div>
            @if (!data_get($github_app, 'installation_id'))
                <div class="mb-10 rounded-sm alert-error">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 stroke-current shrink-0" fill="none"
                        viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <span>{{ __('security.complete_step_warning') }}</span>
                </div>
                <a class="items-center justify-center coolbox" href="{{ getInstallationPath($github_app) }}">
                    {{ __('security.install_repositories') }}
                </a>
            @else
                <div class="flex flex-col gap-2">
                    <div class="flex flex-col sm:flex-row gap-2">
                        <div class="flex flex-col sm:flex-row items-start sm:items-end gap-2 w-full">
                            <x-forms.input canGate="update" :canResource="$github_app" id="name" label="{{ __('security.app_name') }}" />
                            <x-forms.button canGate="update" :canResource="$github_app" wire:click.prevent="updateGithubAppName">
                                {{ __('common.sync_name') }}
                            </x-forms.button>
                            @can('update', $github_app)
                                <a href="{{ $this->getGithubAppNameUpdatePath() }}">
                                    <x-forms.button
                                        class="bg-transparent border-transparent hover:bg-transparent hover:border-transparent hover:underline">
                                        {{ __('security.rename') }}
                                        <x-external-link />
                                    </x-forms.button>
                                </a>
                                <a href="{{ getInstallationPath($github_app) }}" class="w-fit">
                                    <x-forms.button
                                        class="bg-transparent border-transparent hover:bg-transparent hover:border-transparent hover:underline whitespace-nowrap">
                                        {{ __('security.update_repositories') }}
                                        <x-external-link />
                                    </x-forms.button>
                                </a>
                            @endcan
                        </div>
                    </div>
                    <x-forms.input canGate="update" :canResource="$github_app" id="organization" label="{{ __('security.organization') }}"
                        placeholder="{{ __('forms.placeholders.github_user_hint') }}" />
                    @if (!isCloud())
                        <div class="w-48">
                            <x-forms.checkbox canGate="update" :canResource="$github_app" label="{{ __('security.system_wide') }}"
                                helper="{{ __('security.system_wide_hint') }}"
                                instantSave id="isSystemWide" />
                        </div>
                        @if ($isSystemWide)
                            <x-callout type="warning" title="{{ __('security.not_recommended') }}">
                                {{ __('security.system_wide_warning') }}
                            </x-callout>
                        @endif
                    @endif
                    <div class="flex flex-col sm:flex-row gap-2">
                        <x-forms.input canGate="update" :canResource="$github_app" id="htmlUrl" label="{{ __('security.html_url') }}" />
                        <x-forms.input canGate="update" :canResource="$github_app" id="apiUrl" label="{{ __('security.api_url') }}" />
                    </div>
                    <div class="flex flex-col sm:flex-row gap-2">
                        <x-forms.input canGate="update" :canResource="$github_app" id="customUser" label="{{ __('security.user') }}"
                            required />
                        <x-forms.input canGate="update" :canResource="$github_app" type="number" id="customPort"
                            label="{{ __('forms.port') }}" required />
                    </div>
                    <div class="flex flex-col sm:flex-row gap-2">
                        <x-forms.input canGate="update" :canResource="$github_app" type="number" id="appId"
                            label="{{ __('security.app_id') }}" required />
                        <x-forms.input canGate="update" :canResource="$github_app" type="number"
                            id="installationId" label="{{ __('security.installation_id') }}" required />
                    </div>
                    <div class="flex flex-col sm:flex-row gap-2">
                        <x-forms.input canGate="update" :canResource="$github_app" id="clientId" label="{{ __('security.client_id') }}"
                            type="password" required />
                        <x-forms.input canGate="update" :canResource="$github_app" id="clientSecret"
                            label="{{ __('security.client_secret') }}" type="password" required />
                        <x-forms.input canGate="update" :canResource="$github_app" id="webhookSecret"
                            label="{{ __('security.webhook_secret') }}" type="password" required />
                    </div>
                    <div class="flex gap-2">
                        <x-forms.select canGate="update" :canResource="$github_app" id="privateKeyId"
                            label="{{ __('security.private_key') }}" required>
                            @if (blank($github_app->private_key_id))
                                <option value="0" selected>{{ __('security.select_private_key') }}</option>
                            @endif
                            @foreach ($privateKeys as $privateKey)
                                <option value="{{ $privateKey->id }}">{{ $privateKey->name }}</option>
                            @endforeach
                        </x-forms.select>
                    </div>
                    <div class="flex flex-col sm:flex-row items-start sm:items-end gap-2">
                        <h2 class="pt-4">{{ __('security.permissions') }}</h2>
                        @can('view', $github_app)
                            <x-forms.button wire:click.prevent="checkPermissions">{{ __('common.refetch') }}</x-forms.button>
                            <a href="{{ getPermissionsPath($github_app) }}">
                                <x-forms.button>
                                    {{ __('common.update') }}
                                    <x-external-link />
                                </x-forms.button>
                            </a>
                        @endcan
                    </div>
                    <div class="flex flex-col sm:flex-row gap-2">
                        <x-forms.input id="contents" helper="{{ __('security.permission_read_mandatory') }}" label="{{ __('security.content') }}" readonly
                            placeholder="N/A" />
                        <x-forms.input id="metadata" helper="{{ __('security.permission_read_mandatory') }}" label="{{ __('security.metadata') }}" readonly
                            placeholder="N/A" />
                        {{-- <x-forms.input id="administration"
                            helper="{{ __('security.permission_read_write_runners') }}" label="{{ __('security.administration') }}"
                            readonly placeholder="N/A" /> --}}
                        <x-forms.input id="pullRequests"
                            helper="{{ __('security.permission_write_needed') }}"
                            label="{{ __('security.pull_request') }}" readonly placeholder="N/A" />
                    </div>
                </div>
            @endif
        </form>
        @if (data_get($github_app, 'installation_id'))
            <div class="w-full pt-10">
                <div class="h-full">
                    <div class="flex flex-col">
                        <div class="flex gap-2">
                            <h2>{{ __('security.resources') }}</h2>
                        </div>
                        <div class="pb-4 title">{{ __('security.resources_using_hint') }}</div>
                    </div>
                    @if ($applications->isEmpty())
                        <div class="py-4 text-sm opacity-70">
                            {{ __('security.no_resources_using') }}
                        </div>
                    @else
                        <div class="flex flex-col">
                            <div class="flex flex-col">
                                <div class="overflow-x-auto">
                                    <div class="inline-block min-w-full">
                                        <div class="overflow-hidden">
                                            <table class="min-w-full">
                                                <thead>
                                                    <tr>
                                                        <th class="px-5 py-3 text-xs font-medium text-left uppercase">
                                                            {{ __('security.table_project') }}
                                                        </th>
                                                        <th class="px-5 py-3 text-xs font-medium text-left uppercase">
                                                            {{ __('security.table_environment') }}</th>
                                                        <th class="px-5 py-3 text-xs font-medium text-left uppercase">{{ __('security.table_name') }}
                                                        </th>
                                                        <th class="px-5 py-3 text-xs font-medium text-left uppercase">{{ __('security.table_type') }}
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y">
                                                    @foreach ($applications->sortBy('name',SORT_NATURAL) as $resource)
                                                        <tr>
                                                            <td class="px-5 py-4 text-sm whitespace-nowrap">
                                                                {{ data_get($resource->project(), 'name') }}
                                                            </td>
                                                            <td class="px-5 py-4 text-sm whitespace-nowrap">
                                                                {{ data_get($resource, 'environment.name') }}
                                                            </td>
                                                            <td class="px-5 py-4 text-sm whitespace-nowrap"><a
                                                                    class=""
                                                                    {{ wireNavigate() }}
                                                                    href="{{ $resource->link() }}">{{ $resource->name }}
                                                                    <x-internal-link /></a>
                                                            </td>
                                                            <td class="px-5 py-4 text-sm whitespace-nowrap">
                                                                {{ str($resource->type())->headline() }}</td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        @endif
    @else
        <div class="flex flex-col sm:flex-row sm:items-center gap-2 pb-4">
            <h1>{{ __('security.github_app') }}</h1>
            <div class="flex gap-2">
                @can('delete', $github_app)
                    <x-modal-confirmation title="{{ __('modal.confirm_github_app_deletion') }}" isErrorButton buttonTitle="{{ __('button.delete') }}"
                        submitAction="delete" :actions="[__('security.github_app_deletion_action')]" confirmationText="{{ data_get($github_app, 'name') }}"
                        confirmationLabel="{{ __('security.github_app_name_confirmation_label') }}"
                        shortConfirmationLabel="{{ __('modal.github_app_name') }}" :confirmWithPassword="false"
                        step2ButtonText="{{ __('button.permanently_delete') }}" />
                @endcan
            </div>
        </div>
        <div class="flex flex-col gap-2">
            @can('create', $github_app)
                <h3>{{ __('security.manual_installation') }}</h3>
                <div class="flex gap-2 items-center">
                    {{ __('security.manual_installation_hint_text') }}
                    <x-forms.button wire:click.prevent="createGithubAppManually">
                        {{ __('common.continue') }}
                    </x-forms.button>
                </div>
                <h3>{{ __('security.automated_installation') }}</h3>
                <div class=" pb-5 rounded-sm alert-error">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 stroke-current shrink-0" fill="none"
                        viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <span>{{ __('security.complete_step_warning') }}</span>
                </div>
            @endcan
            <div class="flex flex-col">
                <div class="pb-10">
                    @can('create', $github_app)
                        @if (!isCloud() || isDev())
                            <div class="flex flex-col sm:flex-row items-start sm:items-end gap-2">
                                <x-forms.select wire:model.live='webhook_endpoint' label="{{ __('security.webhook_endpoint') }}"
                                    helper="{{ __('security.webhook_endpoint_hint') }}">
                                    @if ($ipv4)
                                        <option value="{{ $ipv4 }}">{{ __('security.use') }} {{ $ipv4 }}</option>
                                    @endif
                                    @if ($ipv6)
                                        <option value="{{ $ipv6 }}">{{ __('security.use') }} {{ $ipv6 }}</option>
                                    @endif
                                    @if ($fqdn)
                                        <option value="{{ $fqdn }}">{{ __('security.use') }} {{ $fqdn }}</option>
                                    @endif
                                    @if (config('app.url'))
                                        <option value="{{ config('app.url') }}">{{ __('security.use') }} {{ config('app.url') }}</option>
                                    @endif
                                </x-forms.select>
                                <x-forms.button isHighlighted
                                    x-on:click.prevent="createGithubApp('{{ $webhook_endpoint }}','{{ $preview_deployment_permissions }}',{{ $administration }})">
                                    {{ __('security.register_now') }}
                                </x-forms.button>
                            </div>
                        @else
                            <div class="flex flex-col sm:flex-row gap-2">
                                <h2>{{ __('security.register_github_app') }}</h2>
                                <x-forms.button isHighlighted
                                    x-on:click.prevent="createGithubApp('{{ $webhook_endpoint }}','{{ $preview_deployment_permissions }}',{{ $administration }})">
                                    {{ __('security.register_now') }}
                                </x-forms.button>
                            </div>
                            <div>{{ __('security.register_github_app_hint') }}</div>
                        @endif

                        <div class="flex flex-col gap-2 pt-4 w-96">
                            <x-forms.checkbox disabled id="default_permissions" label="{{ __('security.mandatory') }}"
                                helper="{{ __('security.mandatory_permissions') }}" />
                            <x-forms.checkbox id="preview_deployment_permissions" label="{{ __('security.preview_deployments') }} "
                                helper="{{ __('security.preview_deployments_hint') }}" />
                            {{-- <x-forms.checkbox id="administration" label="{{ __('security.administration') }} (for Github Runners)"
                            helper="{{ __('security.permission_read_write_runners') }}" /> --}}
                        </div>
                    @else
                        <x-callout type="danger" title="{{ __('security.insufficient_permissions') }}">
                            {{ __('security.insufficient_permissions_hint') }}
                        </x-callout>
                    @endcan
                </div>
            </div>
            <script>
                function createGithubApp(webhook_endpoint, preview_deployment_permissions, administration) {
                    const {
                        organization,
                        uuid,
                        html_url
                    } = @json($github_app);
                    if (!webhook_endpoint) {
                        alert('{{ __('security.please_select_webhook_endpoint') }}');
                        return;
                    }
                    let baseUrl = webhook_endpoint;
                    const name = @js($name);
                    const isDev = @js(config('app.env')) ===
                        'local';
                    const devWebhook = @js(config('constants.webhooks.dev_webhook'));
                    if (isDev && devWebhook) {
                        baseUrl = devWebhook;
                    }
                    const webhookBaseUrl = `${baseUrl}/webhooks`;
                    const path = organization ? `organizations/${organization}/settings/apps/new` : 'settings/apps/new';
                    const default_permissions = {
                        contents: 'read',
                        metadata: 'read',
                        emails: 'read',
                        administration: 'read'
                    };
                    const default_events = ['push'];
                    if (preview_deployment_permissions) {
                        default_permissions.pull_requests = 'write';
                        default_events.push('pull_request');
                    }
                    if (administration) {
                        default_permissions.administration = 'write';
                    }

                    const data = {
                        name,
                        url: baseUrl,
                        hook_attributes: {
                            url: `${webhookBaseUrl}/source/github/events`,
                            active: true,
                        },
                        redirect_url: `${webhookBaseUrl}/source/github/redirect`,
                        callback_urls: [`${baseUrl}/login/github/app`],
                        public: false,
                        request_oauth_on_install: false,
                        setup_url: `${webhookBaseUrl}/source/github/install?source=${uuid}`,
                        setup_on_update: true,
                        default_permissions,
                        default_events
                    };
                    const form = document.createElement('form');
                    form.setAttribute('method', 'post');
                    form.setAttribute('action', `${html_url}/${path}?state=${uuid}`);
                    const input = document.createElement('input');
                    input.setAttribute('id', 'manifest');
                    input.setAttribute('name', 'manifest');
                    input.setAttribute('type', 'hidden');
                    input.setAttribute('value', JSON.stringify(data));
                    form.appendChild(input);
                    document.getElementsByTagName('body')[0].appendChild(form);
                    form.submit();
                }
            </script>
    @endif
</div>
