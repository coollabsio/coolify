import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Sheet, SheetClose, SheetContent, SheetDescription, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { csrfToken } from '@/lib/csrf';
import { cn } from '@/lib/utils';
import type { SelectItemOption, V5DashboardProps, V5Project } from '@/types';

async function persistSelection(projectUuid: string, environmentUuid: string): Promise<boolean> {
    try {
        const response = await fetch('/v5/selection', {
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

        if (!response.ok) {
            console.error(`Could not persist the project/environment selection (HTTP ${response.status}).`);
        }

        return response.ok;
    } catch (error) {
        console.error('Could not persist the project/environment selection.', error);

        return false;
    }
}

function refreshCurrentPageSelection(): void {
    router.reload({
        only: [
            'applications',
            'caddyIngresses',
            'resourceConnections',
            'nginxServers',
            'selectedProjectUuid',
            'selectedEnvironmentUuid',
        ],
    });
}

type AppNavbarProps = V5DashboardProps;

export function AppNavbar({
    projects = [],
    selectedProjectUuid = null,
    selectedEnvironmentUuid = null,
}: AppNavbarProps) {
    const { url } = usePage();
    const firstProject = projects[0] ?? null;
    const [projectUuid, setProjectUuid] = useState<string>(selectedProjectUuid ?? firstProject?.uuid ?? '');
    const selectedProject = useMemo<V5Project | null>(
        () => projects.find((project) => project.uuid === projectUuid) ?? firstProject,
        [firstProject, projectUuid, projects],
    );
    const firstEnvironment = selectedProject?.environments?.[0] ?? null;
    const [environmentUuid, setEnvironmentUuid] = useState<string>(selectedEnvironmentUuid ?? firstEnvironment?.uuid ?? '');
    const selectedEnvironment = useMemo(
        () => selectedProject?.environments?.find((environment) => environment.uuid === environmentUuid) ?? firstEnvironment,
        [environmentUuid, firstEnvironment, selectedProject],
    );

    useEffect(() => {
        setProjectUuid(selectedProjectUuid ?? firstProject?.uuid ?? '');
    }, [firstProject?.uuid, selectedProjectUuid]);

    useEffect(() => {
        setEnvironmentUuid(selectedEnvironmentUuid ?? firstEnvironment?.uuid ?? '');
    }, [firstEnvironment?.uuid, selectedEnvironmentUuid]);

    function selectProject(nextProjectUuid: string | null): void {
        if (nextProjectUuid === null) {
            return;
        }

        const nextProject = projects.find((project) => project.uuid === nextProjectUuid);
        const nextEnvironmentUuid = nextProject?.environments?.[0]?.uuid ?? '';
        const previousProjectUuid = projectUuid;
        const previousEnvironmentUuid = environmentUuid;

        setProjectUuid(nextProjectUuid);
        setEnvironmentUuid(nextEnvironmentUuid);
        void persistSelection(nextProjectUuid, nextEnvironmentUuid).then((persisted) => {
            if (persisted) {
                refreshCurrentPageSelection();

                return;
            }

            setProjectUuid(previousProjectUuid);
            setEnvironmentUuid(previousEnvironmentUuid);
        });
    }

    function selectEnvironment(nextEnvironmentUuid: string | null): void {
        if (nextEnvironmentUuid === null) {
            return;
        }

        const previousEnvironmentUuid = environmentUuid;

        setEnvironmentUuid(nextEnvironmentUuid);
        void persistSelection(projectUuid, nextEnvironmentUuid).then((persisted) => {
            if (persisted) {
                refreshCurrentPageSelection();

                return;
            }

            setEnvironmentUuid(previousEnvironmentUuid);
        });
    }

    const projectItems: SelectItemOption[] = projects.map((project) => ({
        label: project.name,
        value: project.uuid,
    }));
    const environmentItems: SelectItemOption[] = (selectedProject?.environments ?? []).map((environment) => ({
        label: environment.name,
        value: environment.uuid,
    }));
    const isClustersPage = url.startsWith('/v5/clusters');

    return (
        <header className="fixed inset-x-0 top-0 z-40 border-b border-border bg-background">
            <nav className="relative flex h-16 items-center gap-3 px-4 sm:px-6" aria-label="Main navigation">
                <Link
                    href="/v5"
                    className="flex shrink-0 items-center rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                    aria-label="Coolify dashboard"
                >
                    <img src="/coolify-logo.svg" alt="Coolify" className="size-6" />
                </Link>

                <div className="absolute left-1/2 flex min-w-0 -translate-x-1/2 items-center justify-center gap-1 md:static md:flex-1 md:translate-x-0 md:justify-start md:gap-2">
                    <Select
                        items={projectItems}
                        value={selectedProject?.uuid ?? ''}
                        onValueChange={selectProject}
                        disabled={projects.length === 0}
                    >
                        <SelectTrigger aria-label="Select a project" variant="ghost" className="max-w-[38vw] md:max-w-[10rem]">
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
                        items={environmentItems}
                        value={selectedEnvironment?.uuid ?? ''}
                        onValueChange={selectEnvironment}
                        disabled={!selectedProject || (selectedProject.environments ?? []).length === 0}
                    >
                        <SelectTrigger aria-label="Select an environment" variant="ghost" className="max-w-[30vw] md:max-w-[10rem]">
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
                    <Link
                        href="/v5/clusters"
                        className="hidden rounded-md px-3 py-1 text-sm text-muted-foreground transition-colors hover:bg-muted hover:text-foreground md:inline-flex"
                    >
                        Clusters
                    </Link>

                    <Sheet>
                        <SheetTrigger
                            className="inline-flex rounded-md p-2 text-warning transition-colors hover:bg-muted hover:text-warning md:hidden"
                            aria-label="Open mobile menu"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" className="size-6" viewBox="0 0 24 24" aria-hidden="true">
                                <path
                                    fill="none"
                                    stroke="currentColor"
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    strokeWidth="2"
                                    d="M4 6h16M4 12h16M4 18h16"
                                />
                            </svg>
                        </SheetTrigger>
                        <SheetContent side="right" className="w-72 max-w-[85vw] bg-background">
                            <SheetHeader>
                                <SheetTitle>Coolify</SheetTitle>
                                <SheetDescription className="sr-only">Move between Coolify v5 pages.</SheetDescription>
                            </SheetHeader>
                            <nav className="flex flex-col gap-1 px-4" aria-label="Mobile navigation">
                                <SheetClose
                                    render={<Link href="/v5" />}
                                    className={cn(
                                        'rounded-md px-3 py-2 text-sm text-muted-foreground transition-colors hover:bg-muted hover:text-warning',
                                        !isClustersPage && 'bg-accent text-accent-foreground',
                                    )}
                                >
                                    Dashboard
                                </SheetClose>
                                <SheetClose
                                    render={<Link href="/v5/clusters" />}
                                    className={cn(
                                        'rounded-md px-3 py-2 text-sm text-muted-foreground transition-colors hover:bg-muted hover:text-warning',
                                        isClustersPage && 'bg-accent text-accent-foreground',
                                    )}
                                >
                                    Clusters
                                </SheetClose>
                            </nav>
                        </SheetContent>
                    </Sheet>
                </div>
            </nav>
        </header>
    );
}
