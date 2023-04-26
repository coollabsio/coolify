<x-layout>
    @if ($type === 'project')
        <h1>New Project</h1>
    @elseif ($type === 'resource')
        <h1>New Resource</h1>
    @endif
    <div x-data="{ activeTab: 'choose' }">
        <div class="flex flex-col w-64 gap-2 mb-10">
            <button @click.prevent="activeTab = 'public-repo'">Public Repository</button>
            <button @click.prevent="activeTab = 'github-private-repo'">Private Repository (GitHub App)</button>
            @if ($type === 'project')
                <livewire:project.new.empty-project />
            @endif
        </div>

        <div x-cloak x-show="activeTab === 'public-repo'">
            <livewire:project.new.public-git-repository :type="$type" />
        </div>
        <div x-cloak x-show="activeTab === 'github-private-repo'">
            github-private-repo
        </div>
        <div x-cloak x-show="activeTab === 'choose'">
            Choose any option
        </div>
    </div>
</x-layout>
