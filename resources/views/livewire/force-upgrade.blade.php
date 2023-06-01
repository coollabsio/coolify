<div class="flex gap-10 text-xs text-white" x-data="{ visible: @entangle('visible') }">
    <button x-cloak x-show="!visible"
        class="gap-2 text-white normal-case btn btn-ghost hover:no-underline bg-coollabs hover:bg-coollabs-100"
        wire:click='upgrade'>
        <svg xmlns="http://www.w3.org/2000/svg" class="icon" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
            fill="none" stroke-linecap="round" stroke-linejoin="round">
            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
            <path d="M10 20.777a8.942 8.942 0 0 1 -2.48 -.969" />
            <path d="M14 3.223a9.003 9.003 0 0 1 0 17.554" />
            <path d="M4.579 17.093a8.961 8.961 0 0 1 -1.227 -2.592" />
            <path d="M3.124 10.5c.16 -.95 .468 -1.85 .9 -2.675l.169 -.305" />
            <path d="M6.907 4.579a8.954 8.954 0 0 1 3.093 -1.356" />
            <path d="M12 9l-2 3h4l-2 3" />
        </svg>Force Upgrade
    </button>
    <template x-if="visible">
        <div class="bg-coollabs-gradient">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10 mx-auto text-pink-500 lds-heart"
                viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                stroke-linejoin="round">
                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                <path d="M19.5 13.572l-7.5 7.428l-7.5 -7.428m0 0a5 5 0 1 1 7.5 -6.566a5 5 0 1 1 7.5 6.572" />
            </svg> Upgrading, please wait...
            <script>
                function checkHealth() {
                    console.log('Checking server\'s health...')
                    checkHealthInterval = setInterval(async () => {
                        try {
                            const res = await fetch('/api/health');
                            if (res.ok) {
                                console.log('Server is back online. Reloading...')
                                if (checkHealthInterval) clearInterval(checkHealthInterval);
                                window.location.reload();
                            }
                        } catch (error) {
                            console.log('Waiting for server to come back from dead...');
                        }

                        return;
                    }, 2000);
                }

                function checkIfIamDead() {
                    console.log('Checking server\'s pulse...')
                    checkIfIamDeadInterval = setInterval(async () => {
                        try {
                            const res = await fetch('/api/health');
                            if (res.ok) {
                                console.log('I\'m alive. Waiting for server to be dead...');
                            }
                        } catch (error) {
                            console.log('I\'m dead. Charging... Standby... Bzz... Bzz...')
                            checkHealth();
                            if (checkIfIamDeadInterval) clearInterval(checkIfIamDeadInterval);
                        }

                        return;
                    }, 2000);
                }
                let checkHealthInterval = null;
                let checkIfIamDeadInterval = null;
                console.log('Update initiated. Waiting for server to be dead...')
                checkIfIamDead();
            </script>
        </div>
        <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 mx-auto lds-heart" viewBox="0 0 24 24" stroke-width="1.5"
            stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
            <path d="M19.5 12.572l-7.5 7.428l-7.5 -7.428a5 5 0 1 1 7.5 -6.566a5 5 0 1 1 7.5 6.572" />
            <path d="M12 6l-2 4l4 3l-2 4v3" />
        </svg>
    </template>

    {{-- <livewire:upgrading /> --}}
</div>
