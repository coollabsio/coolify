<div>
    @if (session('error'))
        <span x-data x-init="$wire.emit('error', '{{ session('error') }}')" />
    @endif
    <h1>Dashboard</h1>
    <div class="subtitle">Something <x-highlighted text="(more)" /> useful will be here.</div>
    @if (request()->query->get('success'))
        <div class="rounded alert alert-success">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 stroke-current shrink-0" fill="none"
                viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span>Your subscription has been activated! Welcome onboard!</span>
        </div>
    @endif
    <div class="w-full rounded stats stats-vertical lg:stats-horizontal">
        <div class="stat">
            <div class="stat-title">Servers</div>
            <div class="stat-value">{{ $servers }}</div>
        </div>

        <div class="stat">
            <div class="stat-title">Projects</div>
            <div class="stat-value">{{ $projects }}</div>
        </div>

        <div class="stat">
            <div class="stat-title">Resources</div>
            <div class="stat-value">{{ $resources }}</div>
            <div class="stat-desc">Applications, databases, etc...</div>
        </div>
        <div class="stat">
            <div class="stat-title">S3 Storages</div>
            <div class="stat-value">{{ $s3s }}</div>
        </div>
    </div>
    {{-- <x-forms.button wire:click='getIptables'>Get IPTABLES</x-forms.button> --}}
</div>
