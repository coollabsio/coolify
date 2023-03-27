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
                    <h2>Applications</h2>
                    @forelse ($environment->applications as $application)
                        <p>Application: {{ $application->name }}</p>
                        <p>Destination: {{ $application->destination }}</p>
                        <p>Destination Class: {{ $application->destination->getMorphClass() }}</p>
                        <livewire:temporary-check-status :application_id="$application->id" />
                    @empty
            <li>No application found</li>
        @endforelse
        <h2>Databases</h2>
        @forelse ($environment->databases as $database)
            <p>Database: {{ $database }}</p>
        @empty
            <li>No database found</li>
        @endforelse
    @empty
        <p>No environments found</p>
        @endforelse
        </li>
    @empty
        <li>No projects found</li>
        @endforelse
    </ul>
</x-layout>
