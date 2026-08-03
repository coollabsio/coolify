import { ApplicationIngressButton } from '@/components/canvas/application-ingress-button';
import { Button } from '@/components/ui/button';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { apiRequest } from '@/lib/api';
import type { V5Application } from '@/types';
import { useCallback, useEffect, useState } from 'react';

type ApplicationInspectorSheetProps = {
    application: V5Application | null;
    isIngressSaving: boolean;
    onToggleIngress: (application: V5Application) => void;
    onClose: () => void;
};

type ApplicationLogsResponse = {
    status: string;
    statusMessage: string | null;
    containerId: string | null;
    logs: string | null;
    logsError: string | null;
};

function ApplicationLogsTab({ application }: { application: V5Application }) {
    const [isLoading, setIsLoading] = useState(false);
    const [logs, setLogs] = useState<string | null>(null);
    const [logsError, setLogsError] = useState<string | null>(null);
    const [hasLoaded, setHasLoaded] = useState(false);

    const fetchLogs = useCallback(async () => {
        setIsLoading(true);
        setLogsError(null);

        try {
            const response = await apiRequest(`/v5/applications/${application.id}/logs`, { method: 'GET' });

            if (!response.ok) {
                throw new Error(`Request failed with status ${response.status}`);
            }

            const data = (await response.json()) as ApplicationLogsResponse;
            setLogs(data.logs);
            setLogsError(data.logsError);
        } catch {
            setLogs(null);
            setLogsError('Could not reach the server to fetch container logs. Try again.');
        } finally {
            setIsLoading(false);
            setHasLoaded(true);
        }
    }, [application.id]);

    // Lazily fetch when the Logs tab first mounts for this app, and reset/refetch
    // whenever a different application is inspected.
    useEffect(() => {
        setLogs(null);
        setLogsError(null);
        setHasLoaded(false);
        void fetchLogs();
    }, [fetchLogs]);

    const statusMessage = application.effectiveStatusMessage ?? application.statusMessage;

    return (
        <div className="flex flex-col gap-4">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field>
                    <FieldLabel>Deploy status</FieldLabel>
                    <Input value={application.effectiveStatus} readOnly />
                </Field>

                <Field>
                    <FieldLabel>Container</FieldLabel>
                    <Input value={application.runtimeContainerId ?? 'Not created yet'} readOnly />
                </Field>
            </div>

            <Field>
                <FieldLabel>Status message</FieldLabel>
                <Textarea value={statusMessage ?? 'No status message yet.'} readOnly className="min-h-16" />
            </Field>

            <Field>
                <div className="flex items-center justify-between">
                    <FieldLabel>Container logs</FieldLabel>
                    <Button variant="outline" size="sm" onClick={() => void fetchLogs()} disabled={isLoading}>
                        {isLoading ? 'Refreshing…' : 'Refresh'}
                    </Button>
                </div>
                <Textarea
                    value={
                        isLoading && !hasLoaded
                            ? 'Loading container logs…'
                            : logsError
                              ? logsError
                              : logs
                                ? logs
                                : hasLoaded
                                  ? 'No container logs yet. The container has not been created. See the status message above for the deploy result.'
                                  : ''
                    }
                    readOnly
                    className="min-h-80 font-mono text-xs"
                />
            </Field>
        </div>
    );
}

