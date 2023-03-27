<x-layout>
    <h1>
        Coolify v4 🎉
    </h1>
    <h2>Projects</h2>
    <ul>
        @forelse ($projects as $project)
            <li>
                <p>Project Name: {{ $project->name }}</p>
                <p>Project Settings:{{ $project->settings }}</p>
                <h2>Environments</h2>
                @forelse ($project->environments as $environment)
                    <p>Environment Name: {{ $environment->name }}</p>
                    <p>Applications: {{ $environment->applications }}</p>
                    <p>Databases: {{ $environment->databases }}</p>
                @empty
                    <p>No environments found</p>
                @endforelse
            </li>
        @empty
            <li>No projects found</li>
        @endforelse
    </ul>
</x-layout>
