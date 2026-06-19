import { Head } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';

import { AppNavbar } from '@/components/app-navbar';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { csrfToken } from '@/lib/csrf';
import { usePendingIds } from '@/lib/use-pending-ids';
import type { V5Cluster, V5DashboardProps, V5Server } from '@/types';

type ClusterFormErrors = {
    name?: string[];
    description?: string[];
    wireguard_interface?: string[];
    wireguard_management_pool?: string[];
    wireguard_listen_port?: string[];
    container_network_pool?: string[];
    container_network_prefix?: string[];
    namespaces?: string[];
    default_deny_containers?: string[];
    coold_version?: string[];
    corrosion_version?: string[];
    corrosion_gossip_port?: string[];
    corrosion_api_port?: string[];
    builder_enabled?: string[];
    builder_capacity?: string[];
    builder_cpu_quota?: string[];
    builder_memory_max?: string[];
    builder_timeout_secs?: string[];
};

type StoreClusterResponse = {
    cluster: V5Cluster;
};

type ServerFormErrors = {
    name?: string[];
    host?: string[];
    ssh_user?: string[];
    ssh_port?: string[];
    private_key_id?: string[];
    node_address?: string[];
    builder_enabled?: string[];
    builder_capacity?: string[];
    builder_cpu_quota?: string[];
    wireguard_listen_port_override?: string[];
    wireguard_endpoint_override?: string[];
};

type StoreServerResponse = {
    cluster: V5Cluster;
};

type UpdateServerResponse = {
    cluster: V5Cluster;
};

type CheckServerResponse = {
    cluster: V5Cluster;
};

type DeleteServerResponse = {
    cluster: V5Cluster;
};

type BootstrapServerResponse = {
    cluster?: V5Cluster;
    message?: string;
};

