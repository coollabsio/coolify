<div class="flex flex-col gap-6">
    <x-application.settings-section title="Deploy webhook" description="Deploy the stack from a CI pipeline or another tool. The request needs an API token with the deploy ability.">
        <x-forms.input id="deployWebhook" label="Deploy webhook (auth required)" readonly copyable
            :value="url('/api/v1/cluster-stacks/'.$stack->uuid.'/deploy')"
            helper="Send a POST request with the header Authorization: Bearer <token>." />
    </x-application.settings-section>
    @if ($stack->hasGitSource())
        <x-application.settings-section title="Git push" description="A push to the branch of the stack deploys the whole stack.">
            <p class="text-xs text-neutral-500 dark:text-fg-dim">
                The Git source of the stack sends the push events. Each service that builds from the repository has its webhook settings on its <span class="font-medium">Webhooks</span> page.
            </p>
        </x-application.settings-section>
    @endif
</div>
