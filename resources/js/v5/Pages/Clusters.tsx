import { Head } from '@inertiajs/react';
import { DotsThreeIcon } from '@phosphor-icons/react';
import { useEffect, useMemo, useState } from 'react';
import type { FormEvent } from 'react';

import { AppNavbar } from '@/components/app-navbar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
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
    ingress_type?: string[];
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
    status: string;
    output: string;
    checkedAt: string;
};

type DeleteServerResponse = {
    cluster: V5Cluster;
};

type CooldLogsResponse = {
    output: string;
    fetchedAt: string;
};

type BootstrapServerResponse = {
    cluster?: V5Cluster;
    message?: string;
};

type ServerSshCheck = {
    status: string;
    output: string;
    checkedAt: string;
};

type V5ClusterUpdatedEvent = {
    cluster: V5Cluster | null;
};

function statusLabel(status: string): string {
    return status
        .split(/[-_\s]+/)
        .filter(Boolean)
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}

function statusBadgeClass(status: string): string {
    if (status === 'installed' || status === 'running') {
        return 'border-emerald-500/30 bg-emerald-500/10 text-emerald-400';
    }

    if (['queued', 'starting', 'bootstrapping'].includes(status)) {
        return 'border-warning/30 bg-warning/10 text-warning';
    }

    if (['unreachable', 'failed', 'error'].includes(status)) {
        return 'border-destructive/30 bg-destructive/10 text-destructive';
    }

    return 'border-border bg-muted/40 text-muted-foreground';
}

type EchoChannel = {
    listen: (event: string, callback: (payload: unknown) => void) => EchoChannel;
    subscribed?: (callback: () => void) => EchoChannel;
    error?: (callback: (error: unknown) => void) => EchoChannel;
};

type EchoClient = {
    private: (channel: string) => EchoChannel;
    leave?: (channel: string) => void;
    leaveChannel?: (channel: string) => void;
};

declare global {
    interface Window {
        Echo?: EchoClient;
    }
}

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

const ingressTypes = [
    {
        label: 'Caddy',
        value: 'caddy',
    },
];