const clusterDefaults = {
    wireguardInterface: 'wg0',
    wireguardManagementPool: '100.64.0.0/16',
    wireguardListenPort: '51820',
    containerNetworkPool: '10.210.0.0/16',
    containerNetworkPrefix: '24',
    namespaces: 'default',
    defaultDenyContainers: true,
    cooldVersion: 'nightly',
    corrosionVersion: 'v1.0.0',
    corrosionGossipPort: '8787',
    corrosionApiPort: '8080',
    builderEnabled: true,
    builderCapacity: '2',
    builderCpuQuota: '200%',
    builderMemoryMax: '2G',
    builderTimeoutSecs: '1800',
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
    privateKeys = [],
    projects = [],
    selectedProjectUuid = null,
    selectedEnvironmentUuid = null,
}: V5DashboardProps) {
    const [clusterList, setClusterList] = useState<V5Cluster[]>(clusters);
    const [selectedClusterId, setSelectedClusterId] = useState<string>(clusters[0]?.id ?? '');
    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [wireguardInterface, setWireguardInterface] = useState(clusterDefaults.wireguardInterface);
    const [wireguardManagementPool, setWireguardManagementPool] = useState(clusterDefaults.wireguardManagementPool);
    const [wireguardListenPort, setWireguardListenPort] = useState(clusterDefaults.wireguardListenPort);
    const [containerNetworkPool, setContainerNetworkPool] = useState(clusterDefaults.containerNetworkPool);
    const [containerNetworkPrefix, setContainerNetworkPrefix] = useState(clusterDefaults.containerNetworkPrefix);
    const [namespaces, setNamespaces] = useState(clusterDefaults.namespaces);
    const [defaultDenyContainers, setDefaultDenyContainers] = useState(clusterDefaults.defaultDenyContainers);
    const [cooldVersion, setCooldVersion] = useState(clusterDefaults.cooldVersion);
    const [corrosionVersion, setCorrosionVersion] = useState(clusterDefaults.corrosionVersion);
    const [corrosionGossipPort, setCorrosionGossipPort] = useState(clusterDefaults.corrosionGossipPort);
    const [corrosionApiPort, setCorrosionApiPort] = useState(clusterDefaults.corrosionApiPort);
    const [builderEnabled, setBuilderEnabled] = useState(clusterDefaults.builderEnabled);
    const [builderCapacity, setBuilderCapacity] = useState(clusterDefaults.builderCapacity);
    const [builderCpuQuota, setBuilderCpuQuota] = useState(clusterDefaults.builderCpuQuota);
    const [builderMemoryMax, setBuilderMemoryMax] = useState(clusterDefaults.builderMemoryMax);
    const [builderTimeoutSecs, setBuilderTimeoutSecs] = useState(clusterDefaults.builderTimeoutSecs);
    const [errors, setErrors] = useState<ClusterFormErrors>({});
    const [serverName, setServerName] = useState('');
    const [serverHost, setServerHost] = useState('');
    const [serverSshUser, setServerSshUser] = useState('root');
    const [serverSshPort, setServerSshPort] = useState('22');
    const [selectedPrivateKeyId, setSelectedPrivateKeyId] = useState('');
    const [serverNodeAddress, setServerNodeAddress] = useState('');
    const [serverBuilderEnabled, setServerBuilderEnabled] = useState(true);
    const [serverBuilderCapacity, setServerBuilderCapacity] = useState('2');
    const [serverBuilderCpuQuota, setServerBuilderCpuQuota] = useState(clusterDefaults.builderCpuQuota);
    const [wireguardListenPortOverride, setWireguardListenPortOverride] = useState('');
    const [wireguardEndpointOverride, setWireguardEndpointOverride] = useState('');
    const [serverErrors, setServerErrors] = useState<ServerFormErrors>({});
    const [editingServer, setEditingServer] = useState<V5Server | null>(null);
    const [editServerBuilderEnabled, setEditServerBuilderEnabled] = useState(true);
    const [editServerBuilderCapacity, setEditServerBuilderCapacity] = useState('2');
    const [editServerBuilderCpuQuota, setEditServerBuilderCpuQuota] = useState(clusterDefaults.builderCpuQuota);
    const [editServerErrors, setEditServerErrors] = useState<ServerFormErrors>({});
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [isServerSubmitting, setIsServerSubmitting] = useState(false);
    const [isServerUpdateSubmitting, setIsServerUpdateSubmitting] = useState(false);
    const checkingServers = usePendingIds<string>();
    const bootstrappingServers = usePendingIds<string>();
    const [bootstrapServerError, setBootstrapServerError] = useState<string | null>(null);
    const deletingServers = usePendingIds<string>();
    const [isDeletingCluster, setIsDeletingCluster] = useState(false);
    const [deleteClusterError, setDeleteClusterError] = useState<string | null>(null);
    const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
    const [clusterPendingDelete, setClusterPendingDelete] = useState<V5Cluster | null>(null);
    const [serverPendingDelete, setServerPendingDelete] = useState<V5Server | null>(null);
    const [isCreateDialogOpen, setIsCreateDialogOpen] = useState(false);
    const [isAddServerDialogOpen, setIsAddServerDialogOpen] = useState(false);
    const [isEditServerDialogOpen, setIsEditServerDialogOpen] = useState(false);
    const [showAdvancedConfiguration, setShowAdvancedConfiguration] = useState(false);
    const [showAdvancedServerConfiguration, setShowAdvancedServerConfiguration] = useState(false);

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
                wireguard_interface: wireguardInterface,
                wireguard_management_pool: wireguardManagementPool,
                wireguard_listen_port: Number(wireguardListenPort),
                container_network_pool: containerNetworkPool,
                container_network_prefix: Number(containerNetworkPrefix),
                namespaces: namespaces
                    .split(',')
                    .map((namespace) => namespace.trim())
                    .filter(Boolean),
                default_deny_containers: defaultDenyContainers,
                coold_version: cooldVersion,
                corrosion_version: corrosionVersion,
                corrosion_gossip_port: Number(corrosionGossipPort),
                corrosion_api_port: Number(corrosionApiPort),
                builder_enabled: builderEnabled,
                builder_capacity: Number(builderCapacity),
                builder_cpu_quota: builderCpuQuota,
                builder_memory_max: builderMemoryMax,
                builder_timeout_secs: Number(builderTimeoutSecs),
            }),
        });

        if (response.status === 422) {
            const payload = (await response.json()) as {
                errors?: ClusterFormErrors;
            };
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
        const nextClusters = [...clusterList, payload.cluster].sort((first, second) =>
            first.name.localeCompare(second.name),
        );

        setClusterList(nextClusters);
        setSelectedClusterId(payload.cluster.id);
        setName('');
        setDescription('');
        resetAdvancedConfiguration();
        setIsCreateDialogOpen(false);
        setIsSubmitting(false);
    }

    async function createServer(event: FormEvent<HTMLFormElement>): Promise<void> {
        event.preventDefault();

        if (!selectedCluster) {
            return;
        }

        setIsServerSubmitting(true);
        setServerErrors({});

        const response = await fetch(`/v5/clusters/${selectedCluster.id}/servers`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                name: serverName,
                host: serverHost,
                ssh_user: serverSshUser,
                ssh_port: Number(serverSshPort),
                private_key_id: selectedPrivateKeyId === '' ? null : Number(selectedPrivateKeyId),
                node_address: serverNodeAddress.trim() === '' ? null : serverNodeAddress,
                builder_enabled: serverBuilderEnabled,
                builder_capacity: Number(serverBuilderCapacity),
                builder_cpu_quota: serverBuilderCpuQuota,
                wireguard_listen_port_override:
                    wireguardListenPortOverride.trim() === '' ? null : Number(wireguardListenPortOverride),
                wireguard_endpoint_override: wireguardEndpointOverride.trim() === '' ? null : wireguardEndpointOverride,
            }),
        });

        if (response.status === 422) {
            const payload = (await response.json()) as {
                errors?: ServerFormErrors;
            };
            setServerErrors(payload.errors ?? {});
            setIsServerSubmitting(false);

            return;
        }

        if (!response.ok) {
            setServerErrors({
                name: ['Unable to add this server. Please try again.'],
            });
            setIsServerSubmitting(false);

            return;
        }

        const payload = (await response.json()) as StoreServerResponse;

        setClusterList((currentClusters) =>
            currentClusters.map((cluster) => (cluster.id === payload.cluster.id ? payload.cluster : cluster)),
        );
        resetServerForm();
        setIsAddServerDialogOpen(false);
        setIsServerSubmitting(false);
    }

    async function updateServer(event: FormEvent<HTMLFormElement>): Promise<void> {
        event.preventDefault();

        if (!selectedCluster || !editingServer) {
            return;
        }

        setIsServerUpdateSubmitting(true);
        setEditServerErrors({});

        const response = await fetch(`/v5/clusters/${selectedCluster.id}/servers/${editingServer.id}`, {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                builder_enabled: editServerBuilderEnabled,
                builder_capacity: Number(editServerBuilderCapacity),
                builder_cpu_quota: editServerBuilderCpuQuota,
            }),
        });

        if (response.status === 422) {
            const payload = (await response.json()) as {
                errors?: ServerFormErrors;
            };
            setEditServerErrors(payload.errors ?? {});
            setIsServerUpdateSubmitting(false);

            return;
        }

        if (!response.ok) {
            setEditServerErrors({
                builder_capacity: ['Unable to update this server. Please try again.'],
            });
            setIsServerUpdateSubmitting(false);

            return;
        }

        const payload = (await response.json()) as UpdateServerResponse;

        setClusterList((currentClusters) =>
            currentClusters.map((cluster) => (cluster.id === payload.cluster.id ? payload.cluster : cluster)),
        );
        resetEditServerForm();
        setIsEditServerDialogOpen(false);
        setIsServerUpdateSubmitting(false);
    }

    async function checkServer(server: V5Server): Promise<void> {
        if (!selectedCluster) {
            return;
        }

        checkingServers.start(server.id);

        const response = await fetch(`/v5/clusters/${selectedCluster.id}/servers/${server.id}/check`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
        });

        if (response.ok) {
            const payload = (await response.json()) as CheckServerResponse;

            setClusterList((currentClusters) =>
                currentClusters.map((cluster) => (cluster.id === payload.cluster.id ? payload.cluster : cluster)),
            );
        }

        checkingServers.finish(server.id);
    }

    async function bootstrapServer(server: V5Server): Promise<void> {
        if (!selectedCluster) {
            return;
        }

        bootstrappingServers.start(server.id);
        setBootstrapServerError(null);

        const response = await fetch(`/v5/clusters/${selectedCluster.id}/servers/${server.id}/bootstrap`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
        });
        const payload = (await response.json()) as BootstrapServerResponse;

        if (payload.cluster) {
            setClusterList((currentClusters) =>
                currentClusters.map((cluster) => (cluster.id === payload.cluster?.id ? payload.cluster : cluster)),
            );
        }

        if (!response.ok) {
            setBootstrapServerError(payload.message ?? 'Unable to bootstrap this server. Check the CLI state output.');
        }

        bootstrappingServers.finish(server.id);
    }

    function openDeleteClusterDialog(): void {
        if (!selectedCluster || selectedCluster.serversCount !== 0) {
            setDeleteClusterError('Only empty clusters can be deleted.');

            return;
        }

        setDeleteClusterError(null);
        setClusterPendingDelete(selectedCluster);
        setServerPendingDelete(null);
        setIsDeleteDialogOpen(true);
    }

    function openDeleteServerDialog(server: V5Server): void {
        if (!selectedCluster || server.lastBootstrappedAt !== null) {
            return;
        }

        setDeleteClusterError(null);
        setClusterPendingDelete(selectedCluster);
        setServerPendingDelete(server);
        setIsDeleteDialogOpen(true);
    }

    async function deleteUnbootstrappedServer(cluster: V5Cluster, server: V5Server): Promise<void> {
        if (server.lastBootstrappedAt !== null) {
            return;
        }

        deletingServers.start(server.id);

        const response = await fetch(`/v5/clusters/${cluster.id}/servers/${server.id}`, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
        });

        if (response.ok) {
            const payload = (await response.json()) as DeleteServerResponse;

            setClusterList((currentClusters) =>
                currentClusters.map((cluster) => (cluster.id === payload.cluster.id ? payload.cluster : cluster)),
            );
        }

        deletingServers.finish(server.id);
        setIsDeleteDialogOpen(false);
        setClusterPendingDelete(null);
        setServerPendingDelete(null);
    }

    function openEditServerDialog(server: V5Server): void {
        setEditingServer(server);
        setEditServerBuilderEnabled(server.builderEnabled);
        setEditServerBuilderCapacity(String(server.builderCapacity));
        setEditServerBuilderCpuQuota(server.builderCpuQuota);
        setEditServerErrors({});
        setIsEditServerDialogOpen(true);
    }

    async function deleteCluster(cluster: V5Cluster): Promise<void> {
        if (cluster.serversCount !== 0) {
            setDeleteClusterError('Only empty clusters can be deleted.');

            return;
        }

        setIsDeletingCluster(true);
        setDeleteClusterError(null);

        const response = await fetch(`/v5/clusters/${cluster.id}`, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
        });

        if (response.status === 422) {
            const payload = (await response.json()) as { message?: string };
            setDeleteClusterError(payload.message ?? 'Only empty clusters can be deleted.');
            setIsDeletingCluster(false);

            return;
        }

        if (!response.ok) {
            setDeleteClusterError('Unable to delete this cluster. Please try again.');
            setIsDeletingCluster(false);

            return;
        }

        const nextClusters = clusterList.filter((cluster) => cluster.id !== selectedCluster.id);

        setClusterList(nextClusters);
        setSelectedClusterId(nextClusters[0]?.id ?? '');
        setIsDeletingCluster(false);
        setIsDeleteDialogOpen(false);
        setClusterPendingDelete(null);
        setServerPendingDelete(null);
    }

    async function confirmDelete(): Promise<void> {
        if (!clusterPendingDelete) {
            return;
        }

        if (serverPendingDelete) {
            await deleteUnbootstrappedServer(clusterPendingDelete, serverPendingDelete);

            return;
        }

        await deleteCluster(clusterPendingDelete);
    }

    function resetAdvancedConfiguration(): void {
        setWireguardInterface(clusterDefaults.wireguardInterface);
        setWireguardManagementPool(clusterDefaults.wireguardManagementPool);
        setWireguardListenPort(clusterDefaults.wireguardListenPort);
        setContainerNetworkPool(clusterDefaults.containerNetworkPool);
        setContainerNetworkPrefix(clusterDefaults.containerNetworkPrefix);
        setNamespaces(clusterDefaults.namespaces);
        setDefaultDenyContainers(clusterDefaults.defaultDenyContainers);
        setCooldVersion(clusterDefaults.cooldVersion);
        setCorrosionVersion(clusterDefaults.corrosionVersion);
        setCorrosionGossipPort(clusterDefaults.corrosionGossipPort);
        setCorrosionApiPort(clusterDefaults.corrosionApiPort);
        setBuilderEnabled(clusterDefaults.builderEnabled);
        setBuilderCapacity(clusterDefaults.builderCapacity);
        setBuilderCpuQuota(clusterDefaults.builderCpuQuota);
        setBuilderMemoryMax(clusterDefaults.builderMemoryMax);
        setBuilderTimeoutSecs(clusterDefaults.builderTimeoutSecs);
        setShowAdvancedConfiguration(false);
    }

    function resetServerForm(): void {
        setServerName('');
        setServerHost('');
        setServerSshUser('root');
        setServerSshPort('22');
        setSelectedPrivateKeyId('');
        setServerNodeAddress('');
        setServerBuilderEnabled(selectedCluster?.builderEnabled ?? true);
        setServerBuilderCapacity(String(selectedCluster?.builderCapacity ?? 2));
        setServerBuilderCpuQuota(selectedCluster?.builderCpuQuota ?? clusterDefaults.builderCpuQuota);
        setWireguardListenPortOverride('');
        setWireguardEndpointOverride('');
        setServerErrors({});
        setShowAdvancedServerConfiguration(false);
    }

    function resetEditServerForm(): void {
        setEditingServer(null);
        setEditServerBuilderEnabled(true);
        setEditServerBuilderCapacity('2');
        setEditServerBuilderCpuQuota(clusterDefaults.builderCpuQuota);
        setEditServerErrors({});
    }

    return (
        <>
            <Head title="Clusters" />

            <div className="min-h-dvh overflow-y-auto bg-background text-foreground lg:h-dvh lg:overflow-hidden">
                <AppNavbar
                    flux={flux}
                    clusters={clusterList}
                    projects={projects}
                    selectedProjectUuid={selectedProjectUuid}
                    selectedEnvironmentUuid={selectedEnvironmentUuid}
                />

                <main className="flex min-h-dvh overflow-visible px-4 pt-16 lg:h-full lg:min-h-0 lg:overflow-hidden lg:px-6">
                    <section className="grid w-full grid-cols-1 gap-4 py-4 lg:min-h-0 lg:py-6 lg:grid-cols-[20rem_minmax(0,1fr)]">
                        <aside className="flex max-h-80 flex-col rounded-lg border border-border bg-card lg:max-h-none lg:min-h-0">
                            <div className="flex items-start justify-between gap-3 border-b border-border p-4">
                                <div>
                                    <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                        Clusters
                                    </p>
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
                                                    {cluster.serversCount}{' '}
                                                    {cluster.serversCount === 1 ? 'server' : 'servers'}
                                                </span>
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </aside>

                        <section className="overflow-visible rounded-lg border border-border bg-card lg:min-h-0 lg:overflow-y-auto">
                            {selectedCluster ? (
                                <div className="flex min-h-full flex-col">
                                    <div className="border-b border-border p-5">
                                        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                            Cluster details
                                        </p>
                                        <div className="mt-2 flex flex-wrap items-start justify-between gap-3">
                                            <div>
                                                <h2 className="text-2xl font-semibold text-foreground">
                                                    {selectedCluster.name}
                                                </h2>
                                                <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
                                                    {selectedCluster.description ?? 'No description provided.'}
                                                </p>
                                            </div>
                                            <div className="flex flex-wrap items-center gap-2">
                                                {selectedCluster.serversCount === 0 ? (
                                                    <Button
                                                        type="button"
                                                        variant="delete"
                                                        size="sm"
                                                        onClick={openDeleteClusterDialog}
                                                        disabled={isDeletingCluster}
                                                    >
                                                        {isDeletingCluster ? 'Deleting...' : 'Delete cluster'}
                                                    </Button>
                                                ) : null}
                                                <div className="rounded-md border border-border bg-muted/40 px-3 py-2 text-sm text-muted-foreground">
                                                    {selectedCluster.serversCount}{' '}
                                                    {selectedCluster.serversCount === 1 ? 'server' : 'servers'}
                                                </div>
                                            </div>
                                        </div>
                                        {deleteClusterError ? (
                                            <p className="mt-3 text-sm text-destructive">{deleteClusterError}</p>
                                        ) : null}
                                    </div>

                                    <div className="flex-1 p-5">
                                        <div className="mb-5 grid grid-cols-1 gap-3 xl:grid-cols-3">
                                            <div className="rounded-lg border border-border bg-background p-4">
                                                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                                    WireGuard mesh
                                                </p>
                                                <dl className="mt-3 space-y-2 text-xs">
                                                    <div className="flex justify-between gap-3">
                                                        <dt className="text-muted-foreground">Interface</dt>
                                                        <dd className="font-medium text-foreground">
                                                            {selectedCluster.wireguardInterface}
                                                        </dd>
                                                    </div>
                                                    <div className="flex justify-between gap-3">
                                                        <dt className="text-muted-foreground">Management pool</dt>
                                                        <dd className="font-medium text-foreground">
                                                            {selectedCluster.wireguardManagementPool}
                                                        </dd>
                                                    </div>
                                                    <div className="flex justify-between gap-3">
                                                        <dt className="text-muted-foreground">Listen port</dt>
                                                        <dd className="font-medium text-foreground">
                                                            {selectedCluster.wireguardListenPort}
                                                        </dd>
                                                    </div>
                                                </dl>
                                            </div>

                                            <div className="rounded-lg border border-border bg-background p-4">
                                                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                                    Container networking
                                                </p>
                                                <dl className="mt-3 space-y-2 text-xs">
                                                    <div className="flex justify-between gap-3">
                                                        <dt className="text-muted-foreground">Pool</dt>
                                                        <dd className="font-medium text-foreground">
                                                            {selectedCluster.containerNetworkPool}
                                                        </dd>
                                                    </div>
                                                    <div className="flex justify-between gap-3">
                                                        <dt className="text-muted-foreground">Prefix</dt>
                                                        <dd className="font-medium text-foreground">
                                                            /{selectedCluster.containerNetworkPrefix}
                                                        </dd>
                                                    </div>
                                                    <div className="flex justify-between gap-3">
                                                        <dt className="text-muted-foreground">Namespaces</dt>
                                                        <dd className="font-medium text-foreground">
                                                            {selectedCluster.namespaces.join(', ')}
                                                        </dd>
                                                    </div>
                                                </dl>
                                            </div>

                                            <div className="rounded-lg border border-border bg-background p-4">
                                                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                                    CLI state
                                                </p>
                                                <dl className="mt-3 space-y-2 text-xs">
                                                    <div className="flex justify-between gap-3">
                                                        <dt className="text-muted-foreground">coold</dt>
                                                        <dd className="font-medium text-foreground">
                                                            {selectedCluster.cooldVersion}
                                                        </dd>
                                                    </div>
                                                    <div className="flex justify-between gap-3">
                                                        <dt className="text-muted-foreground">Corrosion</dt>
                                                        <dd className="font-medium text-foreground">
                                                            {selectedCluster.corrosionVersion}
                                                        </dd>
                                                    </div>
                                                    <div className="flex justify-between gap-3">
                                                        <dt className="text-muted-foreground">Last run</dt>
                                                        <dd className="font-medium text-foreground">
                                                            {selectedCluster.lastCliStatus ?? 'Never'}
                                                        </dd>
                                                    </div>
                                                </dl>
                                                {selectedCluster.lastCliSummary ? (
                                                    <pre className="mt-3 max-h-28 overflow-auto whitespace-pre-wrap rounded bg-muted/30 p-2 text-xs text-muted-foreground">
                                                        {selectedCluster.lastCliSummary}
                                                    </pre>
                                                ) : null}
                                            </div>
                                        </div>

                                        <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                            <div>
                                                <h3 className="text-base font-semibold text-foreground">
                                                    Servers in this cluster
                                                </h3>
                                                <p className="text-sm text-muted-foreground">
                                                    Connection and builder details for each server assigned to this
                                                    cluster.
                                                </p>
                                            </div>
                                            <Button
                                                type="button"
                                                variant="coolify"
                                                size="sm"
                                                aria-label="Add server to cluster"
                                                onClick={() => {
                                                    setServerBuilderEnabled(selectedCluster.builderEnabled);
                                                    setServerBuilderCapacity(String(selectedCluster.builderCapacity));
                                                    setServerBuilderCpuQuota(selectedCluster.builderCpuQuota);
                                                    setIsAddServerDialogOpen(true);
                                                }}
                                            >
                                                <span className="text-base leading-none">+</span>
                                                Add server
                                            </Button>
                                        </div>

                                        {bootstrapServerError ? (
                                            <div className="mb-3 rounded-md border border-destructive/30 bg-destructive/10 p-3">
                                                <p className="text-sm font-medium text-destructive">
                                                    {bootstrapServerError}
                                                </p>
                                                {selectedCluster.lastCliSummary ? (
                                                    <pre className="mt-2 max-h-48 overflow-auto whitespace-pre-wrap rounded bg-background p-2 text-xs text-muted-foreground">
                                                        {selectedCluster.lastCliSummary}
                                                    </pre>
                                                ) : null}
                                            </div>
                                        ) : null}

                                        {selectedCluster.servers.length === 0 ? (
                                            <div className="rounded-lg border border-dashed border-border p-8 text-center">
                                                <p className="text-sm font-medium text-foreground">
                                                    No servers assigned
                                                </p>
                                                <p className="mt-1 text-sm text-muted-foreground">
                                                    Servers will appear here after they are added to this cluster.
                                                </p>
                                            </div>
                                        ) : (
                                            <div className="grid grid-cols-1 gap-3 xl:grid-cols-2">
                                                {selectedCluster.servers.map((server) => {
                                                    const isCheckingServer = checkingServers.has(server.id);
                                                    const isBootstrappingServer = bootstrappingServers.has(server.id);
                                                    const isDeletingServer = deletingServers.has(server.id);

                                                    return (
                                                        <article
                                                            key={server.id}
                                                        className="rounded-lg border border-border bg-background p-4"
                                                    >
                                                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                            <div className="min-w-0">
                                                                <h4 className="break-words text-sm font-semibold text-foreground">
                                                                    {server.name}
                                                                </h4>
                                                                <p className="mt-1 break-all text-xs text-muted-foreground">
                                                                    {server.host}
                                                                </p>
                                                            </div>
                                                            <div className="flex w-full flex-col items-stretch gap-2 sm:w-auto sm:flex-row sm:flex-wrap sm:items-center sm:justify-end">
                                                                <span className="rounded-full border border-border bg-muted/40 px-2 py-1 text-xs text-muted-foreground">
                                                                    Bootstrap: {server.status}
                                                                </span>
                                                                <Button
                                                                    type="button"
                                                                    variant="outline"
                                                                    size="sm"
                                                                    disabled={isCheckingServer}
                                                                    onClick={() => void checkServer(server)}
                                                                >
                                                                    {isCheckingServer
                                                                        ? 'Checking...'
                                                                        : 'Check SSH'}
                                                                </Button>
                                                                {server.lastBootstrappedAt === null ? (
                                                                    <Button
                                                                        type="button"
                                                                        variant="outline"
                                                                        size="sm"
                                                                        disabled={isBootstrappingServer}
                                                                        onClick={() => void bootstrapServer(server)}
                                                                    >
                                                                        {isBootstrappingServer
                                                                            ? 'Bootstrapping...'
                                                                            : 'Bootstrap'}
                                                                    </Button>
                                                                ) : null}
                                                                <Button
                                                                    type="button"
                                                                    variant="outline"
                                                                    size="sm"
                                                                    onClick={() => openEditServerDialog(server)}
                                                                >
                                                                    Edit server
                                                                </Button>
                                                                {server.lastBootstrappedAt === null ? (
                                                                    <Button
                                                                        type="button"
                                                                        variant="delete"
                                                                        size="sm"
                                                                        disabled={isDeletingServer}
                                                                        onClick={() => openDeleteServerDialog(server)}
                                                                    >
                                                                        {isDeletingServer
                                                                            ? 'Deleting...'
                                                                            : 'Delete'}
                                                                    </Button>
                                                                ) : null}
                                                            </div>
                                                        </div>

                                                        <dl className="mt-4 grid grid-cols-1 gap-3 text-xs sm:grid-cols-2">
                                                            <div>
                                                                <dt className="text-muted-foreground">
                                                                    Builder capacity
                                                                </dt>
                                                                <dd className="mt-1 break-words font-medium text-foreground">
                                                                    {server.builderEnabled
                                                                        ? server.builderCapacity
                                                                        : 'Disabled'}
                                                                </dd>
                                                            </div>
                                                            <div>
                                                                <dt className="text-muted-foreground">
                                                                    Builder CPU quota
                                                                </dt>
                                                                <dd className="mt-1 break-words font-medium text-foreground">
                                                                    {server.builderCpuQuota}
                                                                </dd>
                                                            </div>
                                                            <div>
                                                                <dt className="text-muted-foreground">WireGuard IP</dt>
                                                                <dd className="mt-1 break-words font-medium text-foreground">
                                                                    {server.wireguardManagementIp ?? 'Not assigned'}
                                                                </dd>
                                                            </div>
                                                            <div>
                                                                <dt className="text-muted-foreground">CLI node</dt>
                                                                <dd className="mt-1 break-words font-medium text-foreground">
                                                                    {server.nodeAddress ?? server.host}
                                                                </dd>
                                                            </div>
                                                            <div>
                                                                <dt className="text-muted-foreground">Private key</dt>
                                                                <dd className="mt-1 break-words font-medium text-foreground">
                                                                    {server.privateKeyName ?? 'No key'}
                                                                </dd>
                                                            </div>
                                                            <div>
                                                                <dt className="text-muted-foreground">
                                                                    Last bootstrap
                                                                </dt>
                                                                <dd className="mt-1 break-words font-medium text-foreground">
                                                                    {formatDate(server.lastBootstrappedAt)}
                                                                </dd>
                                                            </div>
                                                        </dl>

                                                        <p className="mt-4 text-xs text-muted-foreground">
                                                            Capabilities: {normalizeCapabilities(server.capabilities)}
                                                        </p>

                                                        <div className="mt-4 rounded-md border border-border bg-muted/30 p-3 text-xs">
                                                            <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                                                <span className="font-medium text-foreground">
                                                                    Latest SSH check
                                                                    {server.lastStatusCheck
                                                                        ? `: ${server.lastStatusCheck}`
                                                                        : ''}
                                                                </span>
                                                                <span className="text-muted-foreground">
                                                                    {server.lastStatusCheckedAt
                                                                        ? formatDate(server.lastStatusCheckedAt)
                                                                        : 'Never run'}
                                                                </span>
                                                            </div>
                                                            {server.lastStatusOutput ? (
                                                                <pre className="mt-2 max-h-40 overflow-auto whitespace-pre-wrap rounded bg-background p-2 text-muted-foreground">
                                                                    {server.lastStatusOutput}
                                                                </pre>
                                                            ) : (
                                                                <p className="mt-2 text-muted-foreground">
                                                                    Run Check SSH to verify connectivity and capture
                                                                    diagnostic output.
                                                                </p>
                                                            )}
                                                        </div>
                                                        </article>
                                                    );
                                                })}
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
                            open={isDeleteDialogOpen}
                            onOpenChange={(open) => {
                                setIsDeleteDialogOpen(open);

                                if (!open) {
                                    setClusterPendingDelete(null);
                                    setServerPendingDelete(null);
                                }
                            }}
                        >
                            <DialogContent className="max-w-md" showCloseButton={false}>
                                <DialogHeader>
                                    <DialogTitle>Confirm deletion</DialogTitle>
                                    <DialogDescription>
                                        {serverPendingDelete
                                            ? `Delete unbootstrapped server ${serverPendingDelete.name}? This only removes it from this cluster.`
                                            : `Delete cluster ${clusterPendingDelete?.name ?? ''}? This cannot be undone.`}
                                    </DialogDescription>
                                </DialogHeader>
                                <DialogFooter>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setIsDeleteDialogOpen(false)}
                                    >
                                        Cancel
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="delete"
                                        disabled={isDeletingCluster || deletingServers.hasAny}
                                        onClick={() => void confirmDelete()}
                                    >
                                        {isDeletingCluster || deletingServers.hasAny ? 'Deleting...' : 'Delete'}
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>

                        <Dialog
                            open={isCreateDialogOpen}
                            onOpenChange={(open) => {
                                setIsCreateDialogOpen(open);

                                if (!open) {
                                    setErrors({});
                                }
                            }}
                        >
                            <DialogContent className="max-w-3xl">
                                <DialogHeader>
                                    <DialogTitle>Create cluster</DialogTitle>
                                    <DialogDescription>
                                        Create an empty cluster now. Servers can be assigned after they exist.
                                    </DialogDescription>
                                </DialogHeader>

                                <form className="mt-5 flex flex-col gap-4" onSubmit={createCluster}>
                                    <Field>
                                        <FieldLabel>Name</FieldLabel>
                                        <Input
                                            value={name}
                                            onChange={(event) => setName(event.target.value)}
                                            placeholder="Production Mesh"
                                            aria-invalid={errors.name ? true : undefined}
                                        />
                                        <FieldError message={errors.name?.[0]} />
                                    </Field>

                                    <Field>
                                        <FieldLabel>Description</FieldLabel>
                                        <Textarea
                                            value={description}
                                            onChange={(event) => setDescription(event.target.value)}
                                            placeholder="What this cluster is used for"
                                            aria-invalid={errors.description ? true : undefined}
                                        />
                                        <FieldError message={errors.description?.[0]} />
                                    </Field>

                                    <div className="rounded-lg border border-border bg-muted/20">
                                        <button
                                            type="button"
                                            className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left text-sm font-medium text-foreground"
                                            onClick={() => setShowAdvancedConfiguration((value) => !value)}
                                        >
                                            <span>Advanced configuration</span>
                                            <span className="text-xs text-muted-foreground">
                                                {showAdvancedConfiguration ? 'Hide' : 'Show'}
                                            </span>
                                        </button>

                                        {showAdvancedConfiguration ? (
                                            <div className="grid grid-cols-1 gap-4 border-t border-border p-4 sm:grid-cols-2">
                                                <Field>
                                                    <FieldLabel>WireGuard interface</FieldLabel>
                                                    <Input
                                                        value={wireguardInterface}
                                                        onChange={(event) => setWireguardInterface(event.target.value)}
                                                        aria-invalid={errors.wireguard_interface ? true : undefined}
                                                    />
                                                    <FieldError message={errors.wireguard_interface?.[0]} />
                                                </Field>

                                                <Field>
                                                    <FieldLabel>WireGuard pool</FieldLabel>
                                                    <Input
                                                        value={wireguardManagementPool}
                                                        onChange={(event) =>
                                                            setWireguardManagementPool(event.target.value)
                                                        }
                                                        aria-invalid={
                                                            errors.wireguard_management_pool ? true : undefined
                                                        }
                                                    />
                                                    <FieldError message={errors.wireguard_management_pool?.[0]} />
                                                </Field>

                                                <Field>
                                                    <FieldLabel>WireGuard listen port</FieldLabel>
                                                    <Input
                                                        value={wireguardListenPort}
                                                        onChange={(event) => setWireguardListenPort(event.target.value)}
                                                        inputMode="numeric"
                                                        aria-invalid={errors.wireguard_listen_port ? true : undefined}
                                                    />
                                                    <FieldError message={errors.wireguard_listen_port?.[0]} />
                                                </Field>

                                                <Field>
                                                    <FieldLabel>Container pool</FieldLabel>
                                                    <Input
                                                        value={containerNetworkPool}
                                                        onChange={(event) =>
                                                            setContainerNetworkPool(event.target.value)
                                                        }
                                                        aria-invalid={errors.container_network_pool ? true : undefined}
                                                    />
                                                    <FieldError message={errors.container_network_pool?.[0]} />
                                                </Field>

                                                <Field>
                                                    <FieldLabel>Container prefix</FieldLabel>
                                                    <Input
                                                        value={containerNetworkPrefix}
                                                        onChange={(event) =>
                                                            setContainerNetworkPrefix(event.target.value)
                                                        }
                                                        inputMode="numeric"
                                                        aria-invalid={
                                                            errors.container_network_prefix ? true : undefined
                                                        }
                                                    />
                                                    <FieldError message={errors.container_network_prefix?.[0]} />
                                                </Field>

                                                <Field>
                                                    <FieldLabel>Namespaces</FieldLabel>
                                                    <Input
                                                        value={namespaces}
                                                        onChange={(event) => setNamespaces(event.target.value)}
                                                        placeholder="default,preview"
                                                        aria-invalid={errors.namespaces ? true : undefined}
                                                    />
                                                    <FieldError message={errors.namespaces?.[0]} />
                                                </Field>

                                                <Field className="flex-row items-center gap-2">
                                                    <input
                                                        type="checkbox"
                                                        checked={defaultDenyContainers}
                                                        onChange={(event) =>
                                                            setDefaultDenyContainers(event.target.checked)
                                                        }
                                                    />
                                                    <FieldLabel>Default-deny containers</FieldLabel>
                                                </Field>

                                                <Field className="flex-row items-center gap-2">
                                                    <input
                                                        type="checkbox"
                                                        checked={builderEnabled}
                                                        onChange={(event) => setBuilderEnabled(event.target.checked)}
                                                    />
                                                    <FieldLabel>Enable builders</FieldLabel>
                                                </Field>

                                                <Field>
                                                    <FieldLabel>coold version</FieldLabel>
                                                    <Input
                                                        value={cooldVersion}
                                                        onChange={(event) => setCooldVersion(event.target.value)}
                                                        aria-invalid={errors.coold_version ? true : undefined}
                                                    />
                                                    <FieldError message={errors.coold_version?.[0]} />
                                                </Field>

                                                <Field>
                                                    <FieldLabel>Corrosion version</FieldLabel>
                                                    <Input
                                                        value={corrosionVersion}
                                                        onChange={(event) => setCorrosionVersion(event.target.value)}
                                                        aria-invalid={errors.corrosion_version ? true : undefined}
                                                    />
                                                    <FieldError message={errors.corrosion_version?.[0]} />
                                                </Field>

                                                <Field>
                                                    <FieldLabel>Corrosion gossip port</FieldLabel>
                                                    <Input
                                                        value={corrosionGossipPort}
                                                        onChange={(event) => setCorrosionGossipPort(event.target.value)}
                                                        inputMode="numeric"
                                                        aria-invalid={errors.corrosion_gossip_port ? true : undefined}
                                                    />
                                                    <FieldError message={errors.corrosion_gossip_port?.[0]} />
                                                </Field>

                                                <Field>
                                                    <FieldLabel>Corrosion API port</FieldLabel>
                                                    <Input
                                                        value={corrosionApiPort}
                                                        onChange={(event) => setCorrosionApiPort(event.target.value)}
                                                        inputMode="numeric"
                                                        aria-invalid={errors.corrosion_api_port ? true : undefined}
                                                    />
                                                    <FieldError message={errors.corrosion_api_port?.[0]} />
                                                </Field>

                                                <Field>
                                                    <FieldLabel>Builder capacity</FieldLabel>
                                                    <Input
                                                        value={builderCapacity}
                                                        onChange={(event) => setBuilderCapacity(event.target.value)}
                                                        inputMode="numeric"
                                                        aria-invalid={errors.builder_capacity ? true : undefined}
                                                    />
                                                    <FieldError message={errors.builder_capacity?.[0]} />
                                                </Field>

                                                <Field>
                                                    <FieldLabel>Builder CPU quota</FieldLabel>
                                                    <Input
                                                        value={builderCpuQuota}
                                                        onChange={(event) => setBuilderCpuQuota(event.target.value)}
                                                        aria-invalid={errors.builder_cpu_quota ? true : undefined}
                                                    />
                                                    <FieldError message={errors.builder_cpu_quota?.[0]} />
                                                </Field>

                                                <Field>
                                                    <FieldLabel>Builder memory max</FieldLabel>
                                                    <Input
                                                        value={builderMemoryMax}
                                                        onChange={(event) => setBuilderMemoryMax(event.target.value)}
                                                        aria-invalid={errors.builder_memory_max ? true : undefined}
                                                    />
                                                    <FieldError message={errors.builder_memory_max?.[0]} />
                                                </Field>

                                                <Field>
                                                    <FieldLabel>Builder timeout</FieldLabel>
                                                    <Input
                                                        value={builderTimeoutSecs}
                                                        onChange={(event) => setBuilderTimeoutSecs(event.target.value)}
                                                        inputMode="numeric"
                                                        aria-invalid={errors.builder_timeout_secs ? true : undefined}
                                                    />
                                                    <FieldError message={errors.builder_timeout_secs?.[0]} />
                                                </Field>
                                            </div>
                                        ) : null}
                                    </div>

                                    <DialogFooter>
                                        <Button type="submit" variant="coolify" disabled={isSubmitting}>
                                            {isSubmitting ? 'Creating...' : 'Create cluster'}
                                        </Button>
                                    </DialogFooter>
                                </form>
                            </DialogContent>
                        </Dialog>

                        <Dialog
                            open={isAddServerDialogOpen}
                            onOpenChange={(open) => {
                                setIsAddServerDialogOpen(open);

                                if (!open) {
                                    resetServerForm();
                                }
                            }}
                        >
                            <DialogContent className="max-w-2xl">
                                <DialogHeader>
                                    <DialogTitle>Add server</DialogTitle>
                                    <DialogDescription>
                                        Add a remote server to this WireGuard cluster. CLI-generated mesh values are
                                        saved after bootstrap or extend runs.
                                    </DialogDescription>
                                </DialogHeader>

                                <form className="mt-5 flex flex-col gap-4" onSubmit={createServer}>
                                    <Field>
                                        <FieldLabel>Name</FieldLabel>
                                        <Input
                                            value={serverName}
                                            onChange={(event) => setServerName(event.target.value)}
                                            placeholder="prod-01"
                                            aria-invalid={serverErrors.name ? true : undefined}
                                        />
                                        <FieldError message={serverErrors.name?.[0]} />
                                    </Field>

                                    <Field>
                                        <FieldLabel>Host</FieldLabel>
                                        <Input
                                            value={serverHost}
                                            onChange={(event) => setServerHost(event.target.value)}
                                            placeholder="203.0.113.10"
                                            aria-invalid={serverErrors.host ? true : undefined}
                                        />
                                        <FieldError message={serverErrors.host?.[0]} />
                                    </Field>

                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <Field>
                                            <FieldLabel>Bootstrap SSH user</FieldLabel>
                                            <Input
                                                value={serverSshUser}
                                                onChange={(event) => setServerSshUser(event.target.value)}
                                                aria-invalid={serverErrors.ssh_user ? true : undefined}
                                            />
                                            <FieldError message={serverErrors.ssh_user?.[0]} />
                                        </Field>

                                        <Field>
                                            <FieldLabel>Bootstrap SSH port</FieldLabel>
                                            <Input
                                                value={serverSshPort}
                                                onChange={(event) => setServerSshPort(event.target.value)}
                                                inputMode="numeric"
                                                aria-invalid={serverErrors.ssh_port ? true : undefined}
                                            />
                                            <FieldError message={serverErrors.ssh_port?.[0]} />
                                        </Field>
                                    </div>

                                    <Field>
                                        <FieldLabel>Private key</FieldLabel>
                                        <select
                                            value={selectedPrivateKeyId}
                                            onChange={(event) => setSelectedPrivateKeyId(event.target.value)}
                                            className="appearance-none rounded-md border border-border bg-background bg-[length:1rem_1rem] bg-[position:right_0.75rem_center] bg-no-repeat px-3 py-2 pr-10 text-sm outline-none transition focus:border-ring focus:ring-1 focus:ring-ring aria-invalid:border-destructive aria-invalid:ring-1 aria-invalid:ring-destructive/20 dark:aria-invalid:border-destructive/50 dark:aria-invalid:ring-destructive/40"
                                            aria-invalid={serverErrors.private_key_id ? true : undefined}
                                            style={{
                                                backgroundImage: `url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 256 256' fill='none' stroke='%23ffffff' stroke-width='28' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m64 96 64 64 64-64'/%3E%3C/svg%3E")`,
                                            }}
                                        >
                                            <option value="">Select a private key</option>
                                            {privateKeys.map((privateKey) => (
                                                <option key={privateKey.id} value={privateKey.id}>
                                                    {privateKey.name}
                                                </option>
                                            ))}
                                        </select>
                                        <FieldError message={serverErrors.private_key_id?.[0]} />
                                    </Field>

                                    <div className="rounded-lg border border-border bg-muted/20">
                                        <button
                                            type="button"
                                            className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left text-sm font-medium text-foreground"
                                            onClick={() => setShowAdvancedServerConfiguration((value) => !value)}
                                        >
                                            <span>Advanced server configuration</span>
                                            <span className="text-xs text-muted-foreground">
                                                {showAdvancedServerConfiguration ? 'Hide' : 'Show'}
                                            </span>
                                        </button>

                                        {showAdvancedServerConfiguration ? (
                                            <div className="grid grid-cols-1 gap-4 border-t border-border p-4 sm:grid-cols-2">
                                                <Field>
                                                    <FieldLabel>CLI node address</FieldLabel>
                                                    <Input
                                                        value={serverNodeAddress}
                                                        onChange={(event) => setServerNodeAddress(event.target.value)}
                                                        placeholder="Defaults to host"
                                                        aria-invalid={serverErrors.node_address ? true : undefined}
                                                    />
                                                    <FieldError message={serverErrors.node_address?.[0]} />
                                                </Field>

                                                <Field>
                                                    <FieldLabel>Builder capacity</FieldLabel>
                                                    <Input
                                                        value={serverBuilderCapacity}
                                                        onChange={(event) =>
                                                            setServerBuilderCapacity(event.target.value)
                                                        }
                                                        inputMode="numeric"
                                                        aria-invalid={serverErrors.builder_capacity ? true : undefined}
                                                    />
                                                    <FieldError message={serverErrors.builder_capacity?.[0]} />
                                                </Field>

                                                <Field>
                                                    <FieldLabel>Builder CPU quota</FieldLabel>
                                                    <Input
                                                        value={serverBuilderCpuQuota}
                                                        onChange={(event) =>
                                                            setServerBuilderCpuQuota(event.target.value)
                                                        }
                                                        placeholder="200%"
                                                        aria-invalid={serverErrors.builder_cpu_quota ? true : undefined}
                                                    />
                                                    <FieldError message={serverErrors.builder_cpu_quota?.[0]} />
                                                </Field>

                                                <Field className="flex-row items-center gap-2">
                                                    <input
                                                        type="checkbox"
                                                        checked={serverBuilderEnabled}
                                                        onChange={(event) =>
                                                            setServerBuilderEnabled(event.target.checked)
                                                        }
                                                    />
                                                    <FieldLabel>Enable builder on this server</FieldLabel>
                                                </Field>

                                                <Field>
                                                    <FieldLabel>WireGuard listen override</FieldLabel>
                                                    <Input
                                                        value={wireguardListenPortOverride}
                                                        onChange={(event) =>
                                                            setWireguardListenPortOverride(event.target.value)
                                                        }
                                                        inputMode="numeric"
                                                        placeholder="Optional"
                                                        aria-invalid={
                                                            serverErrors.wireguard_listen_port_override
                                                                ? true
                                                                : undefined
                                                        }
                                                    />
                                                    <FieldError
                                                        message={serverErrors.wireguard_listen_port_override?.[0]}
                                                    />
                                                </Field>

                                                <Field className="sm:col-span-2">
                                                    <FieldLabel>WireGuard endpoint override</FieldLabel>
                                                    <Input
                                                        value={wireguardEndpointOverride}
                                                        onChange={(event) =>
                                                            setWireguardEndpointOverride(event.target.value)
                                                        }
                                                        placeholder="host.example.com:51821"
                                                        aria-invalid={
                                                            serverErrors.wireguard_endpoint_override ? true : undefined
                                                        }
                                                    />
                                                    <FieldError
                                                        message={serverErrors.wireguard_endpoint_override?.[0]}
                                                    />
                                                </Field>
                                            </div>
                                        ) : null}
                                    </div>

                                    <DialogFooter>
                                        <Button
                                            type="submit"
                                            variant="coolify"
                                            disabled={isServerSubmitting || !selectedCluster}
                                        >
                                            {isServerSubmitting ? 'Adding...' : 'Add server'}
                                        </Button>
                                    </DialogFooter>
                                </form>
                            </DialogContent>
                        </Dialog>

                        <Dialog
                            open={isEditServerDialogOpen}
                            onOpenChange={(open) => {
                                setIsEditServerDialogOpen(open);

                                if (!open) {
                                    resetEditServerForm();
                                }
                            }}
                        >
                            <DialogContent className="max-w-xl">
                                <DialogHeader>
                                    <DialogTitle>Edit server</DialogTitle>
                                    <DialogDescription>
                                        Update builder scheduling limits for {editingServer?.name ?? 'this server'}.
                                        Networking and bootstrap settings stay locked after creation.
                                    </DialogDescription>
                                </DialogHeader>

                                <form className="mt-5 flex flex-col gap-4" onSubmit={updateServer}>
                                    <Field className="flex-row items-center gap-2">
                                        <input
                                            type="checkbox"
                                            checked={editServerBuilderEnabled}
                                            onChange={(event) => setEditServerBuilderEnabled(event.target.checked)}
                                        />
                                        <FieldLabel>Enable builder on this server</FieldLabel>
                                    </Field>

                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <Field>
                                            <FieldLabel>Builder capacity</FieldLabel>
                                            <Input
                                                value={editServerBuilderCapacity}
                                                onChange={(event) => setEditServerBuilderCapacity(event.target.value)}
                                                inputMode="numeric"
                                                aria-invalid={editServerErrors.builder_capacity ? true : undefined}
                                            />
                                            <FieldError message={editServerErrors.builder_capacity?.[0]} />
                                        </Field>

                                        <Field>
                                            <FieldLabel>Builder CPU quota</FieldLabel>
                                            <Input
                                                value={editServerBuilderCpuQuota}
                                                onChange={(event) => setEditServerBuilderCpuQuota(event.target.value)}
                                                placeholder="200%"
                                                aria-invalid={editServerErrors.builder_cpu_quota ? true : undefined}
                                            />
                                            <FieldError message={editServerErrors.builder_cpu_quota?.[0]} />
                                        </Field>
                                    </div>

                                    <p className="text-xs text-muted-foreground">
                                        Host, bootstrap credentials, node address, and WireGuard overrides are not
                                        editable here.
                                    </p>

                                    <DialogFooter>
                                        <Button
                                            type="submit"
                                            variant="coolify"
                                            disabled={isServerUpdateSubmitting || !selectedCluster || !editingServer}
                                        >
                                            {isServerUpdateSubmitting ? 'Saving...' : 'Save server'}
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
