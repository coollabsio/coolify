<div>
    <x-slot:title>
        {{ data_get_str($project, 'name')->limit(10) }} > New | Coolify
    </x-slot>
    @if ($type === 'public')
        <livewire:project.new.public-git-repository :type="$type" />
    @elseif ($type === 'private-gh-app')
        <livewire:project.new.github-private-repository :type="$type" />
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
