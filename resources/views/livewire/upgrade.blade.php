<div @if ($isUpgradeAvailable) title="New version available" @else title="No upgrade available" @endif
    x-init="$wire.checkUpdate" x-data="upgradeModal({
        currentVersion: @js($currentVersion),
        latestVersion: @js($latestVersion),
        devMode: @js($devMode)
    })">
    @if ($isUpgradeAvailable)
        <div :class="{ 'z-40': modalOpen }" class="relative w-auto h-auto">
            @if ($fullButton)
                <x-forms.button type="button" @click="modalOpen=true" x-show="!showProgress" x-cloak isHighlighted>
                    Upgrade now
                </x-forms.button>
                <x-forms.button type="button" @click="modalOpen=true" x-show="showProgress" x-cloak isHighlighted>
                    Updating…
                </x-forms.button>
            @else
            <button type="button" title="Upgrade in progress" aria-label="Upgrade in progress"
                @click="modalOpen=true" x-show="showProgress" x-cloak
                class="inline-flex h-[18px] cursor-pointer items-center rounded-full bg-coollabs/10 px-1.5 text-[9.5px] font-semibold leading-none text-coollabs ring-1 ring-inset ring-coollabs/25 transition-colors hover:bg-coollabs/15 dark:bg-warning/15 dark:text-warning dark:ring-warning/25 dark:hover:bg-warning/20">
                Updating
            </button>
            <button type="button" title="Update available" aria-label="Update available"
                @click="modalOpen=true" x-show="!showProgress" x-cloak
                class="inline-flex h-[18px] cursor-pointer items-center rounded-full bg-coollabs/10 px-1.5 text-[9.5px] font-semibold leading-none text-coollabs ring-1 ring-inset ring-coollabs/25 transition-colors hover:bg-coollabs/15 dark:bg-warning/15 dark:text-warning dark:ring-warning/25 dark:hover:bg-warning/20">
                Update available
            </button>
            @endif
            <template x-teleport="body">
                <div x-show="modalOpen"
                    class="fixed inset-0 z-99 flex min-h-full items-center justify-center overflow-y-auto p-4"
                    x-cloak>
                    <div x-show="modalOpen" x-transition:enter="ease-out duration-100"
                        x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                        x-transition:leave="ease-in duration-100" x-transition:leave-start="opacity-100"
                        x-transition:leave-end="opacity-0"
                        class="absolute inset-0 w-full h-full bg-black/50 backdrop-blur-[2px]"></div>
                    <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen"
                        x-transition:enter="ease-out duration-100"
                        x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave="ease-in duration-100"
                        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                        class="application-settings-form application-settings-section relative flex max-h-[calc(100dvh-2rem)] w-full flex-col lg:min-w-[36rem] lg:max-w-2xl"
                        style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal)">

                        <header class="flex-nowrap!">
                            <div class="min-w-0 flex-1">
                                <h3 class="truncate"
                                    x-text="upgradeComplete ? 'Upgrade complete' : (showProgress ? 'Upgrading…' : 'Upgrade available')">
                                </h3>
                                <p class="mt-0.5 text-[12px] leading-4"
                                    style="color: var(--coollabs-subtle)">
                                    {{ $currentVersion }} <span class="mx-0.5">&rarr;</span>
                                    {{ $latestVersion }}
                                </p>
                            </div>
                            <button type="button" x-show="!showProgress || upgradeError"
                                @click="upgradeError ? closeErrorModal() : modalOpen=false"
                                class="flex size-7 shrink-0 cursor-pointer items-center justify-center rounded-md text-neutral-500 outline-0 transition-colors hover:bg-neutral-100 hover:text-black focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-accent dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                aria-label="Close">
                                <x-reicon name="x" class="size-4" />
                            </button>
                        </header>

                        <div class="application-settings-section-body min-h-0 flex-1 overflow-y-auto"
                            style="-webkit-overflow-scrolling: touch;">
                            {{-- Progress View --}}
                            <template x-if="showProgress">
                                <div class="flex flex-col gap-4">
                                    <x-upgrade-progress />

                                    <div class="flex items-center justify-center gap-1.5 text-[12px]"
                                        style="color: var(--coollabs-subtle)">
                                        <span>Elapsed time</span>
                                        <span class="font-mono text-neutral-700 dark:text-fg"
                                            x-text="formatElapsedTime()"></span>
                                    </div>

                                    <div
                                        class="flex items-center gap-2.5 rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-2.5 dark:border-white/[0.08] dark:bg-white/[0.03]">
                                        <template x-if="!upgradeComplete && !upgradeError">
                                            <svg class="loading-indicator size-4 shrink-0 animate-spin"
                                                xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                                aria-hidden="true">
                                                <circle class="opacity-25" cx="12" cy="12" r="10"
                                                    stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor"
                                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                                </path>
                                            </svg>
                                        </template>
                                        <template x-if="upgradeComplete">
                                            <x-reicon name="check-circle"
                                                class="size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                        </template>
                                        <template x-if="upgradeError">
                                            <x-reicon name="alert-circle"
                                                class="size-4 shrink-0 text-red-600 dark:text-red-400" />
                                        </template>
                                        <span x-text="currentStatus"
                                            class="min-w-0 text-[13px] leading-5 text-neutral-700 dark:text-fg"></span>
                                    </div>

                                    <template x-if="upgradeComplete">
                                        <div class="flex flex-col items-center gap-3">
                                            <p class="text-[12px]" style="color: var(--coollabs-subtle)">
                                                Reloading in
                                                <span x-text="successCountdown"
                                                    class="font-semibold text-coollabs dark:text-warning"></span>
                                                seconds…
                                            </p>
                                            <p class="text-center text-[11px] leading-4"
                                                style="color: var(--coollabs-subtle)">
                                                If the page does not reload automatically, reload it manually.
                                            </p>
                                            <x-forms.button @click="reloadNow()" type="button" isHighlighted>
                                                Reload now
                                            </x-forms.button>
                                        </div>
                                    </template>

                                    <template x-if="upgradeError">
                                        <div class="flex flex-col gap-3">
                                            <p class="text-[12px] leading-5" style="color: var(--coollabs-subtle)">
                                                Check the logs on the server at
                                                <span class="font-mono text-neutral-700 dark:text-fg">/data/coolify/source/upgrade*</span>.
                                            </p>
                                            <div
                                                class="flex flex-wrap justify-end gap-2 border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                                                <x-forms.button @click="closeErrorModal()" type="button">
                                                    Close
                                                </x-forms.button>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            {{-- Confirmation View --}}
                            <template x-if="!showProgress">
                                <div class="flex flex-col gap-4">
                                    <x-callout type="warning" title="Caution">
                                        <p>Any deployments running during the update process will fail.</p>
                                    </x-callout>

                                    <p class="text-[12px] leading-5" style="color: var(--coollabs-subtle)">
                                        If something goes wrong, check the
                                        <a class="font-medium text-coollabs underline decoration-coollabs/30 underline-offset-2 hover:decoration-coollabs dark:text-warning dark:decoration-warning/30 dark:hover:decoration-warning"
                                            href="https://coolify.io/docs/upgrade" target="_blank"
                                            rel="noopener noreferrer">upgrade guide</a>
                                        or the logs on the server at
                                        <span class="font-mono text-neutral-700 dark:text-fg">/data/coolify/source/upgrade*</span>.
                                    </p>

                                    <div
                                        class="flex flex-wrap items-center justify-end gap-2 border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                                        <template x-if="devMode">
                                            <x-forms.button @click="simulateUpgrade" type="button">
                                                Simulate
                                            </x-forms.button>
                                        </template>
                                        <x-forms.button @click="confirmed" isHighlighted type="button">
                                            Upgrade now
                                        </x-forms.button>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    @elseif ($fullButton)
        <p class="text-sm text-neutral-600 dark:text-fg-dim">Coolify is up to date.</p>
    @endif
