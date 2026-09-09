import { useTeamChannel } from '@/lib/use-team-channel';
import type { V5Application, V5CaddyIngress } from '@/types';

export type V5CanvasResourceUpdatedEvent = {
    application: V5Application | null;
    applications?: V5Application[];
    caddyIngress: V5CaddyIngress | null;
};

/**
 * Subscribes the canvas to the private team channel and forwards
 * `.v5.canvas.resource.updated` payloads to the latest onEvent callback
 * without resubscribing when the callback identity changes.
 */
export function useCanvasResourceChannel(teamId: number | null, onEvent: (event: V5CanvasResourceUpdatedEvent) => void): void {
    useTeamChannel(teamId, '.v5.canvas.resource.updated', (payload) => {
        onEvent(payload as V5CanvasResourceUpdatedEvent);
    });
}
