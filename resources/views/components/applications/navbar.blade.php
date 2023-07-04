<div class="flex items-end gap-4 py-2 border-b-2 border-solid border-coolgray-200">
    <a class="{{ request()->routeIs('project.application.configuration') ? 'text-white' : '' }}"
        href="{{ route('project.application.configuration', $parameters) }}">
        <button>Configuration</button>
    </a>
    <a class="{{ request()->routeIs('project.application.deployments') ? 'text-white' : '' }}"
        href="{{ route('project.application.deployments', $parameters) }}">
        <button>Deployments</button>
    </a>
    <div class="flex-1"></div>
    <x-applications.links :application="$application" />
    <x-applications.actions :application="$application" />
</div>
