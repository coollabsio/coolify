<div>
    <x-slot:title>
        {{ data_get_str($project, 'name')->limit(10) }} > New | Coolify
    </x-slot>
    @if ($pendingType)
        <div wire:init="createResource" class="mx-auto flex max-w-md flex-col items-center pt-24 text-center">
            @if ($pendingLogo)
                <img src="{{ $pendingLogo }}" alt="" class="mb-4 size-10 object-contain" />
            @else
                <x-reicon name="database" class="mb-4 size-8 text-neutral-400 dark:text-fg-faint" />
            @endif
            <h2 class="empty-state-title">Creating {{ $pendingName }}</h2>
            <p class="empty-state-description text-[13px]">Setting up the resource. You will be redirected when it is ready.</p>
            <x-loading text="Creating..." class="mt-4" />
        </div>
    @elseif ($type === 'public')
        <livewire:project.new.public-git-repository :type="$type" />
    @elseif ($type === 'private-gh-app')
        <livewire:project.new.github-private-repository :type="$type" />
    @elseif ($type === 'private-gitlab-app')
        <livewire:project.new.gitlab-private-repository :type="$type" />
    @elseif ($type === 'private-gitea-app')
        <livewire:project.new.gitea-private-repository :type="$type" />
    @elseif ($type === 'private-deploy-key')
        <livewire:project.new.github-private-repository-deploy-key :type="$type" />
    @elseif ($type === 'dockerfile')
        <livewire:project.new.simple-dockerfile :type="$type" />
    @elseif ($type === 'docker-compose-empty')
        <livewire:project.new.docker-compose :type="$type" />
    @elseif ($type === 'docker-image')
        <livewire:project.new.docker-image :type="$type" />
    @else
        <livewire:project.new.select />
    @endif
</div>
