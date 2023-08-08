<x-layout>
    @if ($type === 'public')
        <livewire:project.new.public-git-repository :type="$type"/>
    @elseif ($type === 'private-gh-app')
        <livewire:project.new.github-private-repository :type="$type"/>
    @elseif ($type === 'private-deploy-key')
        <livewire:project.new.github-private-repository-deploy-key :type="$type"/>
    @else
        <livewire:project.new.select/>
    @endif
</x-layout>
