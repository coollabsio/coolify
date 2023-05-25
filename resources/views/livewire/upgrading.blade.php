<div x-data="{ visible: @entangle('visible') }" class="fixed text-xs text-white top-2 right-28">
    <template x-if="visible">
        <div>
            <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10 mx-auto lds-heart text-warning" viewBox="0 0 24 24"
                stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                <path d="M19.5 13.572l-7.5 7.428l-7.5 -7.428m0 0a5 5 0 1 1 7.5 -6.566a5 5 0 1 1 7.5 6.572" />
            </svg> Upgrading...
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
</div>
