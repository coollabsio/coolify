<div>
    @if (session('error'))
        <span x-data x-init="$wire.emit('error', '{{ session('error') }}')"/>
    @endif
    <h1>Dashboard</h1>
    <div class="subtitle">Something <x-highlighted text="(more)" /> useful will be here.</div>
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
</div>
