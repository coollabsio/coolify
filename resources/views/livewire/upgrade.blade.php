<div x-data wire:click='upgrade' x-on:click="upgrade" @class([
    'bg-gradient-to-r from-purple-500 via-pink-500 to-red-500' => !$showProgress,
    'hover:bg-transparent focus:bg-transparent' => $showProgress,
])>
    <button>
        @if ($showProgress)
            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-pink-500 lds-heart" viewBox="0 0 24 24"
                stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                <path d="M19.5 13.572l-7.5 7.428l-7.5 -7.428m0 0a5 5 0 1 1 7.5 -6.566a5 5 0 1 1 7.5 6.572" />
            </svg>
        @else
            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-white " viewBox="0 0 24 24" stroke-width="1.5"
                stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                <path
                    d="M9 12h-3.586a1 1 0 0 1 -.707 -1.707l6.586 -6.586a1 1 0 0 1 1.414 0l6.586 6.586a1 1 0 0 1 -.707 1.707h-3.586v3h-6v-3z" />
                <path d="M9 21h6" />
                <path d="M9 18h6" />
            </svg>
        @endif
    </button>
    <script>
        let checkHealthInterval = null;
        let checkIfIamDeadInterval = null;

        function revive() {
            console.log('Checking server\'s health...')
            checkHealthInterval = setInterval(() => {
                fetch('/api/health')
                    .then(response => {
                        if (response.ok) {
                            Toaster.success('Coolify is back online. Reloading...')
                            if (checkHealthInterval) clearInterval(checkHealthInterval);
                            setTimeout(() => {
                                window.location.reload();
                            }, 5000)
                        } else {
                            console.log('Waiting for server to come back from dead...');
                        }
                    })
                return;
            }, 2000);
        }

        function upgrade() {
            console.log('Update initiated.')
            checkIfIamDeadInterval = setInterval(() => {
                fetch('/api/health')
                    .then(response => {
                        if (response.ok) {
                            console.log('It\'s alive. Waiting for server to be dead...');
                        } else {
                            Toaster.success('Update done, restarting Coolify!')
                            console.log('It\'s dead. Reviving... Standby... Bzz... Bzz...')
                            if (checkIfIamDeadInterval) clearInterval(checkIfIamDeadInterval);
                            revive();
                        }
                    })
                return;
            }, 2000);
        }
    </script>
</div>
