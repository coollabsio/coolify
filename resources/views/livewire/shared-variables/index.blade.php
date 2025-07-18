<div>
    <x-slot:title>
        Shared Variables | Coolify
    </x-slot>
    <div class="flex items-start gap-2">
        <h1>Shared Variables</h1>
    </div>
    <div class="subtitle">Set Team / Project / Environment wide variables.</div>

    <div class="flex flex-col gap-2">
        <a class="box group" href="{{ route('shared-variables.team.index') }}">
            <div class="flex flex-col justify-center mx-6">
                <div class="box-title">Team wide</div>
                <div class="box-description">Usable for all resources in a team.</div>
            </div>
        </a>
        <a class="box group" href="{{ route('shared-variables.project.index') }}">
            <div class="flex flex-col justify-center mx-6">
                <div class="box-title">Project wide</div>
                <div class="box-description">Usable for all resources in a project.</div>
            </div>
        </a>
        <a class="box group" href="{{ route('shared-variables.environment.index') }}">
            <div class="flex flex-col justify-center mx-6">
                <div class="box-title">Environment wide</div>
                <div class="box-description">Usable for all resources in an environment.</div>
            </div>
        </a>

    </div>
</div>