export function ApplicationInspectorSheet({ application, isIngressSaving, onToggleIngress, onClose }: ApplicationInspectorSheetProps) {
    return (
        <Sheet
            open={application !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <SheetContent side="right" className="w-full overflow-hidden bg-background sm:rounded-l-xl sm:border data-[side=right]:sm:!inset-y-4 data-[side=right]:sm:!h-auto data-[side=right]:sm:!w-[45vw] data-[side=right]:sm:!max-w-[45vw]" showCloseButton blurOverlay={false}>
                {application && (
                    <>
                        <SheetHeader className="p-6 pb-4">
                            <SheetTitle>App configuration</SheetTitle>
                            <SheetDescription>
                                Double-click an application card to open configuration. Review runtime, networking, and advanced settings for{' '}
                                {application.name}.
                            </SheetDescription>
                        </SheetHeader>

                        <div className="flex flex-1 flex-col gap-6 px-6 pb-6">
                            <Tabs defaultValue="overview" className="gap-4">
                                <TabsList className="w-full justify-start" variant="line">
                                    <TabsTrigger value="overview">Overview</TabsTrigger>
                                    <TabsTrigger value="networking">Networking</TabsTrigger>
                                    <TabsTrigger value="logs">Logs</TabsTrigger>
                                    <TabsTrigger value="advanced">Advanced</TabsTrigger>
                                </TabsList>

                                <TabsContent value="overview" className="flex flex-col gap-4">
                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <Field>
                                            <FieldLabel>Name</FieldLabel>
                                            <Input value={application.name} readOnly />
                                        </Field>

                                        <Field>
                                            <FieldLabel>Status</FieldLabel>
                                            <Input value={application.effectiveStatus} readOnly />
                                        </Field>

                                        {application.effectiveStatus !== application.status && (
                                            <Field>
                                                <FieldLabel>Last known container status</FieldLabel>
                                                <Input value={application.status} readOnly />
                                            </Field>
                                        )}

                                        <Field>
                                            <FieldLabel>Image</FieldLabel>
                                            <Input value={application.image} readOnly />
                                        </Field>

                                        <Field>
                                            <FieldLabel>Server</FieldLabel>
                                            <Input
                                                value={
                                                    application.isServerReachable
                                                        ? (application.serverName ?? 'Unknown')
                                                        : `${application.serverName ?? 'Unknown'} (unreachable)`
                                                }
                                                readOnly
                                            />
                                        </Field>

                                        <Field>
                                            <FieldLabel>Container</FieldLabel>
                                            <Input value={application.containerName} readOnly />
                                        </Field>

                                        <Field>
                                            <FieldLabel>Runtime container ID</FieldLabel>
                                            <Input value={application.runtimeContainerId ?? 'Not available'} readOnly />
                                        </Field>
                                    </div>

                                    <Field>
                                        <FieldLabel>Status message</FieldLabel>
                                        <Textarea
                                            value={application.effectiveStatusMessage ?? 'No status message yet.'}
                                            readOnly
                                            className="min-h-20"
                                        />
                                    </Field>
                                </TabsContent>

                                <TabsContent value="networking" className="flex flex-col gap-4">
                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <Field>
                                            <FieldLabel>Mesh namespace</FieldLabel>
                                            <Input value={application.meshNamespace} readOnly />
                                        </Field>

                                        <Field>
                                            <FieldLabel>Mesh FQDN</FieldLabel>
                                            <Input value={application.meshFqdn} readOnly />
                                        </Field>

                                        <Field>
                                            <FieldLabel>Internal port</FieldLabel>
                                            <Input value={application.internalPort?.toString() ?? 'Not configured'} readOnly />
                                        </Field>

                                        <Field>
                                            <FieldLabel>Public ingress</FieldLabel>
                                            <Input value={application.ingressEnabled ? 'Enabled' : 'Private'} readOnly />
                                        </Field>
                                    </div>

                                    <Field>
                                        <FieldLabel>Domains</FieldLabel>
                                        <Textarea
                                            value={
                                                application.domains.length > 0
                                                    ? application.domains.join('\n')
                                                    : 'No public domains configured.'
                                            }
                                            readOnly
                                        />
                                    </Field>

                                    <div className="flex flex-wrap items-center gap-2">
                                        <ApplicationIngressButton application={application} isSaving={isIngressSaving} onToggle={onToggleIngress} />
                                        <span className="text-xs text-muted-foreground">
                                            Use this action to publish or private-route the app through the server ingress.
                                        </span>
                                    </div>
                                </TabsContent>

                                <TabsContent value="logs">
                                    <ApplicationLogsTab key={application.id} application={application} />
                                </TabsContent>

                                <TabsContent value="advanced" className="flex flex-col gap-4">
                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <Field>
                                            <FieldLabel>Application ID</FieldLabel>
                                            <Input value={application.id} readOnly />
                                        </Field>

                                        <Field>
                                            <FieldLabel>Canvas position</FieldLabel>
                                            <Input value={`${application.canvasX}, ${application.canvasY}`} readOnly />
                                        </Field>
                                    </div>

                                    <Field>
                                        <FieldLabel>Raw app config</FieldLabel>
                                        <Textarea
                                            value={JSON.stringify(application, null, 2)}
                                            readOnly
                                            className="min-h-80 font-mono text-xs"
                                        />
                                    </Field>
                                </TabsContent>
                            </Tabs>
                        </div>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}
