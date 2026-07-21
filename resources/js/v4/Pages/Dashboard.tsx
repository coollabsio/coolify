import { Head } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { csrfToken } from '@/lib/csrf';
import { cn } from '@/lib/utils';
import { ClassicLink } from '@v4/components/classic-link';
import type { V4DashboardProps } from '@v4/types';

export default function Dashboard({
    projects,
    servers,
    privateKeysCount,
    permissions,
    links,
    flash,
}: V4DashboardProps) {
    return (
        <div className="mx-auto flex w-full max-w-6xl flex-col gap-8 px-4 py-8 sm:px-6">
            <Head title="Dashboard | Coolify" />

            <header className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Dashboard</h1>
                    <p className="text-muted-foreground text-sm">Your self-hosted infrastructure.</p>
                    {flash?.error ? <p className="text-destructive mt-2 text-sm">{flash.error}</p> : null}
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {/* Native form POST so the browser does a full document reload into classic Livewire. */}
                    <form method="POST" action={links.uiMode}>
                        <input type="hidden" name="_token" value={csrfToken()} />
                        <input type="hidden" name="mode" value="classic" />
                        <Button type="submit" variant="outline" size="sm">
                            Switch to classic UI
                        </Button>
                    </form>
                </div>
            </header>

            <section className="space-y-3">
                <div className="flex items-center gap-2">
                    <h2 className="text-lg font-medium">Projects</h2>
                </div>

                {projects.length > 0 ? (
                    <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                        {projects.map((project) => (
                            <div
                                key={project.uuid}
                                className="border-border bg-card relative flex flex-col gap-3 rounded-none border p-4"
                            >
                                <ClassicLink href={project.url} className="absolute inset-0 z-0" aria-label={project.name} />
                                <div className="relative z-10 flex flex-1 items-start justify-between gap-4">
                                    <div>
                                        <div className="font-medium">{project.name}</div>
                                        {project.description ? (
                                            <div className="text-muted-foreground text-sm">{project.description}</div>
                                        ) : null}
                                    </div>
                                    <div className="flex shrink-0 items-center gap-3 text-xs font-semibold">
                                        {permissions.createAnyResource && project.resourceCreateUrl ? (
                                            <ClassicLink
                                                href={project.resourceCreateUrl}
                                                className="relative z-10 hover:underline"
                                            >
                                                + Add Resource
                                            </ClassicLink>
                                        ) : null}
                                        {project.canUpdate ? (
                                            <ClassicLink href={project.settingsUrl} className="relative z-10 hover:underline">
                                                Settings
                                            </ClassicLink>
                                        ) : null}
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <div className="space-y-1">
                        <p className="text-warning font-semibold">No projects found.</p>
                        {permissions.createProject ? (
                            <p className="text-sm">
                                Create your first project or go to the{' '}
                                <ClassicLink href={links.onboarding} className="underline">
                                    onboarding
                                </ClassicLink>{' '}
                                page.
                            </p>
                        ) : null}
                    </div>
                )}
            </section>

            <section className="space-y-3">
                <div className="flex items-center gap-2">
                    <h2 className="text-lg font-medium">Servers</h2>
                    {permissions.createServer && servers.length > 0 && privateKeysCount > 0 ? (
                        <Button type="button" variant="outline" size="icon-xs" render={<ClassicLink href={links.serverCreate} />}>
                            +
                        </Button>
                    ) : null}
                </div>

                {servers.length > 0 ? (
                    <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                        {servers.map((server) => (
                            <ClassicLink
                                key={server.uuid}
                                href={server.url}
                                className={cn(
                                    'border-border bg-card flex flex-col gap-1 rounded-none border p-4 transition-colors hover:bg-muted/40',
                                    (!server.isReachable || server.forceDisabled) && 'border-red-500',
                                )}
                            >
                                <div className="font-medium">{server.name}</div>
                                {server.description ? (
                                    <div className="text-muted-foreground text-sm">{server.description}</div>
                                ) : null}
                                <div className="text-destructive flex gap-1 text-xs">
                                    {!server.isReachable ? <span>Not reachable</span> : null}
                                    {!server.isReachable && !server.isUsable ? <span>&</span> : null}
                                    {!server.isUsable ? <span>Not usable by Coolify</span> : null}
                                </div>
                            </ClassicLink>
                        ))}
                    </div>
                ) : privateKeysCount === 0 ? (
                    <div className="space-y-1">
                        <p className="text-warning font-semibold">No private keys found.</p>
                        {permissions.createServer ? (
                            <p className="text-sm">
                                Before you can add your server, add a private key or go to the{' '}
                                <ClassicLink href={links.onboarding} className="underline">
                                    onboarding
                                </ClassicLink>{' '}
                                page.
                            </p>
                        ) : null}
                    </div>
                ) : (
                    <div className="space-y-1">
                        <p className="text-warning font-semibold">No servers found.</p>
                        {permissions.createServer ? (
                            <p className="text-sm">
                                <ClassicLink href={links.serverCreate} className="underline">
                                    Add
                                </ClassicLink>{' '}
                                your first server or go to the{' '}
                                <ClassicLink href={links.onboarding} className="underline">
                                    onboarding
                                </ClassicLink>{' '}
                                page.
                            </p>
                        ) : null}
                    </div>
                )}
            </section>
        </div>
    );
}
