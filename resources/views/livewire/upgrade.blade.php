<div @if ($isUpgradeAvailable) title="New version available" @else title="No upgrade available" @endif
    x-init="$wire.checkUpdate" x-data="upgradeModal({
        currentVersion: @js($currentVersion),
        latestVersion: @js($latestVersion)
    })">
    @if ($isUpgradeAvailable)
        <div :class="{ 'z-40': modalOpen }" class="relative w-auto h-auto">
            <button class="menu-item" @click="modalOpen=true" x-show="showProgress">
                <svg xmlns="http://www.w3.org/2000/svg"
                    class="w-6 h-6 text-pink-500 transition-colors hover:text-pink-300 lds-heart" viewBox="0 0 24 24"
                    stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                    <path d="M19.5 13.572l-7.5 7.428l-7.5 -7.428m0 0a5 5 0 1 1 7.5 -6.566a5 5 0 1 1 7.5 6.572" />
                </svg>
                In progress
            </button>
            <button class="menu-item cursor-pointer" @click="modalOpen=true" x-show="!showProgress">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-pink-500 transition-colors hover:text-pink-300"
                    viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                    stroke-linejoin="round">
                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                    <path
                        d="M9 12h-3.586a1 1 0 0 1 -.707 -1.707l6.586 -6.586a1 1 0 0 1 1.414 0l6.586 6.586a1 1 0 0 1 -.707 1.707h-3.586v3h-6v-3z" />
                    <path d="M9 21h6" />
                    <path d="M9 18h6" />
                </svg>
                Upgrade
            </button>
            <template x-teleport="body">
                <div x-show="modalOpen"
                    class="fixed top-0 lg:pt-10 left-0 z-99 flex items-start justify-center w-screen h-screen" x-cloak>
                    <div x-show="modalOpen" x-transition:enter="ease-out duration-100" x-transition:enter-start="opacity-0"
                        x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-100"
                        x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                        class="absolute inset-0 w-full h-full bg-black/20 backdrop-blur-xs"></div>
                    <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen" x-transition:enter="ease-out duration-100"
                        x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave="ease-in duration-100"
                        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                        class="relative w-full py-6 border rounded-sm min-w-full lg:min-w-[36rem] max-w-fit bg-neutral-100 border-neutral-400 dark:bg-base px-7 dark:border-coolgray-300">

                        {{-- Header --}}
                        <div class="flex items-center justify-between pb-3">
                            <div>
                                <h3 class="text-lg font-semibold"
                                    x-text="upgradeComplete ? 'Upgrade Complete!' : (showProgress ? 'Upgrading...' : 'Upgrade Available')">
                                </h3>
                                <div class="text-sm text-neutral-500 dark:text-neutral-400">
                                    {{ $currentVersion }} <span class="mx-1">&rarr;</span> {{ $latestVersion }}
                                </div>
                            </div>
                            <button x-show="!showProgress" @click="modalOpen=false"
                                class="absolute top-0 right-0 flex items-center justify-center w-8 h-8 mt-5 mr-5 text-gray-600 rounded-full hover:text-gray-800 hover:bg-gray-50 dark:text-neutral-400 dark:hover:text-white dark:hover:bg-coolgray-300">
                                <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                    stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        {{-- Content --}}
                        <div class="relative w-auto pb-6">
                            {{-- Progress View --}}
                            <template x-if="showProgress">
                                <div class="space-y-6">
                                    {{-- Step Progress Indicator --}}
                                    <div class="pt-2">
                                        <x-upgrade-progress />
                                    </div>

                                    {{-- Elapsed Time --}}
                                    <div class="text-center">
                                        <span class="text-sm text-neutral-500 dark:text-neutral-400">Elapsed time:</span>
                                        <span class="ml-2 font-mono text-sm" x-text="formatElapsedTime()"></span>
                                    </div>

                                    {{-- Current Status Message --}}
                                    <div class="p-4 rounded-lg bg-neutral-200 dark:bg-coolgray-200">
                                        <div class="flex items-center gap-3">
                                            <template x-if="!upgradeComplete">
                                                <svg class="w-5 h-5 text-warning animate-spin"
                                                    xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                        stroke-width="4"></circle>
                                                    <path class="opacity-75" fill="currentColor"
                                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                                    </path>
                                                </svg>
                                            </template>
                                            <template x-if="upgradeComplete">
                                                <svg class="w-5 h-5 text-success" xmlns="http://www.w3.org/2000/svg"
                                                    viewBox="0 0 20 20" fill="currentColor">
                                                    <path fill-rule="evenodd"
                                                        d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                                                        clip-rule="evenodd" />
                                                </svg>
                                            </template>
                                            <span x-text="currentStatus" class="text-sm"></span>
                                        </div>
                                    </div>

                                    {{-- Success State with Countdown --}}
                                    <template x-if="upgradeComplete">
                                        <div class="flex flex-col items-center gap-4">
                                            <p class="text-sm text-neutral-500 dark:text-neutral-400">
                                                Reloading in <span x-text="successCountdown"
                                                    class="font-bold text-warning"></span> seconds...
                                            </p>
                                            <x-forms.button @click="reloadNow()" type="button">
                                                Reload Now
                                            </x-forms.button>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            {{-- Confirmation View --}}
                            <template x-if="!showProgress">
                                <div class="space-y-4">
                                    {{-- Warning --}}
                                    <x-callout type="warning" title="Caution">
                                        <p>Any deployments running during the update process will
                                            fail.
                                        </p>
                                    </x-callout>

                                    {{-- Help Links --}}
                                    <p class="text-sm text-neutral-600 dark:text-neutral-400">
                                        If something goes wrong, check the
                                        <a class="font-medium underline dark:text-white hover:text-neutral-800 dark:hover:text-neutral-300"
                                            href="https://coolify.io/docs/upgrade" target="_blank">upgrade guide</a> or the
                                        logs on the server at /data/coolify/source/upgrade*.
                                    </p>
                                </div>
                            </template>
                        </div>

                        {{-- Footer Actions --}}
                        <div class="flex gap-4" x-show="!showProgress">
                            <x-forms.button @click="modalOpen=false"
                                class="w-24 dark:bg-coolgray-200 dark:hover:bg-coolgray-300">Cancel
                            </x-forms.button>
                            <div class="flex-1"></div>
                            <x-forms.button @click="confirmed" class="w-32" isHighlighted type="button">
                                Upgrade Now
                            </x-forms.button>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    @endif
