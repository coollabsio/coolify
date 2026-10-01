            <div class="application-settings-form flex flex-col gap-6">
                <x-application.settings-section title="Permissions"
                    description="GitHub permissions currently granted to this App.">
                    <x-slot:actions>
                        @can('view', $github_app)
                            <x-forms.button type="button" wire:click.prevent="checkPermissions">
                                <x-reicon name="refresh" class="size-3.5" />
                                Refetch
                            </x-forms.button>
                            <a href="{{ getPermissionsPath($github_app) }}" class="button">
                                Update on GitHub
                                <x-external-link />
                            </a>
                        @endcan
                    </x-slot:actions>

                    <div class="grid gap-4 lg:grid-cols-3">
                        <x-forms.input canGate="view" :canResource="$github_app" id="contents"
                            helper="Read access is mandatory." label="Contents" readonly placeholder="N/A" />
                        <x-forms.input canGate="view" :canResource="$github_app" id="metadata"
                            helper="Read access is mandatory." label="Metadata" readonly placeholder="N/A" />
                        <x-forms.input canGate="view" :canResource="$github_app" id="pullRequests"
                            helper="Write access is needed for preview deployment status updates."
                            label="Pull requests" readonly placeholder="N/A" />
                    </div>
                </x-application.settings-section>

                @php($missingRunnerRequirements = $github_app->missingRunnerRequirements())
                <x-application.settings-section title="GitHub Actions runners"
                    description="Build servers can run the organization's workflow jobs in Docker containers. Configure them in the GitHub Runners menu of a build server.">
                    <div class="grid gap-4 lg:grid-cols-3">
                        <x-forms.input canGate="view" :canResource="$github_app"
                            label="Self-hosted runners" readonly placeholder="N/A"
                            :value="$github_app->organization_self_hosted_runners"
                            helper="Organization permission. Write access is needed to register runners." />
                        <x-forms.input canGate="view" :canResource="$github_app"
                            label="Actions" readonly placeholder="N/A" :value="$github_app->actions"
                            helper="Repository permission. Read access is needed for the Workflow job webhook event." />
                        <x-forms.input canGate="view" :canResource="$github_app"
                            label="Webhook events" readonly placeholder="N/A"
                            :value="implode(', ', $github_app->webhook_events ?? [])"
                            helper="Refetch to update this list after you change the App on GitHub." />
                    </div>
                    @if ($missingRunnerRequirements !== [])
                        <x-callout type="info" title="Not ready for runners" class="mt-4">
                            Update the App on GitHub, then click Refetch. Missing:
                            <ul class="mt-1 list-disc pl-4">
                                @foreach ($missingRunnerRequirements as $requirement)
                                    <li>{{ $requirement }}</li>
                                @endforeach
                            </ul>
                        </x-callout>
                    @endif
                </x-application.settings-section>
            </div>
