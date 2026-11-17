@php
    $rotation = data_get($status, 'rotation');
    $inProgress = (bool) data_get($rotation, 'in_progress', false);
    $rotationStatus = data_get($rotation, 'status');
    $nextStep = data_get($status, 'next_step');
    $blocking = (int) data_get($status, 'blocking', 0);
    $nodes = data_get($status, 'nodes', []);
    $statusLabel = match ($rotationStatus) {
        'distributing' => 'Distributing dual-CA bundle',
        'switched' => 'Switched to new CA',
        'retiring' => 'Retiring old CA',
        'completed' => 'Completed',
        'cancelling' => 'Cancelling',
        'cancelled' => 'Cancelled',
        default => 'No rotation',
    };
    $statusType = match ($rotationStatus) {
        'completed' => 'success',
        'cancelled', null => 'neutral',
        default => 'warning',
    };
    $nextStepLabel = match ($nextStep) {
        'switch' => 'Switch to new CA',
        'retire' => 'Start retirement',
        'complete' => 'Retire old CA',
        'finish_cancel' => 'Finish cancel',
        default => null,
    };
    $nextStepActions = match ($nextStep) {
        'switch' => [
            'Coolify issues a new Flux certificate signed by the new CA.',
            'Flux restarts and every Sentinel reconnects with the dual-CA bundle.',
            'If Flux does not serve the new certificate, the previous certificate is restored.',
        ],
        'retire' => ['Cluster servers receive a trust bundle with the new CA only.'],
        'complete' => ['The old CA is retired and its private key is erased.'],
        'finish_cancel' => ['The cancelled rotation is closed.'],
        default => [],
    };
    $pending = collect($nodes)->where('acknowledged', false)->count();
    $serverWord = $blocking === 1 ? 'server has' : 'servers have';
    // What the admin must do now, so the buttons do not need to be guessed.
    [$guideTitle, $guideText, $guideType] = match (true) {
        ! $inProgress => [null, null, null],
        $nextStep === 'switch' && $blocking === 0 => ['Step 1 of 3: Ready to switch', 'Every cluster server trusts the old and the new CA. Click Switch to new CA to move Flux to the new certificate.', 'success'],
        $nextStep === 'switch' => ['Step 1 of 3: Waiting for servers', "{$blocking} cluster {$serverWord} not acknowledged the trust bundle yet. Wait, click Retry delivery, or run Repair trust on the server. Force the switch only if you accept that the server loses its connection.", 'warning'],
        $nextStep === 'retire' => ['Step 2 of 3: Remove the old CA from servers', 'Flux uses the new certificate now. Click Start retirement to send the cluster servers a trust bundle with the new CA only.', 'info'],
        $nextStep === 'complete' && $blocking === 0 => ['Step 3 of 3: Ready to finish', 'Every cluster server trusts only the new CA. Click Retire old CA to erase the old CA and finish the rotation.', 'success'],
        $nextStep === 'complete' => ['Step 3 of 3: Waiting for servers', "{$blocking} cluster {$serverWord} not acknowledged the new trust bundle yet. Wait, click Retry delivery, or run Repair trust on the server.", 'warning'],
        $nextStep === 'finish_cancel' && $blocking === 0 => ['Ready to finish the cancel', 'Every cluster server trusts only the old CA again. Click Finish cancel to close the rotation.', 'success'],
        $nextStep === 'finish_cancel' => ['Cancelling: waiting for servers', "{$blocking} cluster {$serverWord} not acknowledged the old trust bundle yet. Wait, click Retry delivery, or run Repair trust on the server.", 'warning'],
        default => [null, null, null],
    };
@endphp

