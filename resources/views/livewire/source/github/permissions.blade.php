            <div class="application-settings-form">
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
            </div>
