import { useMemo, useState } from 'react';

import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function persistSelection(projectUuid, environmentUuid) {
    void fetch('/v5/selection', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({
            project_uuid: projectUuid,
            environment_uuid: environmentUuid,
        }),
    });
}

export function AppNavbar({
    flux,
    clusters = [],
    projects = [],
    selectedProjectUuid = null,
    selectedEnvironmentUuid = null,
}) {
    const firstProject = projects[0] ?? null;
    const [projectUuid, setProjectUuid] = useState(selectedProjectUuid ?? firstProject?.uuid ?? '');
    const selectedProject = useMemo(
        () => projects.find((project) => project.uuid === projectUuid) ?? firstProject,
        [firstProject, projectUuid, projects],
    );
    const firstEnvironment = selectedProject?.environments?.[0] ?? null;
    const [environmentUuid, setEnvironmentUuid] = useState(selectedEnvironmentUuid ?? firstEnvironment?.uuid ?? '');
    const selectedEnvironment = useMemo(
        () => selectedProject?.environments?.find((environment) => environment.uuid === environmentUuid) ?? firstEnvironment,
        [environmentUuid, firstEnvironment, selectedProject],
    );

    function selectProject(nextProjectUuid) {
        const nextProject = projects.find((project) => project.uuid === nextProjectUuid);
        const nextEnvironmentUuid = nextProject?.environments?.[0]?.uuid ?? '';

        setProjectUuid(nextProjectUuid);
        setEnvironmentUuid(nextEnvironmentUuid);
        persistSelection(nextProjectUuid, nextEnvironmentUuid);
    }

    function selectEnvironment(nextEnvironmentUuid) {
        setEnvironmentUuid(nextEnvironmentUuid);
        persistSelection(projectUuid, nextEnvironmentUuid);
    }

    return (
        <header className="fixed inset-x-0 top-0 z-40 border-b border-border bg-background">
            <nav className="flex h-16 items-center gap-4 px-6" aria-label="Main navigation">
                <a
                    href="/v5"
                    className="flex shrink-0 items-center rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                    aria-label="Coolify home"
                >
                    <img src="/coolify-logo.svg" alt="Coolify" className="size-8" />
                </a>

                <div className="flex min-w-0 items-center gap-2">
                    <Select value={selectedProject?.uuid ?? ''} onValueChange={selectProject} disabled={projects.length === 0}>
                        <SelectTrigger aria-label="Select a project" variant="ghost" className="max-w-[10rem]">
                            <SelectValue placeholder="Select a project" />
                        </SelectTrigger>
                        <SelectContent position="popper" align="start" sideOffset={4}>
                            <SelectGroup>
                                {projects.map((project) => (
                                    <SelectItem key={project.uuid} value={project.uuid}>
                                        {project.name}
                                    </SelectItem>
                                ))}
                            </SelectGroup>
                        </SelectContent>
                    </Select>

                    <span className="text-muted-foreground">/</span>

                    <Select
                        value={selectedEnvironment?.uuid ?? ''}
                        onValueChange={selectEnvironment}
                        disabled={!selectedProject || (selectedProject.environments ?? []).length === 0}
                    >
                        <SelectTrigger aria-label="Select an environment" variant="ghost" className="max-w-[10rem]">
                            <SelectValue placeholder="Select an environment" />
                        </SelectTrigger>
                        <SelectContent position="popper" align="start" sideOffset={4}>
                            <SelectGroup>
                                {(selectedProject?.environments ?? []).map((environment) => (
                                    <SelectItem key={environment.uuid} value={environment.uuid}>
                                        {environment.name}
                                    </SelectItem>
                                ))}
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                </div>

                <div className="ml-auto flex items-center gap-3">
                    <div
                        className="hidden rounded-md border border-border bg-muted/40 px-3 py-1 text-xs text-muted-foreground lg:block"
                        title={flux?.socket ?? flux?.message}
                    >
                        Flux: {flux?.label ?? 'Unknown'} · {clusters.length} clusters
                    </div>
                </div>
            </nav>
        </header>
    );
}
