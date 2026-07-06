import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

import { AppNavbar } from '@/components/app-navbar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { csrfToken } from '@/lib/csrf';
import { useTeamChannel } from '@/lib/use-team-channel';
import type { V5DashboardProps } from '@/types';

type RealtimeTestProps = V5DashboardProps & {
    currentTeam: {
        id: number;
    } | null;
};

type RealtimeTestEvent = {
    message: string;
    teamId: number;
    sentAt: string;
};

function formatLogPayload(payload: unknown): string {
    if (typeof payload === 'string') {
        return payload;
    }

    return JSON.stringify(payload, null, 2);
}

export default function RealtimeTest({ currentTeam, flux, projects = [], selectedProjectUuid = null, selectedEnvironmentUuid = null }: RealtimeTestProps) {
    const [message, setMessage] = useState('Hello from v5 realtime test');
    const [isBroadcasting, setIsBroadcasting] = useState(false);
    const [logs, setLogs] = useState<string[]>([]);

    const addLog = useCallback((label: string, payload?: unknown): void => {
        const timestamp = new Date().toLocaleTimeString();
        setLogs((currentLogs) => [
            `[${timestamp}] ${label}${payload === undefined ? '' : `\n${formatLogPayload(payload)}`}`,
            ...currentLogs,
        ]);
    }, []);

    useEffect(() => {
        if (!currentTeam) {
            addLog('No current team was provided to the page.');
        }
    }, [currentTeam, addLog]);

    useTeamChannel(
        currentTeam?.id ?? null,
        '.v5.realtime.test',
        (payload) => {
            const event = payload as RealtimeTestEvent;

            addLog('Received .v5.realtime.test', event);
        },
        { onDebug: addLog, onError: addLog },
    );

    async function broadcastTestEvent(): Promise<void> {
        setIsBroadcasting(true);
        addLog('Sending POST /v5/realtime-test');

        const response = await fetch('/v5/realtime-test', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ message }),
        });

        const payload = await response.json().catch(() => null);
        addLog(`POST /v5/realtime-test responded ${response.status}`, payload);
        setIsBroadcasting(false);
    }

    return (
        <>
            <Head title="Realtime test" />
            <div className="min-h-dvh bg-background text-foreground">
                <AppNavbar
                    flux={flux}
                    clusters={[]}
                    projects={projects}
                    selectedProjectUuid={selectedProjectUuid}
                    selectedEnvironmentUuid={selectedEnvironmentUuid}
                />
                <main className="mx-auto flex max-w-5xl flex-col gap-6 px-4 pt-20 pb-8 lg:px-6">
                    <section className="rounded-lg border border-border bg-card p-5">
                        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">RealtimeTest</p>
                        <h1 className="mt-1 text-2xl font-semibold text-foreground">v5 realtime websocket test</h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Opens a private team channel subscription and broadcasts a manual backend event named{' '}
                            <code className="rounded bg-muted px-1 py-0.5">v5.realtime.test</code>.
                        </p>
                    </section>

                    <section className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                        <div className="rounded-lg border border-border bg-card p-4">
                            <h2 className="text-sm font-semibold text-foreground">Runtime state</h2>
                            <dl className="mt-3 space-y-2 text-sm">
                                <div className="flex justify-between gap-3">
                                    <dt className="text-muted-foreground">Team ID</dt>
                                    <dd className="font-medium text-foreground">{currentTeam?.id ?? 'Missing'}</dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt className="text-muted-foreground">Echo</dt>
                                    <dd className="font-medium text-foreground">{typeof window !== 'undefined' && window.Echo ? 'Available' : 'Missing'}</dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt className="text-muted-foreground">Channel</dt>
                                    <dd className="font-medium text-foreground">{currentTeam ? `private-team.${currentTeam.id}` : 'Missing'}</dd>
                                </div>
                            </dl>
                        </div>

                        <div className="rounded-lg border border-border bg-card p-4 lg:col-span-2">
                            <h2 className="text-sm font-semibold text-foreground">Send test event</h2>
                            <div className="mt-3 flex flex-col gap-3 sm:flex-row">
                                <Input value={message} onChange={(event) => setMessage(event.target.value)} />
                                <Button type="button" variant="coolify" disabled={isBroadcasting} onClick={() => void broadcastTestEvent()}>
                                    {isBroadcasting ? 'Broadcasting...' : 'Broadcast event'}
                                </Button>
                            </div>
                        </div>
                    </section>

                    <section className="rounded-lg border border-border bg-card p-4">
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="text-sm font-semibold text-foreground">Event log</h2>
                            <Button type="button" variant="outline" size="sm" onClick={() => setLogs([])}>
                                Clear
                            </Button>
                        </div>
                        <pre className="mt-3 min-h-64 overflow-auto whitespace-pre-wrap rounded-md bg-background p-4 text-xs text-muted-foreground">
                            {logs.length === 0 ? 'No logs yet.' : logs.join('\n\n')}
                        </pre>
                    </section>
                </main>
            </div>
        </>
    );
}
