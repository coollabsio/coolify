<div class="flex flex-col gap-2">
    <div class="flex items-center gap-2">
        <h2>Webhooks</h2>
        <x-helper
            helper="For more details goto our <a class='underline dark:text-white' href='https://coolify.io/docs/api-reference/deploy-webhook' target='_blank'>docs</a>." />
    </div>
    <div>
        <x-forms.input readonly
            helper="See details in our <a target='_blank' class='underline dark:text-white' href='https://coolify.io/docs/api-reference/deploy-webhook'>documentation</a>."
            label="Deploy Webhook (auth required)" id="deploywebhook"></x-forms.input>
    </div>
    @if ($resource->type() === 'application')
        <div>
            <h3>Manual Git Webhooks</h3>
            @if ($githubManualWebhook && $gitlabManualWebhook)
                <form wire:submit='submit' class="flex flex-col gap-2">
                    <div class="flex items-end gap-2">
                        <x-forms.input helper="Content Type in GitHub configuration could be json or form-urlencoded."
                            readonly label="GitHub" id="githubManualWebhook"></x-forms.input>
                        <x-forms.input type="password"
                            helper="Need to set a secret to be able to use this webhook. It should match with the secret in GitHub."
                            label="GitHub Webhook Secret" id="githubManualWebhookSecret"></x-forms.input>

                    </div>
                    <a target="_blank" class="flex hover:no-underline" href="{{ $resource?->gitWebhook }}">
                        <x-forms.button>Webhook Configuration on GitHub
                            <x-external-link />
                        </x-forms.button>
                    </a>
                    <div class="flex gap-2">
                        <x-forms.input readonly label="GitLab" id="gitlabManualWebhook"></x-forms.input>
                        <x-forms.input type="password"
                            helper="Need to set a secret to be able to use this webhook. It should match with the secret in GitLab."
                            label="GitLab Webhook Secret" id="gitlabManualWebhookSecret"></x-forms.input>
                    </div>
                    <div class="flex gap-2">
                        <x-forms.input readonly label="Bitbucket" id="bitbucketManualWebhook"></x-forms.input>
                        <x-forms.input type="password"
                            helper="Need to set a secret to be able to use this webhook. It should match with the secret in Bitbucket."
                            label="Bitbucket Webhook Secret" id="bitbucketManualWebhookSecret"></x-forms.input>
                    </div>
                    <div class="flex gap-2">
                        <x-forms.input readonly label="Gitea" id="giteaManualWebhook"></x-forms.input>
                        <x-forms.input type="password"
                            helper="Need to set a secret to be able to use this webhook. It should match with the secret in Gitea."
                            label="Gitea Webhook Secret" id="giteaManualWebhookSecret"></x-forms.input>
                    </div>
                    <x-forms.button type="submit">Save</x-forms.button>
                </form>
            @else
                You are using an official Git App. You do not need manual webhooks.
            @endif
        </div>
    @endif

</div>
