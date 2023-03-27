<x-layout>
    <h1>
        Coolify v4 🎉
    </h1>
    <h1>Projects</h1>
    <ul>
        @forelse ($projects as $project)
                <h2>{{ $project->name }}</h2>
                <p>Project Settings:{{ $project->settings }}</p>
                <h2>Environments</h2>
                @forelse ($project->environments as $environment)
                    <p>Environment Name: {{ $environment->name }}</p>
                    <h2>Applications</h2>
                    @forelse ($environment->applications as $application)
                        <h3>{{ $application->name }}</h3>
                        <p>Application: {{ $application }}</p>
                        <p>Destination Class: {{ $application->destination->getMorphClass() }}</p>
                    @empty
            <li>No application found</li>
        @endforelse
        <h2>Databases</h2>
        @forelse ($environment->databases as $database)
            <h3>{{ $database->name }}</h3>
            <p>Database: {{ $database }}</p>
        @empty
            <li>No database found</li>
        @endforelse
    @empty
        <p>No environments found</p>
        @endforelse
    @empty
        <li>No projects found</li>
        @endforelse
    </ul>
</x-layout>
