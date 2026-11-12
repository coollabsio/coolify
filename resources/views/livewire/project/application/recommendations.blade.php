<div>
    @if ($openRecommendations->isNotEmpty() || $dismissedRecommendations->isNotEmpty())
        <div class="mb-6" x-data="{ showDismissed: false }">
            <x-application.settings-section id="recommendations-section" title="Recommendations"
                helper="Settings that Coolify recommends for this application. A change takes effect with the next deployment.">
                @if ($dismissedRecommendations->isNotEmpty())
                    <x-slot:actions>
                        <x-forms.button x-on:click="showDismissed = ! showDismissed"
                            x-text="showDismissed ? 'Hide dismissed' : 'Show dismissed ({{ $dismissedRecommendations->count() }})'">
                            Show dismissed ({{ $dismissedRecommendations->count() }})
                        </x-forms.button>
                    </x-slot:actions>
                @endif

                @foreach ($openRecommendations as $recommendation)
                    <x-callout wire:key="recommendation-{{ $recommendation->value }}" :type="$recommendation->severity($application)"
                        :title="$recommendation->title()" @class(['mt-3' => ! $loop->first])>
                        <p>{{ $recommendation->why($application) }}</p>
                        @can('update', $application)
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                @if ($recommendation->fixNeedsConfirmation())
                                    <x-modal-confirmation :title="$recommendation->title().'?'" :buttonTitle="$recommendation->fixLabel()"
                                        submitAction="applyFix('{{ $recommendation->value }}')" :actions="[
                                            'The build pack of this application changes to Railpack.',
                                            'The next deployment builds the application with Railpack.',
                                        ]" warningMessage="Railpack reads RAILPACK_* variables and railpack.json, not NIXPACKS_* variables or nixpacks.toml. You can switch back in Build & deploy on this page."
                                        :confirmWithText="false" :confirmWithPassword="false"
                                        step2ButtonText="Confirm" />
                                @else
                                    <x-forms.button wire:click="applyFix('{{ $recommendation->value }}')">
                                        {{ $recommendation->fixLabel() }}
                                    </x-forms.button>
                                @endif
                                <x-forms.button wire:click="dismiss('{{ $recommendation->value }}')">Dismiss</x-forms.button>
                            </div>
                        @endcan
                    </x-callout>
                @endforeach

                @if ($openRecommendations->isEmpty())
                    <p class="text-sm text-neutral-600 dark:text-fg-dim" x-show="! showDismissed">
                        You dismissed every recommendation for this application.
                    </p>
                @endif

                @if ($dismissedRecommendations->isNotEmpty())
                    <div x-show="showDismissed" x-cloak @class(['mt-3' => $openRecommendations->isNotEmpty()])>
                        @foreach ($dismissedRecommendations as $recommendation)
                            <div wire:key="dismissed-recommendation-{{ $recommendation->value }}"
                                class="flex min-h-12 items-center gap-3 border-b border-neutral-200 py-2.5 last:border-b-0 dark:border-white/[0.07]">
                                <span class="min-w-0 flex-1 truncate text-sm">{{ $recommendation->title() }}</span>
                                @can('update', $application)
                                    <x-forms.button wire:click="restore('{{ $recommendation->value }}')">Restore</x-forms.button>
                                @endcan
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-application.settings-section>
        </div>
    @endif
</div>
