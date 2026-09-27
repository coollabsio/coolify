<div>
    <section class="application-settings-section">
        <div class="application-settings-section-header">
            <div>
                <h2 class="flex items-center gap-2">
                    Connect3 site
                    @if ($project->isLive())
                        <span class="rounded px-1.5 py-0.5 text-xs font-semibold bg-success/15 text-success">live</span>
                    @else
                        <span class="rounded px-1.5 py-0.5 text-xs font-semibold bg-warning/15 text-warning">staged</span>
                    @endif
                </h2>
                <p>Private staging subdomain, client review credentials and the staged &rarr; live switch.</p>
            </div>
        </div>
        <div class="application-settings-section-body flex flex-col gap-6">
            @if (! $apex)
                <div class="rounded border border-warning/40 bg-warning/10 px-4 py-3 text-sm">
                    No staging apex configured. Set it under
                    <a class="underline" href="{{ route('settings.index') }}">Settings &rarr; Connect3 staging</a>
                    before assigning client slugs.
                </div>
            @endif

            <form wire:submit="submit" class="grid gap-4 sm:grid-cols-2">
                <x-forms.input id="client_slug" label="Client slug" placeholder="todd-plumbing"
                    helper="Lowercase, DNS-safe. Drives <b>&lt;slug&gt;.{{ $apex ?? '<staging-apex>' }}</b>. New applications in this project get that hostname automatically." />
                <x-forms.input id="ai_gateway_key_id" label="AI gateway key id"
                    helper="Per-client LiteLLM virtual key id used for token cost metering." />
                <div class="sm:col-span-2">
                    <x-forms.input id="live_domains" label="Live domain(s)" placeholder="example.com, www.example.com"
                        helper="Client domains bound on promotion. Comma separated. DNS must point at this server (or be proxied through Cloudflare) before promoting." />
                </div>
                <div class="sm:col-span-2 flex flex-wrap items-center gap-2">
                    <x-forms.button type="submit">Save</x-forms.button>
                    @if ($project->client_slug && $apex)
                        @if ($project->isLive())
                            <x-modal-confirmation title="Demote to staged?" buttonTitle="Demote" isErrorButton
                                submitAction="demote" :confirmWithText="false" :confirmWithPassword="false"
                                step2ButtonText="Demote" :actions="[
                                    'Live routers for '.implode(', ', $project->liveDomainsList()).' are removed from the proxy.',
                                    'The staging URL keeps working behind basic auth.',
                                    'No container is restarted. Certificates stay cached for re-promotion.',
                                ]" />
                        @else
                            <x-modal-confirmation title="Promote to live?" buttonTitle="Promote" isHighlightedButton
                                submitAction="promote" :confirmWithText="false" :confirmWithPassword="false"
                                step2ButtonText="Run DNS pre-flight and promote" :actions="[
                                    'DNS for every live domain is checked against this server first. If it does not point here, nothing changes.',
                                    'On success the proxy starts serving the live domain(s) publicly with TLS, no auth, no forced noindex.',
                                    'The staging URL stays private and noindexed. No container is restarted.',
                                ]" />
                        @endif
                    @endif
                </div>
            </form>

            @if ($project->client_slug && $apex)
                <div class="flex flex-col gap-3">
                    <h3 class="text-sm font-semibold">Staging access</h3>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <x-forms.copy-input label="Staging URL" :text="$stagingUrl" />
                        <x-forms.copy-input label="Username" :text="$project->staging_auth_user" />
                        <x-forms.copy-input label="Password" :text="$project->staging_auth_pass" />
                    </div>
                    <p class="text-xs opacity-70">
                        Always basic-auth protected and sent with <code>X-Robots-Tag: {{ C3_NOINDEX_VALUE }}</code>;
                        <code>/robots.txt</code> is overridden at the proxy. PR previews land on
                        <code>{{ $previewExample }}</code> with the same protections.
                    </p>
                    <div>
                        <x-modal-confirmation title="Regenerate staging password?" buttonTitle="Regenerate password"
                            submitAction="regenerateCredentials" :confirmWithText="false" :confirmWithPassword="false"
                            step2ButtonText="Regenerate" :actions="[
                                'The current password stops working immediately.',
                                'Anyone reviewing the staging site needs the new password.',
                            ]" />
                    </div>
                </div>
            @endif

            @if ($liveUrls !== [])
                <div class="flex flex-col gap-2">
                    <h3 class="text-sm font-semibold">Live</h3>
                    <ul class="text-sm">
                        @foreach ($liveUrls as $url)
                            <li><a class="underline" target="_blank" href="{{ $url }}">{{ $url }}</a></li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </section>
</div>