</div>

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('upgradeModal', (config) => ({
            modalOpen: false,
            showProgress: false,
            currentStatus: '',
            checkHealthInterval: null,
            checkIfIamDeadInterval: null,
            elapsedInterval: null,
            healthCheckAttempts: 0,
            startTime: null,
            elapsedTime: 0,
            currentStep: 0,
            upgradeComplete: false,
            successCountdown: 3,
            currentVersion: config.currentVersion || '',
            latestVersion: config.latestVersion || '',

            confirmed() {
                this.showProgress = true;
                this.currentStep = 1;
                this.startTimer();
                this.$wire.$call('upgrade');
                this.upgrade();
                window.addEventListener('beforeunload', (event) => {
                    event.preventDefault();
                    event.returnValue = '';
                });
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

            getStepMessage(step) {
                const messages = {
                    1: 'Preparing upgrade...',
                    2: 'Pulling helper image...',
                    3: 'Pulling Coolify image...',
                    4: 'Restarting Coolify...'
                };
                return messages[step] || 'Processing...';
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
                if (this.checkHealthInterval) return true;
                this.healthCheckAttempts = 0;
                this.currentStep = 4;
                console.log('Checking server\'s health...');
                this.checkHealthInterval = setInterval(() => {
                    this.healthCheckAttempts++;
                    const elapsedMinutes = Math.floor((Date.now() - this.startTime) / 60000);
                    fetch('/api/health')
                        .then(response => {
                            if (response.ok) {
                                this.showSuccess();
                            } else {
                                this.currentStatus = this.getReviveStatusMessage(elapsedMinutes, this.healthCheckAttempts);
                            }
                        })
                        .catch(error => {
                            console.error('Health check failed:', error);
                            this.currentStatus = this.getReviveStatusMessage(elapsedMinutes, this.healthCheckAttempts);
                        });
                }, 2000);
            },

            showSuccess() {
                if (this.checkHealthInterval) {
                    clearInterval(this.checkHealthInterval);
                    this.checkHealthInterval = null;
                }
                if (this.elapsedInterval) {
                    clearInterval(this.elapsedInterval);
                    this.elapsedInterval = null;
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

            upgrade() {
                if (this.checkIfIamDeadInterval) return true;
                this.currentStep = 1;
                this.currentStatus = this.getStepMessage(1);

                // Simulate step progression (since we can't get real-time feedback from Docker pulls)
                let stepTime = 0;
                const stepInterval = setInterval(() => {
                    stepTime++;
                    // Progress through steps based on elapsed time
                    if (stepTime >= 3 && this.currentStep === 1) {
                        this.currentStep = 2;
                        this.currentStatus = this.getStepMessage(2);
                    } else if (stepTime >= 8 && this.currentStep === 2) {
                        this.currentStep = 3;
                        this.currentStatus = this.getStepMessage(3);
                    }
                }, 1000);

                this.checkIfIamDeadInterval = setInterval(() => {
                    fetch('/api/health')
                        .then(response => {
                            if (response.ok) {
                                // Still running, update status based on current step
                                this.currentStatus = this.getStepMessage(this.currentStep);
                            } else {
                                // Service is down, now waiting to revive
                                clearInterval(stepInterval);
                                this.currentStep = 4;
                                this.currentStatus = 'Coolify is restarting with the new version...';
                                if (this.checkIfIamDeadInterval) {
                                    clearInterval(this.checkIfIamDeadInterval);
                                    this.checkIfIamDeadInterval = null;
                                }
                                this.revive();
                            }
                        })
                        .catch(error => {
                            console.error('Health check failed:', error);
                            clearInterval(stepInterval);
                            this.currentStep = 4;
                            this.currentStatus = 'Coolify is restarting with the new version...';
                            if (this.checkIfIamDeadInterval) {
                                clearInterval(this.checkIfIamDeadInterval);
                                this.checkIfIamDeadInterval = null;
                            }
                            this.revive();
                        });
                }, 2000);
            }
        }))
    })
</script>