<div>
    @if ($resource->type() === 'application')
        @php
            $manualWebhookProviders = [
                [
                    'name' => 'GitHub',
                    'url' => $githubManualWebhook,
                    'secret' => 'githubManualWebhookSecret',
                    'description' => 'Accepts JSON or form-urlencoded webhook payloads.',
                ],
                [
                    'name' => 'GitLab',
                    'url' => $gitlabManualWebhook,
                    'secret' => 'gitlabManualWebhookSecret',
                    'description' => 'Use the same secret when configuring the webhook in GitLab.',
                ],
                [
                    'name' => 'Bitbucket',
                    'url' => $bitbucketManualWebhook,
                    'secret' => 'bitbucketManualWebhookSecret',
                    'description' => 'Use the same secret when configuring the webhook in Bitbucket.',
                ],
                [
                    'name' => 'Gitea',
                    'url' => $giteaManualWebhook,
                    'secret' => 'giteaManualWebhookSecret',
                    'description' => 'Use the same secret when configuring the webhook in Gitea.',
                ],
            ];
        @endphp

        <div class="flex flex-col gap-6">
            <x-application.settings-section id="deploy-webhook-section" title="Deploy webhook"
                helper="Trigger a deployment from an external service. Requests must include a valid Coolify API authorization token.">
                <x-slot:actions>
                    <a class="button" href="https://coolify.io/docs/api-reference/authorization" target="_blank"
                        rel="noopener noreferrer">
                        Documentation
                        <x-external-link />
                    </a>
                </x-slot:actions>
                <x-forms.copy-button label="Deploy webhook URL" :text="$deploywebhook ?? ''" />
            </x-application.settings-section>

            @if ($githubManualWebhook && $gitlabManualWebhook)
                <form wire:submit.prevent="submit" class="application-settings-form flex flex-col">
                    <x-unsaved-bar action="submit" />
                    <x-application.settings-section id="manual-git-webhooks-section" title="Manual Git webhooks"
                        helper="Configure these endpoints when the repository is not connected through an official Git App." flush>
                        <x-slot:actions>
                            @if (filled($resource?->gitWebhook))
                                <a class="button" href="{{ $resource->gitWebhook }}" target="_blank"
                                    rel="noopener noreferrer">
                                    Repository settings
                                    <x-external-link />
                                </a>
                            @endif
                        </x-slot:actions>

                        <div class="divide-y divide-neutral-200 dark:divide-white/[0.07]">
                            @foreach ($manualWebhookProviders as $provider)
                                <section class="px-4 py-5 first:pt-4 last:pb-4"
                                    wire:key="manual-webhook-{{ str($provider['name'])->slug() }}">
                                    <div class="mb-4">
                                        <h4 class="text-sm font-semibold text-black dark:text-fg">
                                            {{ $provider['name'] }}
                                        </h4>
                                        <p class="mt-1 text-[13px] leading-5 text-neutral-500 dark:text-fg-dim">
                                            {{ $provider['description'] }}
                                        </p>
                                    </div>
                                    <div class="grid gap-4 md:grid-cols-2">
                                        <x-forms.copy-button label="Webhook URL" :text="$provider['url'] ?? ''" />
                                        @can('update', $resource)
                                            <x-forms.input type="password" :id="$provider['secret']"
                                                label="Webhook secret"
                                                helper="Must exactly match the secret configured in {{ $provider['name'] }}."
                                                autocomplete="new-password" />
                                        @else
                                            <x-forms.input disabled label="Webhook secret"
                                                value="Hidden (only administrators can view)" />
                                        @endcan
                                    </div>
                                </section>
                            @endforeach
                        </div>
                    </x-application.settings-section>
                </form>
            @else
                <x-application.settings-section id="manual-git-webhooks-section" title="Manual Git webhooks"
                    helper="Manual repository webhooks are only required when a repository is not connected through an official Git App.">
                    <x-empty size="sm" title="Managed by your Git App"
                        description="This application uses an official Git App, so Coolify configures repository webhooks automatically.">
                        <x-slot:icon>
                            <x-reicon name="notifications" class="size-8" />
                        </x-slot:icon>
                    </x-empty>
                </x-application.settings-section>
            @endif
        </div>
    @else
        <div class="application-settings-form">
            <x-application.settings-section title="Deploy webhook"
                helper="Trigger an external deployment with a valid Coolify API authorization token.">
                <x-slot:actions>
                    <a class="button" href="https://coolify.io/docs/api-reference/authorization" target="_blank"
                        rel="noopener noreferrer">
                        Documentation
                        <x-external-link />
                    </a>
                </x-slot:actions>
                <x-forms.copy-button label="Deploy webhook URL" :text="$deploywebhook ?? ''" />
            </x-application.settings-section>
        </div>
    @endif
</div>