{{-- Servers acknowledge the bundle in the background, so poll while a rotation runs. --}}
<div @if ($inProgress) wire:poll.5s="refreshStatus" @endif>
    <x-slot:title>
        Cluster Trust | Coolify
    </x-slot>

    <x-settings.layout>
        <div class="application-settings-form flex min-w-0 flex-col gap-6">
            <x-application.settings-section title="Flux certificate authority"
                helper="Sentinel on every cluster server verifies Flux with this instance-wide CA. Rotation first sends a bundle with the old and new CA over the secure Flux connection, then switches the Flux certificate, then removes the old CA.">
                <dl class="grid gap-x-6 gap-y-5 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-xs font-medium text-neutral-500 dark:text-fg-dim">Rotation</dt>
                        <dd class="mt-1"><x-status-badge :status="$statusLabel" :type="$statusType" /></dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-neutral-500 dark:text-fg-dim">Trust bundle version</dt>
                        <dd class="mt-1 text-sm font-medium text-neutral-950 dark:text-fg">{{ data_get($status, 'bundle_version') }}</dd>
                    </div>
                    @if ($inProgress)
                        <div>
                            <dt class="text-xs font-medium text-neutral-500 dark:text-fg-dim">Servers acknowledged</dt>
                            <dd class="mt-1 text-sm font-medium text-neutral-950 dark:text-fg">
                                {{ collect($nodes)->where('acknowledged', true)->count() }} / {{ count($nodes) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-neutral-500 dark:text-fg-dim">Blocking servers</dt>
                            <dd class="mt-1 text-sm font-medium {{ $blocking > 0 ? 'text-warning' : 'text-neutral-950 dark:text-fg' }}">{{ $blocking }}</dd>
                        </div>
                    @endif
                </dl>

                @if (filled(data_get($rotation, 'last_error')))
                    <p class="mt-4 text-[12px] text-red-600 dark:text-red-400">{{ data_get($rotation, 'last_error') }}</p>
                @endif
                @if (data_get($rotation, 'switch_forced') || data_get($rotation, 'completion_forced'))
                    <p class="mt-4 text-[12px] text-warning">
                        A step was forced. Run Repair trust over SSH on cluster servers that cannot connect.
                    </p>
                @endif

                @if ($guideTitle !== null)
                    <x-callout :type="$guideType" :title="$guideTitle" class="mt-4">{{ $guideText }}</x-callout>
                @endif

                {{-- The confirmation modals use wire:ignore, so a new key per state makes Livewire replace the buttons. --}}
                <div wire:key="flux-trust-actions-{{ $rotationStatus ?? 'none' }}-{{ $nextStep ?? 'none' }}-{{ $blocking > 0 ? 'blocked' : 'ready' }}-{{ $pending > 0 ? 'pending' : 'delivered' }}"
                    class="mt-4 flex flex-wrap items-center gap-2 border-t border-neutral-200 pt-4 dark:border-white/[0.07]">
                    @if (! $inProgress)
                        <x-modal-confirmation title="Start Flux CA rotation?" buttonTitle="Start rotation"
                            submitAction="startRotation" :actions="[
                                'Coolify creates a new certificate authority.',
                                'Connected cluster servers receive a trust bundle with the old and new CA over Flux.',
                                'Nothing switches until you continue the rotation.',
                            ]" :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Start rotation" />
                    @else
                        @if ($nextStepLabel !== null && ($blocking === 0 || $nextStep === 'retire'))
                            <x-modal-confirmation :title="$nextStepLabel . '?'" :buttonTitle="$nextStepLabel"
                                submitAction="continueRotation" isHighlightedButton :actions="$nextStepActions" :confirmWithText="false"
                                :confirmWithPassword="false" :step2ButtonText="$nextStepLabel" />
                        @elseif ($nextStepLabel !== null)
                            <x-modal-confirmation :title="'Force: ' . $nextStepLabel . '?'"
                                :buttonTitle="'Force: ' . $nextStepLabel" isErrorButton submitAction="forceContinueRotation"
                                :actions="$nextStepActions"
                                :warningMessage="$blocking . ' usable cluster server(s) have not acknowledged the trust bundle. They may lose their Flux connection and need Repair trust over SSH.'"
                                :confirmWithText="false" :confirmWithPassword="false" :step2ButtonText="'Force: ' . $nextStepLabel" />
                        @endif
                        @if ($pending > 0)
                            <x-forms.button type="button" wire:click="retryDistribution">Retry delivery</x-forms.button>
                        @endif
                        @if ($rotationStatus === 'distributing')
                            <x-modal-confirmation title="Cancel Flux CA rotation?" buttonTitle="Cancel rotation"
                                isErrorButton submitAction="cancelRotation" :actions="[
                                    'The new CA is discarded and its private key is erased.',
                                    'Cluster servers receive a trust bundle with the old CA only.',
                                ]" :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Cancel rotation" />
                        @endif
                    @endif
                </div>
            </x-application.settings-section>

            <x-application.settings-section title="Cluster servers"
                helper="The trust bundle each cluster server acknowledged. Offline servers receive the bundle when they reconnect. Servers whose Sentinel cannot receive bundles over Flux need Repair trust from the server's Sentinel page.">
                @if ($nodes === [])
                    <x-empty size="sm" title="No cluster servers" description="Cluster servers appear here after they are added." icon-name="servers" />
                @else
                    <div class="divide-y divide-neutral-200 dark:divide-white/[0.07]">
                        @foreach ($nodes as $trustNode)
                            <div wire:key="flux-trust-node-{{ $trustNode['uuid'] }}"
                                class="flex flex-wrap items-center justify-between gap-3 py-3">
                                <div class="min-w-0 flex-1">
                                    <div class="flex min-w-0 flex-wrap items-center gap-2">
                                        <span class="truncate text-[13px] font-semibold text-black dark:text-fg">{{ $trustNode['name'] }}</span>
                                        <span class="text-[12px] text-neutral-500 dark:text-fg-dim">{{ $trustNode['team'] }}</span>
                                    </div>
                                    <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                                        Bundle {{ $trustNode['version'] ?? 'unknown' }}
                                        · {{ $trustNode['connected'] ? 'Connected' : 'Offline' }}
                                        @if (! $trustNode['is_usable'])
                                            · Not usable, not required
                                        @endif
                                        @if ($trustNode['supports_update'] === false)
                                            · Needs Sentinel upgrade or SSH repair
                                        @endif
                                    </p>
                                    @if (filled($trustNode['error']))
                                        <p class="text-[12px] text-red-600 dark:text-red-400">{{ $trustNode['error'] }}</p>
                                    @endif
                                </div>
                                @if ($trustNode['acknowledged'])
                                    <x-status-badge status="Acknowledged" type="success" />
                                @elseif ($trustNode['blocking'])
                                    <x-status-badge status="Blocking" type="warning" />
                                @else
                                    <x-status-badge status="Pending" type="neutral" />
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-application.settings-section>
        </div>
    </x-settings.layout>
</div>
