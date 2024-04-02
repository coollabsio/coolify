<div @if ($isUpgradeAvailable) title="New version available" @else title="No upgrade available" @endif
    x-init="$wire.checkUpdate" x-data>
    @if ($isUpgradeAvailable)
        <button wire:key="upgrade" wire:click='upgrade' class="menu-item" x-on:click="upgrade">
            @if ($showProgress)
                <svg xmlns="http://www.w3.org/2000/svg"
                    class="w-6 h-6 text-pink-500 transition-colors hover:text-pink-300 lds-heart" viewBox="0 0 24 24"
                    stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                    stroke-linejoin="round">
                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                    <path d="M19.5 13.572l-7.5 7.428l-7.5 -7.428m0 0a5 5 0 1 1 7.5 -6.566a5 5 0 1 1 7.5 6.572" />
                </svg>
                Inprogress
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
    @endif
</div>
