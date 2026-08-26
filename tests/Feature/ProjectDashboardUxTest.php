<?php

it('adds operational status and domains to project cards', function () {
    $component = file_get_contents(app_path('Livewire/Project/Index.php'));
    $view = file_get_contents(resource_path('views/livewire/project/index.blade.php'));

    expect($component)
        ->toContain('ProjectStatusAggregator::forProjects')
        ->toContain('ProjectDomainAggregator::forProjects')
        ->toContain("'statusLabel'")
        ->toContain("'domains'");

    expect($view)
        ->toContain('Project status: ${project.statusLabel}')
        ->toContain('project.domains.slice(0, 3)')
        ->toContain('target="_blank" rel="noopener noreferrer"');
});

it('supports running first sorting and domain aware search', function () {
    $view = file_get_contents(resource_path('views/livewire/project/index.blade.php'));

    expect($view)
        ->toContain("localStorage.getItem('projects-sort') || 'running'")
        ->toContain("localStorage.setItem('projects-sort', sortBy)")
        ->toContain("label: 'Running first'")
        ->toContain('const priority = { success: 0, warning: 1, error: 2, neutral: 3 }')
        ->toContain('...(project.domains || []).map((domain) => domain.label)');
});

it('shows the same project status context on dashboard cards', function () {
    $component = file_get_contents(app_path('Livewire/Dashboard.php'));
    $view = file_get_contents(resource_path('views/livewire/dashboard.blade.php'));

    expect($component)
        ->toContain('ProjectStatusAggregator::forProjects($this->projects)');

    expect($view)
        ->toContain('$projectStatuses[$project->uuid]')
        ->toContain('Project status: {{ $projectStatus[\'label\'] }}');
});

it('uses bounded caches and excludes deleted service domains', function () {
    $statuses = file_get_contents(app_path('Support/ProjectStatusAggregator.php'));
    $domains = file_get_contents(app_path('Support/ProjectDomainAggregator.php'));

    expect($statuses)
        ->toContain('Cache::remember($cacheKey, 5')
        ->toContain("['label' => 'Empty', 'type' => 'neutral']")
        ->toContain("['label' => 'Unhealthy', 'type' => 'error']");

    expect($domains)
        ->toContain('Cache::remember($cacheKey, 10')
        ->toContain("whereNull('services.deleted_at')")
        ->toContain("whereNull('service_applications.deleted_at')");
});
