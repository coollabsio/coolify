<div class="flex flex-col gap-6">
    <x-application.settings-section id="recommended-settings-section" title="Recommended settings"
        helper="Settings that Coolify recommends for this application. A change takes effect with the next deployment.">
        @forelse ($openRecommendations as $recommendation)
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
                                ]" :confirmWithText="false" :confirmWithPassword="false"
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
        @empty
            <x-empty size="sm" icon-name="check-circle" title="No recommendations"
                description="This application uses the settings that Coolify recommends." />
        @endforelse
    </x-application.settings-section>

    @if ($dismissedRecommendations->isNotEmpty())
        <x-application.settings-section id="dismissed-recommendations-section" title="Dismissed"
            helper="Recommendations that you dismissed. They do not count in the menu." flush>
            @foreach ($dismissedRecommendations as $recommendation)
                <div wire:key="dismissed-recommendation-{{ $recommendation->value }}"
                    class="flex min-h-12 items-center gap-3 border-b border-neutral-200 px-4 py-2.5 last:border-b-0 dark:border-white/[0.07]">
                    <span class="min-w-0 flex-1 truncate text-sm">{{ $recommendation->title() }}</span>
                    @can('update', $application)
                        <x-forms.button wire:click="restore('{{ $recommendation->value }}')">Restore</x-forms.button>
                    @endcan
                </div>
            @endforeach
        </x-application.settings-section>
    @endif
</div>
