import { Head } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';

import { AppNavbar } from '@/components/app-navbar';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { csrfToken } from '@/lib/csrf';
import type { V5Cluster, V5DashboardProps } from '@/types';

type ClusterFormErrors = {
    name?: string[];
    description?: string[];
};

type StoreClusterResponse = {
    cluster: V5Cluster;
};

function formatDate(value: string | null): string {
    if (value === null) {
        return 'Never';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function normalizeCapabilities(capabilities: string[]): string {
    if (capabilities.length === 0) {
        return 'No capabilities';
    }

    return capabilities.join(', ');
}

export default function Clusters({
    flux,
    clusters = [],
    projects = [],
    selectedProjectUuid = null,
    selectedEnvironmentUuid = null,
}: V5DashboardProps) {
    const [clusterList, setClusterList] = useState<V5Cluster[]>(clusters);
    const [selectedClusterId, setSelectedClusterId] = useState<string>(clusters[0]?.id ?? '');
    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [errors, setErrors] = useState<ClusterFormErrors>({});
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [isCreateDialogOpen, setIsCreateDialogOpen] = useState(false);

    const selectedCluster = useMemo(
        () => clusterList.find((cluster) => cluster.id === selectedClusterId) ?? clusterList[0] ?? null,
        [clusterList, selectedClusterId],
    );

    async function createCluster(event: FormEvent<HTMLFormElement>): Promise<void> {
        event.preventDefault();
        setIsSubmitting(true);
        setErrors({});

        const response = await fetch('/v5/clusters', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                name,
                description: description.trim() === '' ? null : description,
            }),
        });

        if (response.status === 422) {
            const payload = (await response.json()) as { errors?: ClusterFormErrors };
            setErrors(payload.errors ?? {});
            setIsSubmitting(false);

            return;
        }

        if (!response.ok) {
            setErrors({
                name: ['Unable to create this cluster. Please try again.'],
            });
            setIsSubmitting(false);

            return;
        }

        const payload = (await response.json()) as StoreClusterResponse;
        const nextClusters = [...clusterList, payload.cluster].sort((first, second) => first.name.localeCompare(second.name));

        setClusterList(nextClusters);
        setSelectedClusterId(payload.cluster.id);
        setName('');
        setDescription('');
        setIsCreateDialogOpen(false);
        setIsSubmitting(false);
    }

    return (
        <>
            <Head title="Clusters" />

            <div className="h-dvh overflow-hidden bg-background text-foreground">
                <AppNavbar
                    flux={flux}
                    clusters={clusterList}
                    projects={projects}
                    selectedProjectUuid={selectedProjectUuid}
                    selectedEnvironmentUuid={selectedEnvironmentUuid}
                />

                <main className="flex h-full min-h-0 overflow-hidden px-6 pt-16">
                    <section className="grid min-h-0 w-full grid-cols-1 gap-4 py-6 lg:grid-cols-[20rem_minmax(0,1fr)]">
                        <aside className="flex min-h-0 flex-col rounded-lg border border-border bg-card">
                            <div className="flex items-start justify-between gap-3 border-b border-border p-4">
                                <div>
                                    <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Clusters</p>
                                    <h1 className="mt-1 text-lg font-semibold text-foreground">Cluster inventory</h1>
                                </div>
                                <Button
                                    type="button"
                                    variant="coolify"
                                    size="sm"
                                    aria-label="Create cluster"
                                    onClick={() => setIsCreateDialogOpen(true)}
                                >
                                    <span className="text-base leading-none">+</span>
                                    Add cluster
                                </Button>
                            </div>

                            <div className="min-h-0 flex-1 overflow-y-auto p-2">
                                {clusterList.length === 0 ? (
                                    <div className="rounded-md border border-dashed border-border p-4 text-sm text-muted-foreground">
                                        No clusters yet. Create your first cluster to group servers.
                                    </div>
                                ) : (
                                    <div className="flex flex-col gap-2">
                                        {clusterList.map((cluster) => (
                                            <button
                                                key={cluster.id}
                                                type="button"
                                                onClick={() => setSelectedClusterId(cluster.id)}
                                                className={`rounded-md border p-3 text-left transition-colors ${
                                                    selectedCluster?.id === cluster.id
                                                        ? 'border-warning bg-warning/10 text-foreground'
                                                        : 'border-border bg-background hover:bg-muted/50'
                                                }`}
                                            >
                                                <span className="block text-sm font-medium">{cluster.name}</span>
                                                <span className="mt-1 block text-xs text-muted-foreground">
                                                    {cluster.serversCount} {cluster.serversCount === 1 ? 'server' : 'servers'}
                                                </span>
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </aside>

                        <section className="min-h-0 overflow-y-auto rounded-lg border border-border bg-card">
                            {selectedCluster ? (
                                <div className="flex min-h-full flex-col">
                                    <div className="border-b border-border p-5">
                                        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                            Cluster details
                                        </p>
                                        <div className="mt-2 flex flex-wrap items-start justify-between gap-3">
                                            <div>
                                                <h2 className="text-2xl font-semibold text-foreground">{selectedCluster.name}</h2>
                                                <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
                                                    {selectedCluster.description ?? 'No description provided.'}
                                                </p>
                                            </div>
                                            <div className="rounded-md border border-border bg-muted/40 px-3 py-2 text-sm text-muted-foreground">
                                                {selectedCluster.serversCount}{' '}
                                                {selectedCluster.serversCount === 1 ? 'server' : 'servers'}
                                            </div>
                                        </div>
                                    </div>

                                    <div className="flex-1 p-5">
                                        <div className="mb-4 flex items-center justify-between gap-3">
                                            <div>
                                                <h3 className="text-base font-semibold text-foreground">Servers in this cluster</h3>
                                                <p className="text-sm text-muted-foreground">
                                                    Connection and builder details for each server assigned to this cluster.
                                                </p>
                                            </div>
                                        </div>

                                        {selectedCluster.servers.length === 0 ? (
                                            <div className="rounded-lg border border-dashed border-border p-8 text-center">
                                                <p className="text-sm font-medium text-foreground">No servers assigned</p>
                                                <p className="mt-1 text-sm text-muted-foreground">
                                                    Servers will appear here after they are added to this cluster.
                                                </p>
                                            </div>
                                        ) : (
                                            <div className="grid grid-cols-1 gap-3 xl:grid-cols-2">
                                                {selectedCluster.servers.map((server) => (
                                                    <article
                                                        key={server.id}
                                                        className="rounded-lg border border-border bg-background p-4"
                                                    >
                                                        <div className="flex items-start justify-between gap-3">
                                                            <div>
                                                                <h4 className="text-sm font-semibold text-foreground">
                                                                    {server.name}
                                                                </h4>
                                                                <p className="mt-1 text-xs text-muted-foreground">{server.host}</p>
                                                            </div>
                                                            <span className="rounded-full border border-border bg-muted/40 px-2 py-1 text-xs text-muted-foreground">
                                                                {server.status}
                                                            </span>
                                                        </div>

                                                        <dl className="mt-4 grid grid-cols-2 gap-3 text-xs">
                                                            <div>
                                                                <dt className="text-muted-foreground">SSH</dt>
                                                                <dd className="mt-1 font-medium text-foreground">
                                                                    {server.sshUser}@{server.host}:{server.sshPort}
                                                                </dd>
                                                            </div>
                                                            <div>
                                                                <dt className="text-muted-foreground">Builder capacity</dt>
                                                                <dd className="mt-1 font-medium text-foreground">
                                                                    {server.builderEnabled ? server.builderCapacity : 'Disabled'}
                                                                </dd>
                                                            </div>
                                                            <div>
                                                                <dt className="text-muted-foreground">Private key</dt>
                                                                <dd className="mt-1 font-medium text-foreground">
                                                                    {server.privateKeyName ?? 'No key'}
                                                                </dd>
                                                            </div>
                                                            <div>
                                                                <dt className="text-muted-foreground">Last bootstrap</dt>
                                                                <dd className="mt-1 font-medium text-foreground">
                                                                    {formatDate(server.lastBootstrappedAt)}
                                                                </dd>
                                                            </div>
                                                        </dl>

                                                        <p className="mt-4 text-xs text-muted-foreground">
                                                            Capabilities: {normalizeCapabilities(server.capabilities)}
                                                        </p>
                                                    </article>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                </div>
                            ) : (
                                <div className="flex min-h-full items-center justify-center p-8 text-center">
                                    <div>
                                        <p className="text-sm font-medium text-foreground">No cluster selected</p>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            Create a cluster to start organizing servers.
                                        </p>
                                    </div>
                                </div>
                            )}
                        </section>

                        <Dialog
                            open={isCreateDialogOpen}
                            onOpenChange={(open) => {
                                setIsCreateDialogOpen(open);

                                if (!open) {
                                    setErrors({});
                                }
                            }}
                        >
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>Create cluster</DialogTitle>
                                    <DialogDescription>
                                        Create an empty cluster now. Servers can be assigned after they exist.
                                    </DialogDescription>
                                </DialogHeader>

                                <form className="mt-5 flex flex-col gap-4" onSubmit={createCluster}>
                                    <label className="flex flex-col gap-1 text-sm">
                                        <span className="font-medium text-foreground">Name</span>
                                        <input
                                            value={name}
                                            onChange={(event) => setName(event.target.value)}
                                            className="rounded-md border border-border bg-background px-3 py-2 text-sm outline-none transition focus:border-ring focus:ring-1 focus:ring-ring"
                                            placeholder="Production Mesh"
                                        />
                                        {errors.name ? <span className="text-xs text-destructive">{errors.name[0]}</span> : null}
                                    </label>

                                    <label className="flex flex-col gap-1 text-sm">
                                        <span className="font-medium text-foreground">Description</span>
                                        <textarea
                                            value={description}
                                            onChange={(event) => setDescription(event.target.value)}
                                            className="min-h-24 rounded-md border border-border bg-background px-3 py-2 text-sm outline-none transition focus:border-ring focus:ring-1 focus:ring-ring"
                                            placeholder="What this cluster is used for"
                                        />
                                        {errors.description ? (
                                            <span className="text-xs text-destructive">{errors.description[0]}</span>
                                        ) : null}
                                    </label>

                                    <DialogFooter>
                                        <DialogClose render={<Button type="button" variant="outline" />}>Cancel</DialogClose>
                                        <Button type="submit" variant="coolify" disabled={isSubmitting}>
                                            {isSubmitting ? 'Creating...' : 'Create cluster'}
                                        </Button>
                                    </DialogFooter>
                                </form>
                            </DialogContent>
                        </Dialog>
                    </section>
                </main>
            </div>
        </>
    );
}