function formatDate(value: string | null): string {
    if (value === null) {
        return 'Never';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

export default function Clusters({
    flux,
    currentTeam = null,
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
    const [serverIngressEnabled, setServerIngressEnabled] = useState(false);
    const [serverIngressType, setServerIngressType] = useState('caddy');
    const [serverBuilderCapacity, setServerBuilderCapacity] = useState('2');
    const [serverBuilderCpuQuota, setServerBuilderCpuQuota] = useState(clusterDefaults.builderCpuQuota);
    const [wireguardListenPortOverride, setWireguardListenPortOverride] = useState('');
    const [wireguardEndpointOverride, setWireguardEndpointOverride] = useState('');
    const [serverErrors, setServerErrors] = useState<ServerFormErrors>({});
    const [editingServer, setEditingServer] = useState<V5Server | null>(null);
    const [editServerBuilderEnabled, setEditServerBuilderEnabled] = useState(true);
    const [editServerIngressEnabled, setEditServerIngressEnabled] = useState(false);
    const [editServerIngressType, setEditServerIngressType] = useState('caddy');
    const [editServerBuilderCapacity, setEditServerBuilderCapacity] = useState('2');
    const [editServerBuilderCpuQuota, setEditServerBuilderCpuQuota] = useState(clusterDefaults.builderCpuQuota);
    const [editServerErrors, setEditServerErrors] = useState<ServerFormErrors>({});
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [isServerSubmitting, setIsServerSubmitting] = useState(false);
    const [isServerUpdateSubmitting, setIsServerUpdateSubmitting] = useState(false);
    const checkingServers = usePendingIds<string>();
    const [sshChecks, setSshChecks] = useState<Record<string, ServerSshCheck>>({});
    const [visibleBootstrapLogs, setVisibleBootstrapLogs] = useState<Record<string, boolean>>({});
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
    const [isCooldLogsDialogOpen, setIsCooldLogsDialogOpen] = useState(false);
    const [cooldLogsServer, setCooldLogsServer] = useState<V5Server | null>(null);
    const [cooldLogsOutput, setCooldLogsOutput] = useState('');
    const [cooldLogsFetchedAt, setCooldLogsFetchedAt] = useState<string | null>(null);
    const [cooldLogsError, setCooldLogsError] = useState<string | null>(null);
    const [isLoadingCooldLogs, setIsLoadingCooldLogs] = useState(false);
    const [showAdvancedConfiguration, setShowAdvancedConfiguration] = useState(false);
    const [showAdvancedServerConfiguration, setShowAdvancedServerConfiguration] = useState(false);

    const selectedCluster = useMemo(
        () => clusterList.find((cluster) => cluster.id === selectedClusterId) ?? clusterList[0] ?? null,
        [clusterList, selectedClusterId],
    );

    const notInitializedServers = selectedCluster?.servers.filter((server) => server.lastBootstrappedAt === null) ?? [];
    const initializedServers = selectedCluster?.servers.filter((server) => server.lastBootstrappedAt !== null) ?? [];
    const hasBootstrapInProgress =
        selectedCluster?.servers.some((server) => ['queued', 'running'].includes(server.lastBootstrapStatus ?? '')) ?? false;

    useEffect(() => {
        if (!currentTeam) {
            return;
        }

        let isCancelled = false;
        let attempts = 0;
        const channelName = `team.${currentTeam.id}`;

        const interval = window.setInterval(() => {
            attempts += 1;

            if (!window.Echo) {
                if (attempts === 1) {
                    console.debug('Waiting for window.Echo before subscribing to cluster updates');
                }

                if (attempts >= 20) {
                    window.clearInterval(interval);
                }

                return;
            }

            window.clearInterval(interval);

            if (isCancelled) {
                return;
            }

            const channel = window.Echo.private(channelName);

            channel.subscribed?.(() => console.debug(`Subscribed to private-${channelName} for cluster updates`));
            channel.error?.((error) => console.error(`Subscription error on private-${channelName}`, error));
            channel.listen('.v5.cluster.updated', (payload) => {
                const event = payload as V5ClusterUpdatedEvent;

                if (!event.cluster) {
                    return;
                }

                setClusterList((currentClusters) =>
                    currentClusters.map((cluster) => (cluster.id === event.cluster?.id ? event.cluster : cluster)),
                );
            });
        }, 500);

        return () => {
            isCancelled = true;
            window.clearInterval(interval);
            window.Echo?.leave?.(channelName) ?? window.Echo?.leaveChannel?.(`private-${channelName}`);
        };
    }, [currentTeam]);

    useEffect(() => {
        if (!selectedCluster || !hasBootstrapInProgress) {
            return;
        }

        let isCancelled = false;

        async function refreshCluster(): Promise<void> {
            if (!selectedCluster) {
                return;
            }

            const response = await fetch(`/v5/clusters/${selectedCluster.id}`, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                },
            });

            if (!response.ok || isCancelled) {
                return;
            }

            const payload = (await response.json()) as { cluster?: V5Cluster };

            if (payload.cluster) {
                setClusterList((currentClusters) =>
                    currentClusters.map((cluster) => (cluster.id === payload.cluster?.id ? payload.cluster : cluster)),
                );
            }
        }

        const interval = window.setInterval(() => void refreshCluster(), 3000);

        return () => {
            isCancelled = true;
            window.clearInterval(interval);
        };
    }, [hasBootstrapInProgress, selectedCluster]);

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
                ingress_enabled: serverIngressEnabled,
                ingress_type: serverIngressEnabled ? serverIngressType : null,
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
                ingress_enabled: editServerIngressEnabled,
                ingress_type: editServerIngressEnabled ? editServerIngressType : null,
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
            const payload = (await response.json().catch(() => null)) as { message?: string } | null;

            setEditServerErrors({
                builder_capacity: [payload?.message ?? 'Unable to update this server. Please try again.'],
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
            setSshChecks((currentChecks) => ({
                ...currentChecks,
                [server.id]: payload,
            }));
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
            setBootstrapServerError(payload.message ?? 'Unable to queue bootstrap for this server.');
        }

        bootstrappingServers.finish(server.id);
    }


    async function loadCooldLogs(server: V5Server): Promise<void> {
        if (!selectedCluster) {
            return;
        }

        setCooldLogsServer(server);
        setIsCooldLogsDialogOpen(true);
        setIsLoadingCooldLogs(true);
        setCooldLogsError(null);
        setCooldLogsOutput('');
        setCooldLogsFetchedAt(null);

        const response = await fetch(`/v5/clusters/${selectedCluster.id}/servers/${server.id}/coold-logs?tail=200`, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
            },
        });

        const payload = (await response.json().catch(() => null)) as CooldLogsResponse & { message?: string } | null;

        if (!response.ok) {
            setCooldLogsError(payload?.message ?? 'Unable to load coold logs.');
            setIsLoadingCooldLogs(false);

            return;
        }

        setCooldLogsOutput(payload?.output ?? '');
        setCooldLogsFetchedAt(payload?.fetchedAt ?? null);
        setIsLoadingCooldLogs(false);
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
        if (!selectedCluster) {
            return;
        }

        setDeleteClusterError(null);
        setClusterPendingDelete(selectedCluster);
        setServerPendingDelete(server);
        setIsDeleteDialogOpen(true);
    }

    async function deleteServer(cluster: V5Cluster, server: V5Server): Promise<void> {
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
        setEditServerIngressEnabled(server.ingressEnabled);
        setEditServerIngressType(server.ingressType ?? 'caddy');
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
            await deleteServer(clusterPendingDelete, serverPendingDelete);

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
        setServerIngressEnabled(false);
        setServerIngressType('caddy');
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
        setEditServerIngressEnabled(false);
        setEditServerIngressType('caddy');
        setEditServerBuilderCapacity('2');
        setEditServerBuilderCpuQuota(clusterDefaults.builderCpuQuota);
        setEditServerErrors({});
    }


    function renderServerCard(server: V5Server) {
        const isCheckingServer = checkingServers.has(server.id);
        const isBootstrapInProgress = ['queued', 'running'].includes(server.lastBootstrapStatus ?? '');
        const isBootstrappingServer = bootstrappingServers.has(server.id) || isBootstrapInProgress;
        const isDeletingServer = deletingServers.has(server.id);
        const isServerInitialized = server.lastBootstrappedAt !== null;
        const latestSshCheck = sshChecks[server.id] ?? null;
        const hasBootstrapLogs = server.lastBootstrapOutput !== null && server.lastBootstrapOutput.trim() !== '';
        const canShowBootstrapLogs = hasBootstrapLogs || isBootstrapInProgress;
        const isBootstrapLogVisible =
            isBootstrapInProgress || (canShowBootstrapLogs && (visibleBootstrapLogs[server.id] ?? false));

        return (
            <article key={server.id} className="rounded-lg border border-border bg-background p-4">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0 flex-1">
                        <h4 className="break-words text-sm font-semibold text-foreground">{server.name}</h4>
                        <p className="mt-1 break-all text-xs text-muted-foreground">{server.host}</p>
                        {server.status === 'unreachable' && server.lastStatusOutput ? (
                            <p className="mt-2 break-words text-xs text-destructive">{server.lastStatusOutput}</p>
                        ) : null}
                    </div>
                    <div className="flex shrink-0 items-center justify-end gap-2 sm:flex-wrap">
                        <span
                            className={`inline-flex h-7 items-center rounded-md border px-2 text-xs font-medium ${statusBadgeClass(server.status)}`}
                            title={server.lastStatusOutput ?? undefined}
                        >
                            {statusLabel(server.status)}
                        </span>
                        {!isServerInitialized ? (
                            <div role="group" aria-label="Server initialization" className="inline-flex">
                                <span className="inline-flex h-7 items-center rounded-l-md border border-r-0 border-destructive/30 bg-destructive/10 px-2 text-xs font-medium text-destructive">
                                    Not initialized
                                </span>
                                <Button
                                    type="button"
                                    variant="coolify"
                                    size="sm"
                                    className="rounded-r-md"
                                    disabled={isBootstrappingServer}
                                    onClick={() => void bootstrapServer(server)}
                                >
                                    {isBootstrapInProgress
                                        ? 'Bootstrapping...'
                                        : isBootstrappingServer
                                          ? 'Queueing...'
                                          : 'Bootstrap'}
                                </Button>
                            </div>
                        ) : null}
                        <DropdownMenu>
                            <DropdownMenuTrigger
                                render={
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon-sm"
                                        aria-label="Server actions"
                                    />
                                }
                            >
                                <DotsThreeIcon data-icon="inline-start" weight="bold" />
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-48">
                                <DropdownMenuGroup>
                                    <DropdownMenuItem
                                        disabled={isCheckingServer}
                                        onClick={() => void checkServer(server)}
                                    >
                                        {isCheckingServer ? 'Checking...' : 'Check connection'}
                                    </DropdownMenuItem>
                                    {canShowBootstrapLogs ? (
                                        <DropdownMenuItem
                                            disabled={isBootstrapInProgress}
                                            onClick={() =>
                                                setVisibleBootstrapLogs((currentLogs) => ({
                                                    ...currentLogs,
                                                    [server.id]: !isBootstrapLogVisible,
                                                }))
                                            }
                                        >
                                            {isBootstrapInProgress
                                                ? 'Install logs shown'
                                                : isBootstrapLogVisible
                                                  ? 'Hide install logs'
                                                  : 'Show install logs'}
                                        </DropdownMenuItem>
                                    ) : null}
                                    <DropdownMenuItem onClick={() => void loadCooldLogs(server)}>
                                        Coold logs
                                    </DropdownMenuItem>
                                    <DropdownMenuItem onClick={() => openEditServerDialog(server)}>
                                        Edit server
                                    </DropdownMenuItem>
                                </DropdownMenuGroup>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    variant="destructive"
                                    disabled={isDeletingServer}
                                    onClick={() => openDeleteServerDialog(server)}
                                >
                                    {isDeletingServer ? 'Deleting...' : 'Delete server'}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>

                <dl className="mt-4 grid grid-cols-1 gap-3 text-xs sm:grid-cols-2">
                    <div>
                        <dt className="text-muted-foreground">Builder capacity</dt>
                        <dd className="mt-1 break-words font-medium text-foreground">
                            {server.builderEnabled ? server.builderCapacity : 'Disabled'}
                        </dd>
                    </div>
                    {server.builderEnabled ? (
                        <div>
                            <dt className="text-muted-foreground">Builder CPU quota</dt>
                            <dd className="mt-1 break-words font-medium text-foreground">{server.builderCpuQuota}</dd>
                        </div>
                    ) : null}
                    <div>
                        <dt className="text-muted-foreground">Caddy ingress</dt>
                        <dd className="mt-1 break-words font-medium text-foreground">
                            {server.ingressEnabled ? 'Enabled' : 'Disabled'}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground">WireGuard IP</dt>
                        <dd className="mt-1 break-words font-medium text-foreground">
                            {server.wireguardManagementIp ?? 'Not assigned'}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground">Server IP</dt>
                        <dd className="mt-1 break-words font-medium text-foreground">{server.host}</dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground">Private key</dt>
                        <dd className="mt-1 break-words font-medium text-foreground">
                            {server.privateKeyName ?? 'No key'}
                        </dd>
                    </div>
                </dl>

                {latestSshCheck ? (
                    <div className="mt-4 rounded-md border border-border bg-muted/30 p-3 text-xs">
                        <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                            <span className="font-medium text-foreground">Latest SSH check: {latestSshCheck.status}</span>
                            <span className="text-muted-foreground">{formatDate(latestSshCheck.checkedAt)}</span>
                        </div>
                        <pre className="mt-2 max-h-40 overflow-auto whitespace-pre-wrap rounded bg-background p-2 text-muted-foreground">
                            {latestSshCheck.output}
                        </pre>
                    </div>
                ) : null}

                {isBootstrapLogVisible ? (
                    <div className="mt-4 rounded-md border border-border bg-muted/30 p-3 text-xs">
                        <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                            <span className="font-medium text-foreground">
                                Install logs
                                {server.lastBootstrapStatus ? `: ${server.lastBootstrapStatus}` : ''}
                            </span>
                            <span className="text-muted-foreground">{formatDate(server.lastBootstrapRanAt)}</span>
                        </div>
                        {server.lastBootstrapOutput ? (
                            <pre className="mt-2 max-h-48 overflow-auto whitespace-pre-wrap rounded bg-background p-2 text-muted-foreground">
                                {server.lastBootstrapOutput}
                            </pre>
                        ) : (
                            <p className="mt-2 text-muted-foreground">No install logs captured yet.</p>
                        )}
                    </div>
                ) : null}
            </article>
        );
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
                    <section className="flex w-full flex-col gap-4 py-4 lg:min-h-0 lg:py-6">
                        <div className="rounded-lg border border-border bg-card p-4">
                            <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                                <div className="min-w-0">
                                    <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                        Clusters
                                    </p>
                                    <h1 className="mt-1 text-lg font-semibold text-foreground">Cluster inventory</h1>
                                </div>

                                <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                                    {clusterList.length === 0 ? (
                                        <div className="rounded-md border border-dashed border-border px-3 py-2 text-sm text-muted-foreground">
                                            No clusters yet.
                                        </div>
                                    ) : (
                                        <Select
                                            items={clusterList.map((cluster) => ({
                                                label: cluster.name,
                                                value: cluster.id,
                                            }))}
                                            value={selectedClusterId}
                                            onValueChange={(value) => {
                                                if (value !== null) {
                                                    setSelectedClusterId(value);
                                                }
                                            }}
                                        >
                                            <SelectTrigger
                                                aria-label="Select a cluster"
                                                className="w-full sm:w-72"
                                            >
                                                <SelectValue placeholder="Select a cluster" />
                                            </SelectTrigger>
                                            <SelectContent position="popper" align="end" sideOffset={4}>
                                                <SelectGroup>
                                                    {clusterList.map((cluster) => (
                                                        <SelectItem
                                                            key={cluster.id}
                                                            value={cluster.id}
                                                        >
                                                            {cluster.name}
                                                        </SelectItem>
                                                    ))}
                                                </SelectGroup>
                                            </SelectContent>
                                        </Select>
                                    )}

                                    <Button
                                        type="button"
                                        variant="coolify"
                                        size="default"
                                        aria-label="Create cluster"
                                        onClick={() => setIsCreateDialogOpen(true)}
                                        className="sm:shrink-0"
                                    >
                                        <span className="text-base leading-none">+</span>
                                        Add cluster
                                    </Button>
                                </div>
                            </div>
                        </div>

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
                                                        size="default"
                                                        onClick={openDeleteClusterDialog}
                                                        disabled={isDeletingCluster}
                                                    >
                                                        {isDeletingCluster ? 'Deleting...' : 'Delete cluster'}
                                                    </Button>
                                                ) : null}
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
                                                    Cluster state
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
                                                </dl>
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
                                            <div className="flex flex-col gap-6">
                                                {notInitializedServers.length > 0 ? (
                                                    <section aria-labelledby="not-initialized-servers-heading">
                                                        <div className="mb-3">
                                                            <h4
                                                                id="not-initialized-servers-heading"
                                                                className="text-sm font-semibold text-foreground"
                                                            >
                                                                Not initialized servers
                                                            </h4>
                                                            <p className="mt-1 text-xs text-muted-foreground">
                                                                Bootstrap these servers before using them for workloads.
                                                            </p>
                                                        </div>
                                                        <div className="grid grid-cols-1 gap-3 xl:grid-cols-2">
                                                            {notInitializedServers.map(renderServerCard)}
                                                        </div>
                                                    </section>
                                                ) : null}

                                                {initializedServers.length > 0 ? (
                                                    <section aria-labelledby="initialized-servers-heading">
                                                        <div className="mb-3">
                                                            <h4
                                                                id="initialized-servers-heading"
                                                                className="text-sm font-semibold text-foreground"
                                                            >
                                                                Servers
                                                            </h4>
                                                            <p className="mt-1 text-xs text-muted-foreground">
                                                                Initialized servers currently assigned to this cluster.
                                                            </p>
                                                        </div>
                                                        <div className="grid grid-cols-1 gap-3 xl:grid-cols-2">
                                                            {initializedServers.map(renderServerCard)}
                                                        </div>
                                                    </section>
                                                ) : null}
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
                                            ? `Delete server ${serverPendingDelete.name}? This removes it from this cluster inventory so you can add it again later.`
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

                                    <div className="rounded-lg border border-border bg-muted/20 transition-colors focus-within:border-ring">
                                        <button
                                            type="button"
                                            className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left text-sm font-medium text-foreground outline-none"
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
                                        Add a remote server to this WireGuard cluster. Generated mesh values are saved after bootstrap or extend runs.
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
                                            className="appearance-none rounded-md border border-border bg-background bg-[length:1rem_1rem] bg-[position:right_0.75rem_center] bg-no-repeat px-3 py-2 pr-10 text-sm outline-none transition focus:border-ring focus:ring-0 aria-invalid:border-destructive aria-invalid:ring-0 dark:aria-invalid:border-destructive/50"
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

                                    <div className="rounded-lg border border-border bg-muted/20 transition-colors focus-within:border-ring">
                                        <button
                                            type="button"
                                            className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left text-sm font-medium text-foreground outline-none"
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
                                                    <FieldLabel>Node address override</FieldLabel>
                                                    <Input
                                                        value={serverNodeAddress}
                                                        onChange={(event) => setServerNodeAddress(event.target.value)}
                                                        placeholder="Defaults to server IP"
                                                        aria-invalid={serverErrors.node_address ? true : undefined}
                                                    />
                                                    <FieldError message={serverErrors.node_address?.[0]} />
                                                </Field>

                                                <section className="rounded-lg border border-border bg-background/60 p-4 sm:col-span-2">
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

                                                    <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                                        <Field>
                                                            <FieldLabel>Builder capacity</FieldLabel>
                                                            <Input
                                                                value={serverBuilderCapacity}
                                                                onChange={(event) =>
                                                                    setServerBuilderCapacity(event.target.value)
                                                                }
                                                                inputMode="numeric"
                                                                aria-invalid={
                                                                    serverErrors.builder_capacity ? true : undefined
                                                                }
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
                                                                aria-invalid={
                                                                    serverErrors.builder_cpu_quota ? true : undefined
                                                                }
                                                            />
                                                            <FieldError message={serverErrors.builder_cpu_quota?.[0]} />
                                                        </Field>
                                                    </div>
                                                </section>

                                                <section className="rounded-lg border border-border bg-background/60 p-4 sm:col-span-2">
                                                    <Field className="flex-row items-center gap-2">
                                                        <input
                                                            type="checkbox"
                                                            checked={serverIngressEnabled}
                                                            onChange={(event) =>
                                                                setServerIngressEnabled(event.target.checked)
                                                            }
                                                        />
                                                        <FieldLabel>Enable ingress on this server</FieldLabel>
                                                    </Field>

                                                    <Field className="mt-4">
                                                        <FieldLabel>Ingress type</FieldLabel>
                                                        <Select
                                                            items={ingressTypes}
                                                            value={serverIngressType}
                                                            onValueChange={(value) => {
                                                                if (value !== null) {
                                                                    setServerIngressType(value);
                                                                }
                                                            }}
                                                            disabled={!serverIngressEnabled}
                                                        >
                                                            <SelectTrigger
                                                                aria-label="Select ingress type"
                                                                className="h-10 w-full rounded-md px-3 text-sm"
                                                                aria-invalid={
                                                                    serverErrors.ingress_type ? true : undefined
                                                                }
                                                            >
                                                                <SelectValue placeholder="Select ingress type" />
                                                            </SelectTrigger>
                                                            <SelectContent
                                                                position="popper"
                                                                align="start"
                                                                sideOffset={4}
                                                            >
                                                                <SelectGroup>
                                                                    {ingressTypes.map((ingressType) => (
                                                                        <SelectItem
                                                                            key={ingressType.value}
                                                                            value={ingressType.value}
                                                                        >
                                                                            {ingressType.label}
                                                                        </SelectItem>
                                                                    ))}
                                                                </SelectGroup>
                                                            </SelectContent>
                                                        </Select>
                                                        <FieldError message={serverErrors.ingress_type?.[0]} />
                                                    </Field>
                                                </section>

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
                            open={isCooldLogsDialogOpen}
                            onOpenChange={(open) => {
                                setIsCooldLogsDialogOpen(open);

                                if (!open) {
                                    setCooldLogsServer(null);
                                    setCooldLogsOutput('');
                                    setCooldLogsFetchedAt(null);
                                    setCooldLogsError(null);
                                }
                            }}
                        >
                            <DialogContent className="max-w-4xl">
                                <DialogHeader>
                                    <DialogTitle>coold logs</DialogTitle>
                                    <DialogDescription>
                                        Latest journalctl entries for {cooldLogsServer?.name ?? 'this server'}.
                                    </DialogDescription>
                                </DialogHeader>

                                <div className="mt-5 flex flex-col gap-3">
                                    <div className="flex items-center justify-between gap-3">
                                        <p className="text-xs text-muted-foreground">
                                            {cooldLogsFetchedAt ? `Fetched ${formatDate(cooldLogsFetchedAt)}` : 'Last 200 lines'}
                                        </p>
                                        {cooldLogsServer ? (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                disabled={isLoadingCooldLogs}
                                                onClick={() => void loadCooldLogs(cooldLogsServer)}
                                            >
                                                {isLoadingCooldLogs ? 'Loading...' : 'Refresh'}
                                            </Button>
                                        ) : null}
                                    </div>

                                    {cooldLogsError ? (
                                        <div className="rounded-md border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive">
                                            {cooldLogsError}
                                        </div>
                                    ) : null}

                                    <pre className="max-h-[32rem] overflow-auto rounded-lg border border-border bg-black p-4 font-mono text-xs leading-relaxed text-white">
                                        {isLoadingCooldLogs ? 'Loading coold logs...' : cooldLogsOutput || 'No coold logs returned.'}
                                    </pre>
                                </div>
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
                                        Update builder scheduling limits and ingress for {editingServer?.name ?? 'this server'}.
                                        Networking and bootstrap settings stay locked after creation.
                                    </DialogDescription>
                                </DialogHeader>

                                <form className="mt-5 flex flex-col gap-4" onSubmit={updateServer}>
                                    <section className="rounded-lg border border-border bg-muted/20 p-4">
                                        <Field className="flex-row items-center gap-2">
                                            <input
                                                type="checkbox"
                                                checked={editServerBuilderEnabled}
                                                onChange={(event) => setEditServerBuilderEnabled(event.target.checked)}
                                            />
                                            <FieldLabel>Enable builder on this server</FieldLabel>
                                        </Field>

                                        <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
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
                                    </section>

                                    <section className="rounded-lg border border-border bg-muted/20 p-4">
                                        <Field className="flex-row items-center gap-2">
                                            <input
                                                type="checkbox"
                                                checked={editServerIngressEnabled}
                                                onChange={(event) => setEditServerIngressEnabled(event.target.checked)}
                                            />
                                            <FieldLabel>Enable ingress on this server</FieldLabel>
                                        </Field>

                                        <Field className="mt-4">
                                            <FieldLabel>Ingress type</FieldLabel>
                                            <Select
                                                items={ingressTypes}
                                                value={editServerIngressType}
                                                onValueChange={(value) => {
                                                    if (value !== null) {
                                                        setEditServerIngressType(value);
                                                    }
                                                }}
                                                disabled={!editServerIngressEnabled}
                                            >
                                                <SelectTrigger
                                                    aria-label="Select ingress type"
                                                    className="h-10 w-full rounded-md px-3 text-sm"
                                                    aria-invalid={editServerErrors.ingress_type ? true : undefined}
                                                >
                                                    <SelectValue placeholder="Select ingress type" />
                                                </SelectTrigger>
                                                <SelectContent position="popper" align="start" sideOffset={4}>
                                                    <SelectGroup>
                                                        {ingressTypes.map((ingressType) => (
                                                            <SelectItem
                                                                key={ingressType.value}
                                                                value={ingressType.value}
                                                            >
                                                                {ingressType.label}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectGroup>
                                                </SelectContent>
                                            </Select>
                                            <FieldError message={editServerErrors.ingress_type?.[0]} />
                                        </Field>
                                    </section>

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
