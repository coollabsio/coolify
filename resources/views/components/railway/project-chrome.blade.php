@props([
    'project',
    'environment',
    'projects' => null,
    'environments' => null,
    'active' => 'architecture',
])

{{-- The in-project shell: top bar + left icon rail + content area. --}}
<div class="flex flex-col h-screen w-full overflow-hidden">
    <x-railway.topbar :project="$project" :environment="$environment" :projects="$projects" :environments="$environments" />
    <div class="flex flex-1 min-h-0">
        <x-railway.rail :project="$project" :environment="$environment" :active="$active" />
        <main class="relative flex-1 min-w-0 min-h-0 overflow-hidden">
            {{ $slot }}
        </main>
    </div>
</div>
