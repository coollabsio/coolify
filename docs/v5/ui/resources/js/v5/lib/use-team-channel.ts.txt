import { useEffect, useRef } from 'react';

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

export type TeamChannelLogger = (message: string, payload?: unknown) => void;

export type UseTeamChannelOptions = {
    /** Receives subscription lifecycle messages; defaults to console.debug. */
    onDebug?: TeamChannelLogger;
    /** Receives subscription failures; defaults to console.error. */
    onError?: TeamChannelLogger;
};

/**
 * Subscribes to the private `team.{teamId}` channel and forwards `eventName`
 * payloads to the latest onEvent callback without resubscribing when the
 * callback identity changes. Polls for window.Echo (500ms, up to 20 attempts)
 * because the Echo script can finish loading after React mounts.
 */
export function useTeamChannel(
    teamId: number | null,
    eventName: string,
    onEvent: (payload: unknown) => void,
    options: UseTeamChannelOptions = {},
): void {
    const onEventRef = useRef(onEvent);
    const onDebugRef = useRef(options.onDebug);
    const onErrorRef = useRef(options.onError);

    onEventRef.current = onEvent;
    onDebugRef.current = options.onDebug;
    onErrorRef.current = options.onError;

    useEffect(() => {
        if (teamId === null) {
            return;
        }

        const debug: TeamChannelLogger = (message, payload) => {
            if (onDebugRef.current) {
                onDebugRef.current(message, payload);

                return;
            }

            console.debug(message);
        };
        const fail: TeamChannelLogger = (message, payload) => {
            if (onErrorRef.current) {
                onErrorRef.current(message, payload);

                return;
            }

            console.error(message, payload);
        };

        let isCancelled = false;
        let attempts = 0;
        const channelName = `team.${teamId}`;

        const interval = window.setInterval(() => {
            attempts += 1;

            if (!window.Echo) {
                if (attempts === 1) {
                    debug(`Waiting for window.Echo before subscribing to ${eventName} updates`);
                }

                if (attempts >= 20) {
                    window.clearInterval(interval);
                    fail('window.Echo was not available after 10 seconds.');
                }

                return;
            }

            window.clearInterval(interval);

            if (isCancelled) {
                return;
            }

            debug(`Subscribing to private-${channelName} for ${eventName} updates`);
            const channel = window.Echo.private(channelName);

            channel.subscribed?.(() => debug(`Subscribed to private-${channelName} for ${eventName} updates`));
            channel.error?.((error) => fail(`Subscription error on private-${channelName}`, error));
            channel.listen(eventName, (payload) => {
                onEventRef.current(payload);
            });
        }, 500);

        return () => {
            isCancelled = true;
            window.clearInterval(interval);
            window.Echo?.leave?.(channelName) ?? window.Echo?.leaveChannel?.(`private-${channelName}`);
        };
    }, [teamId, eventName]);
}
