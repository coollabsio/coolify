<x-layout>
    <h1>Dashboard</h1>
    <div class="pt-2 pb-10 text-sm">Something (more) useful will be here.</div>
    <div class="w-full rounded shadow stats stats-vertical lg:stats-horizontal">
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
    </div>
</x-layout>
