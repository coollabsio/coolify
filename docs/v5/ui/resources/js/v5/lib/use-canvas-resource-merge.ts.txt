import { useCallback, type Dispatch, type RefObject, type SetStateAction } from 'react';

import { useCanvasResourceChannel, type V5CanvasResourceUpdatedEvent } from '@/lib/use-canvas-channel';
import type { V5Application, V5CaddyIngress } from '@/types';

type UseCanvasResourceMergeOptions = {
    teamId: number | null;
    selectedProjectUuid: string | null;
    selectedEnvironmentUuid: string | null;
    setApplications: Dispatch<SetStateAction<V5Application[]>>;
    setIngresses: Dispatch<SetStateAction<V5CaddyIngress[]>>;
    /** Cards mid-drag or with an unsaved position keep their local canvasX/canvasY. */
    locallyPositionedApplicationIds: RefObject<Set<string>>;
    locallyPositionedIngressIds: RefObject<Set<string>>;
};

/**
 * Merges `.v5.canvas.resource.updated` broadcasts into the canvas state.
 *
 * The team channel broadcasts every canvas resource of the team, so incoming
 * applications are only appended when they belong to the selected
 * project+environment; existing entries are updated in place regardless.
 */
export function useCanvasResourceMerge({
    teamId,
    selectedProjectUuid,
    selectedEnvironmentUuid,
    setApplications,
    setIngresses,
    locallyPositionedApplicationIds,
    locallyPositionedIngressIds,
}: UseCanvasResourceMergeOptions): void {
    const belongsToCurrentCanvas = useCallback(
        (application: V5Application): boolean =>
            selectedProjectUuid !== null &&
            selectedEnvironmentUuid !== null &&
            application.projectUuid === selectedProjectUuid &&
            application.environmentUuid === selectedEnvironmentUuid,
        [selectedProjectUuid, selectedEnvironmentUuid],
    );

    const mergeIncomingApplications = useCallback(
        (incomingApplications: V5Application[]): void => {
            setApplications((currentApplications) => {
                const updatedApplications = currentApplications.map((application) => {
                    const incoming = incomingApplications.find((candidate) => candidate.id === application.id);

                    if (!incoming) {
                        return application;
                    }

                    return locallyPositionedApplicationIds.current.has(incoming.id)
                        ? { ...incoming, canvasX: application.canvasX, canvasY: application.canvasY }
                        : incoming;
                });
                const appendedApplications = incomingApplications.filter(
                    (candidate) =>
                        !currentApplications.some((application) => application.id === candidate.id) &&
                        belongsToCurrentCanvas(candidate),
                );

                return [...updatedApplications, ...appendedApplications];
            });
        },
        [belongsToCurrentCanvas, setApplications, locallyPositionedApplicationIds],
    );

    const handleCanvasResourceEvent = useCallback(
        (event: V5CanvasResourceUpdatedEvent): void => {
            if (event.application) {
                mergeIncomingApplications([event.application]);
            }

            if (event.applications && event.applications.length > 0) {
                mergeIncomingApplications(event.applications);
            }

            const incomingIngress = event.caddyIngress;

            if (incomingIngress) {
                setIngresses((currentIngresses) =>
                    currentIngresses.map((ingress) => {
                        if (ingress.id !== incomingIngress.id) {
                            return ingress;
                        }

                        return locallyPositionedIngressIds.current.has(incomingIngress.id)
                            ? { ...incomingIngress, canvasX: ingress.canvasX, canvasY: ingress.canvasY }
                            : incomingIngress;
                    }),
                );
            }
        },
        [mergeIncomingApplications, setIngresses, locallyPositionedIngressIds],
    );

    useCanvasResourceChannel(teamId, handleCanvasResourceEvent);
}
