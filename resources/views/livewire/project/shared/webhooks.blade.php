<div class="flex flex-col gap-2">
    <div class="flex items-center gap-2">
        <h2>{{ __('webhooks.title') }}</h2>
        <x-helper
            helper="{{ __('webhooks.helper_docs') }}" />
    </div>
    <div>
        <x-forms.input readonly
            helper="{{ __('webhooks.deploy_webhook_helper') }}"
            label="{{ __('webhooks.deploy_webhook_label') }}" id="deploywebhook"></x-forms.input>
    </div>
    @if ($resource->type() === 'application')
        <div>
            <h3>{{ __('webhooks.manual_git_webhooks') }}</h3>
            @if ($githubManualWebhook && $gitlabManualWebhook)
                <form wire:submit='submit' class="flex flex-col gap-2">
                    <div class="flex items-end gap-2">
                        <x-forms.input helper="{{ __('webhooks.github_content_type_helper') }}"
                            readonly label="{{ __('webhooks.github_label') }}" id="githubManualWebhook"></x-forms.input>
                        @can('update', $resource)
                            <x-forms.input type="password"
                                helper="{{ __('webhooks.github_secret_helper') }}"
                                label="{{ __('webhooks.github_secret_label') }}" id="githubManualWebhookSecret"></x-forms.input>
                        @else
                            <x-forms.input disabled type="password"
                                helper="{{ __('webhooks.github_secret_helper') }}"
                                label="{{ __('webhooks.github_secret_label') }}" id="githubManualWebhookSecret"></x-forms.input>
                        @endcan
                    </div>
                    <a target="_blank" class="flex hover:no-underline" href="{{ $resource?->gitWebhook }}">
                        <x-forms.button>{{ __('webhooks.github_config_button') }}
                            <x-external-link />
                        </x-forms.button>
                    </a>
                    <div class="flex gap-2">
                        <x-forms.input readonly label="{{ __('webhooks.gitlab_label') }}" id="gitlabManualWebhook"></x-forms.input>
                        @can('update', $resource)
                            <x-forms.input type="password"
                                helper="{{ __('webhooks.gitlab_secret_helper') }}"
                                label="{{ __('webhooks.gitlab_secret_label') }}" id="gitlabManualWebhookSecret"></x-forms.input>
                        @else
                            <x-forms.input disabled type="password"
                                helper="{{ __('webhooks.gitlab_secret_helper') }}"
                                label="{{ __('webhooks.gitlab_secret_label') }}" id="gitlabManualWebhookSecret"></x-forms.input>
                        @endcan
                    </div>
                    <div class="flex gap-2">
                        <x-forms.input readonly label="{{ __('webhooks.bitbucket_label') }}" id="bitbucketManualWebhook"></x-forms.input>
                        @can('update', $resource)
                            <x-forms.input type="password"
                                helper="{{ __('webhooks.bitbucket_secret_helper') }}"
                                label="{{ __('webhooks.bitbucket_secret_label') }}" id="bitbucketManualWebhookSecret"></x-forms.input>
                        @else
                            <x-forms.input disabled type="password"
                                helper="{{ __('webhooks.bitbucket_secret_helper') }}"
                                label="{{ __('webhooks.bitbucket_secret_label') }}" id="bitbucketManualWebhookSecret"></x-forms.input>
                        @endcan
                    </div>
                    <div class="flex gap-2">
                        <x-forms.input readonly label="{{ __('webhooks.gitea_label') }}" id="giteaManualWebhook"></x-forms.input>
                        @can('update', $resource)
                            <x-forms.input type="password"
                                helper="{{ __('webhooks.gitea_secret_helper') }}"
                                label="{{ __('webhooks.gitea_secret_label') }}" id="giteaManualWebhookSecret"></x-forms.input>
                        @else
                            <x-forms.input disabled type="password"
                                helper="{{ __('webhooks.gitea_secret_helper') }}"
                                label="{{ __('webhooks.gitea_secret_label') }}" id="giteaManualWebhookSecret"></x-forms.input>
                        @endcan
                    </div>
                    @can('update', $resource)
                        <x-forms.button type="submit">{{ __('button.save') }}</x-forms.button>
                    @endcan
                </form>
            @else
                <x-callout type="info" title="{{ __('webhooks.official_git_app_info_title') }}">
                    {{ __('webhooks.official_git_app_info_message') }}
                </x-callout>
            @endif
        </div>
    @endif

</div>
