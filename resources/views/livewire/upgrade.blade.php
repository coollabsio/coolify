<div @if ($isUpgradeAvailable) title="New version available" @else title="No upgrade available" @endif
    x-init="$wire.checkUpdate" x-data="upgradeModal">
    @if ($isUpgradeAvailable)
        <div :class="{ 'z-40': modalOpen }" class="relative w-auto h-auto">
            <button class="menu-item" @click="modalOpen=true">
                @if ($showProgress)
                    <svg xmlns="http://www.w3.org/2000/svg"
                        class="w-6 h-6 text-pink-500 transition-colors hover:text-pink-300 lds-heart" viewBox="0 0 24 24"
                        stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                        <path d="M19.5 13.572l-7.5 7.428l-7.5 -7.428m0 0a5 5 0 1 1 7.5 -6.566a5 5 0 1 1 7.5 6.572" />
                    </svg>
                    In progress
                @else
                    <svg xmlns="http://www.w3.org/2000/svg"
                        class="w-6 h-6 text-pink-500 transition-colors hover:text-pink-300" viewBox="0 0 24 24"
                        stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                        <path
                            d="M9 12h-3.586a1 1 0 0 1 -.707 -1.707l6.586 -6.586a1 1 0 0 1 1.414 0l6.586 6.586a1 1 0 0 1 -.707 1.707h-3.586v3h-6v-3z" />
                        <path d="M9 21h6" />
                        <path d="M9 18h6" />
                    </svg>
                    Upgrade
                @endif
            </button>

            <template x-teleport="body">
                <div x-show="modalOpen"
                    class="fixed top-0 lg:pt-10 left-0 z-[99] flex items-start justify-center w-screen h-screen"
                    x-cloak>
                    <div x-show="modalOpen" x-transition:enter="ease-out duration-100"
                        x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                        x-transition:leave="ease-in duration-100" x-transition:leave-start="opacity-100"
                        x-transition:leave-end="opacity-0"
                        class="absolute inset-0 w-full h-full bg-black bg-opacity-20 backdrop-blur-sm"></div>
                    <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen" x-transition:enter="ease-out duration-100"
                        x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave="ease-in duration-100"
                        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                        class="relative w-full py-6 border rounded min-w-full lg:min-w-[36rem] max-w-fit bg-neutral-100 border-neutral-400 dark:bg-base px-7 dark:border-coolgray-300">
                        <div class="flex items-center justify-between pb-3">
                            <h3 class="text-lg font-semibold">Upgrade confirmation</h3>
                            @if (!$showProgress)
                                <button @click="modalOpen=false"
                                    class="absolute top-0 right-0 flex items-center justify-center w-8 h-8 mt-5 mr-5 text-gray-600 rounded-full hover:text-gray-800 hover:bg-gray-50">
                                    <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none"
                                        viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            @endif
                        </div>
                        <div class="relative w-auto pb-8">
                            <p>Are you sure you would like to upgrade your instance to {{ $latestVersion }}?</p>
                            <br />
                            <p>You can review the changelogs <a class="font-bold underline dark:text-white"
                                    href="https://github.com/coollabsio/coolify/releases" target="_blank">here</a>.</p>
                            <br />
                            <p>If something goes wrong and you cannot upgrade your instance, You can check the following
                                <a class="font-bold underline dark:text-white" href="https://coolify.io/docs/upgrade"
                                    target="_blank">guide</a> on what to do.
                            </p>
                            @if ($showProgress)
                                <div class="flex flex-col pt-4">
                                    <h2>Progress <x-loading /></h2>
                                    <div x-html="currentStatus"></div>
                                </div>
                            @endif
                        </div>
                        <div class="flex gap-4">
                            @if (!$showProgress)
                                <x-forms.button @click="modalOpen=false"
                                    class="w-24 dark:bg-coolgray-200 dark:hover:bg-coolgray-300">Cancel
                                </x-forms.button>
                                <div class="flex-1"></div>
                                <x-forms.button @click="confirmed" class="w-24" isHighlighted type="button">Continue
                                </x-forms.button>
                            @endif
                        </div>
                    </div>
                </div>
            </template>
        </div>
    @endif
</div>

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('upgradeModal', () => ({
            modalOpen: false,
            showProgress: @js($showProgress),
            currentStatus: '',
            confirmed() {
                this.$wire.$call('upgrade')
                this.upgrade();
                this.$wire.showProgress = true;
                window.addEventListener('beforeunload', (event) => {
                    event.preventDefault();
                    event.returnValue = '';
                });
            },
            revive() {
                if (checkHealthInterval) return true;
                console.log('Checking server\'s health...')
                checkHealthInterval = setInterval(() => {
                    fetch('/api/health')
                        .then(response => {
                            if (response.ok) {
                                this.currentStatus =
                                    'Coolify is back online. Reloading this page (you can manually reload if its not done automatically)...';
                                if (checkHealthInterval) clearInterval(
                                    checkHealthInterval);
                                setTimeout(() => {
                                    window.location.reload();
                                }, 5000)
                            } else {
                                this.currentStatus =
                                    "Waiting for Coolify to come back from dead..."
                            }
                        })
                }, 2000);
            },
            upgrade() {
                if (checkIfIamDeadInterval || this.$wire.showProgress) return true;
                this.currentStatus = 'Pulling new images and updating Coolify.';
                checkIfIamDeadInterval = setInterval(() => {
                    fetch('/api/health')
                        .then(response => {
                            if (response.ok) {
                                this.currentStatus = "Waiting for the update process..."
                            } else {
                                this.currentStatus =
                                    "Update done, restarting Coolify & waiting until it is revived!"
                                if (checkIfIamDeadInterval) clearInterval(
                                    checkIfIamDeadInterval);
                                this.revive();
                            }
                        })
                }, 2000);
            }

        }))
    })
</script>
