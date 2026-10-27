@if ($ignores->isEmpty())
    <x-empty size="sm" title="No ignored vulnerabilities." />
@else
    <div class="data-table">
        <div class="data-table-header image-scan-ignores-table-grid">
            <span>Vulnerability or package</span>
            <span>Reason</span>
            <span>Expires</span>
            <span>Set for</span>
            <span class="text-right">Actions</span>
        </div>
        @foreach ($ignores as $ignore)
            @php($isTeamWide = $ignore->resource_id === null)
            <div wire:key="image-scan-ignore-{{ $ignore->uuid }}"
                class="data-table-row image-scan-ignores-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]">
                <div class="min-w-0 truncate text-[12px] text-black dark:text-fg">
                    @if ($ignore->vulnerability_id)
                        <span class="font-mono">{{ $ignore->vulnerability_id }}</span>
                    @else
                        All vulnerabilities in <span class="font-mono">{{ $ignore->package }}</span>
                    @endif
                </div>
                <div class="min-w-0 truncate text-[12px] text-neutral-600 dark:text-fg-dim"
                    title="{{ $ignore->reason }}">{{ $ignore->reason }}</div>
                <div class="text-[11px] text-neutral-500 dark:text-fg-dim">
                    {{ $ignore->expires_at?->format('Y-m-d') ?? 'Never' }}
                </div>
                <div class="min-w-0 text-[11px] text-neutral-500 dark:text-fg-dim">
                    {{ $isTeamWide ? 'All team resources' : 'This resource' }}
                </div>
                <div class="flex justify-end">
                    @if ($isApplication && $isTeamWide)
                        @if ($showsTeamSettingsLink)
                            <a href="{{ route('team.image-scanning') }}" wire:navigate
                                class="inline-flex h-7 items-center rounded-md px-2 text-[11px] font-medium text-neutral-600 hover:bg-neutral-100 dark:text-fg-dim dark:hover:bg-white/[0.06]">
                                Team settings
                            </a>
                        @endif
                    @elseif ($canEdit)
                        <x-modal-confirmation title="Remove this ignore?"
                            submitAction="removeIgnore('{{ $ignore->uuid }}')" :actions="[
                                'The vulnerability counts again for alerts and Block deploys.',
                            ]"
                            :confirmWithText="false" :confirmWithPassword="false"
                            temporaryDisableTwoStepConfirmation step2ButtonText="Remove">
                            <x-slot:trigger>
                                <button type="button"
                                    class="inline-flex h-7 items-center rounded-md px-2 text-[11px] font-medium text-error transition-colors hover:bg-error/10">
                                    Remove
                                </button>
                            </x-slot:trigger>
                        </x-modal-confirmation>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
@endif