</div>

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('upgradeModal', (config) => ({
            modalOpen: false,
            showProgress: false,
            currentStatus: '',
            checkHealthInterval: null,
            checkUpgradeStatusInterval: null,
            elapsedInterval: null,
            healthCheckAttempts: 0,
            livewireFailures: 0,
            startTime: null,
            elapsedTime: 0,
            currentStep: 0,
            upgradeComplete: false,
            upgradeError: false,
            successCountdown: 3,
            currentVersion: config.currentVersion || '',
            latestVersion: config.latestVersion || '',
            serviceDown: false,
            instanceWentDown: false,
            devMode: config.devMode || false,
            simulationInterval: null,

            simulateUpgrade() {
                if (!this.devMode) return;

                this.showProgress = true;
                this.currentStep = 1;
                this.currentStatus = '[DEV] Starting simulated upgrade...';
                this.startTimer();
                this.upgradeComplete = false;
                this.upgradeError = false;

                const steps = [
                    { step: 1, status: '[DEV] Preparing upgrade environment...' },
                    { step: 2, status: '[DEV] Pulling helper image...' },
                    { step: 3, status: '[DEV] Pulling Coolify image...' },
                    { step: 4, status: '[DEV] Restarting services...' },
                ];

                let stepIndex = 0;
                this.simulationInterval = setInterval(() => {
                    if (stepIndex < steps.length) {
                        this.currentStep = steps[stepIndex].step;
                        this.currentStatus = steps[stepIndex].status;
                        stepIndex++;
                    } else {
                        clearInterval(this.simulationInterval);
                        this.simulationInterval = null;
                        this.showSuccess();
                    }
                }, 2000);
            },

            confirmed() {
                this.showProgress = true;
                this.currentStep = 1;
                this.currentStatus = 'Starting upgrade...';
                this.startTimer();
                // Trigger server-side upgrade script via Livewire
                this.$wire.$call('upgrade');
                // Start client-side status polling
                this.upgrade();
                // Prevent accidental navigation during upgrade
                this.beforeUnloadHandler = (event) => {
                    event.preventDefault();
                    event.returnValue = '';
                };
                window.addEventListener('beforeunload', this.beforeUnloadHandler);
            },

            startTimer() {
                this.startTime = Date.now();
                this.elapsedInterval = setInterval(() => {
                    this.elapsedTime = Math.floor((Date.now() - this.startTime) / 1000);
                }, 1000);
            },

            formatElapsedTime() {
                const minutes = Math.floor(this.elapsedTime / 60);
                const seconds = this.elapsedTime % 60;
                return `${minutes}:${seconds.toString().padStart(2, '0')}`;
            },

            mapStepToUI(apiStep) {
                // Map backend steps (1-6) to UI steps (1-4)
                // Backend: 1=config, 2=env, 3=pull, 4=stop, 5=start, 6=complete
                // UI: 1=prepare, 2=pull images, 3=pull coolify, 4=restart
                if (apiStep <= 2) return 1;
                if (apiStep === 3) return 2;
                if (apiStep <= 5) return 3;
                return 4;
            },

            startHealthWatch() {
                if (this.checkHealthInterval) {
                    return;
                }
                this.checkHealthInterval = setInterval(() => {
                    this.probeHealth();
                }, 2000);
            },

            async probeHealth() {
                this.healthCheckAttempts++;
                const elapsedMinutes = Math.floor((Date.now() - this.startTime) / 60000);

                try {
                    const response = await fetch('/api/health');
                    if (!response.ok) {
                        this.instanceWentDown = true;
                        this.currentStep = 4;
                        this.currentStatus = this.getReviveStatusMessage(elapsedMinutes, this.healthCheckAttempts);
                        return;
                    }
                    if (!this.instanceWentDown && this.currentStep < 4) {
                        return;
                    }

                    let data;
                    try {
                        data = await this.$wire.getUpgradeStatus();
                    } catch (error) {
                        if (this.instanceWentDown) {
                            this.showSuccess();
                            return;
                        }
                        throw error;
                    }
                    if (!data) {
                        if (this.instanceWentDown) {
                            // The upgraded instance can no longer return data for the old Livewire component.
                            // A healthy response after observed downtime confirms that the restart completed.
                            this.showSuccess();
                        } else {
                            this.currentStatus = this.getReviveStatusMessage(elapsedMinutes, this.healthCheckAttempts);
                        }
                        return;
                    }
                    if (data.status === 'complete') {
                        this.showSuccess();
                    } else if (data.status === 'error') {
                        this.showError(data.message);
                    } else if (data.status === 'none' && this.instanceWentDown) {
                        // Older target releases cannot report the new upgrade status.
                        // A failed health probe followed by a healthy response proves the restart completed.
                        this.showSuccess();
                    } else {
                        this.currentStatus = data.message ?? this.getReviveStatusMessage(elapsedMinutes, this.healthCheckAttempts);
                    }
                } catch (error) {
                    console.error('Health check failed:', error);
                    this.instanceWentDown = true;
                    this.currentStep = 4;
                    this.currentStatus = this.getReviveStatusMessage(elapsedMinutes, this.healthCheckAttempts);
                }
            },

            getReviveStatusMessage(elapsedMinutes, attempts) {
                if (elapsedMinutes === 0) {
                    return `Waiting for Coolify to come back online... (attempt ${attempts})`;
                } else if (elapsedMinutes < 2) {
                    return `Waiting for Coolify to come back online... (${elapsedMinutes} minute${elapsedMinutes !== 1 ? 's' : ''} elapsed)`;
                } else if (elapsedMinutes < 5) {
                    return `Update in progress, this may take several minutes... (${elapsedMinutes} minutes elapsed)`;
                } else if (elapsedMinutes < 10) {
                    return `Large updates can take 10+ minutes. Please be patient... (${elapsedMinutes} minutes elapsed)`;
                } else {
                    return `Still updating. If this takes longer than 15 minutes, please check server logs... (${elapsedMinutes} minutes elapsed)`;
                }
            },

            revive() {
                this.currentStep = 4;
                console.log('Checking server\'s health...');
                this.startHealthWatch();
            },

            showSuccess() {
                if (this.checkHealthInterval) {
                    clearInterval(this.checkHealthInterval);
                    this.checkHealthInterval = null;
                }
                if (this.checkUpgradeStatusInterval) {
                    clearInterval(this.checkUpgradeStatusInterval);
                    this.checkUpgradeStatusInterval = null;
                }
                if (this.elapsedInterval) {
                    clearInterval(this.elapsedInterval);
                    this.elapsedInterval = null;
                }
                // Remove beforeunload handler now that upgrade is complete
                if (this.beforeUnloadHandler) {
                    window.removeEventListener('beforeunload', this.beforeUnloadHandler);
                    this.beforeUnloadHandler = null;
                }

                this.upgradeComplete = true;
                this.currentStep = 5;
                this.currentStatus = `Successfully upgraded to ${this.latestVersion}`;
                this.successCountdown = 3;

                const countdownInterval = setInterval(() => {
                    this.successCountdown--;
                    if (this.successCountdown <= 0) {
                        clearInterval(countdownInterval);
                        window.location.reload();
                    }
                }, 1000);
            },

            reloadNow() {
                window.location.reload();
            },

            showError(message) {
                // Stop all intervals
                if (this.checkHealthInterval) {
                    clearInterval(this.checkHealthInterval);
                    this.checkHealthInterval = null;
                }
                if (this.checkUpgradeStatusInterval) {
                    clearInterval(this.checkUpgradeStatusInterval);
                    this.checkUpgradeStatusInterval = null;
                }
                if (this.elapsedInterval) {
                    clearInterval(this.elapsedInterval);
                    this.elapsedInterval = null;
                }
                // Remove beforeunload handler so user can close modal
                if (this.beforeUnloadHandler) {
                    window.removeEventListener('beforeunload', this.beforeUnloadHandler);
                    this.beforeUnloadHandler = null;
                }

                this.upgradeError = true;
                this.currentStatus = `Error: ${message}`;
            },

            closeErrorModal() {
                this.modalOpen = false;
                this.showProgress = false;
                this.upgradeError = false;
                this.currentStatus = '';
                this.currentStep = 0;
            },

            upgrade() {
                if (this.checkUpgradeStatusInterval) return true;
                this.currentStep = 1;
                this.currentStatus = 'Starting upgrade...';
                this.serviceDown = false;
                this.instanceWentDown = false;
                this.livewireFailures = 0;
                this.startHealthWatch();

                // Poll upgrade status via Livewire
                this.checkUpgradeStatusInterval = setInterval(async () => {
                    try {
                        const data = await this.$wire.getUpgradeStatus();
                        this.livewireFailures = 0;
                        if (data.status === 'in_progress') {
                            this.currentStep = this.mapStepToUI(data.step);
                            this.currentStatus = data.message;
                        } else if (data.status === 'complete') {
                            this.showSuccess();
                        } else if (data.status === 'error') {
                            this.showError(data.message);
                        } else if (data.status === 'none' && this.instanceWentDown) {
                            this.revive();
                            await this.probeHealth();
                        }
                    } catch (error) {
                        this.livewireFailures++;
                        if (this.livewireFailures < 3) {
                            this.currentStatus = 'Reconnecting. This is expected during an upgrade...';
                            return;
                        }
                        // Repeated Livewire failures usually mean the instance is restarting
                        console.log('Livewire unavailable, switching to health check mode');
                        if (!this.serviceDown) {
                            this.serviceDown = true;
                            this.instanceWentDown = true;
                            this.currentStep = 4;
                            this.currentStatus = 'Coolify is restarting with the new version...';
                            if (this.checkUpgradeStatusInterval) {
                                clearInterval(this.checkUpgradeStatusInterval);
                                this.checkUpgradeStatusInterval = null;
                            }
                            this.revive();
                        }
                    }
                }, 2000);
            }
        }))
    })
</script>
