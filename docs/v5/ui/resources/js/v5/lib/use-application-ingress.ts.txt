import { useCallback, useRef, useState } from 'react';

import { canvasRequest } from '@/lib/canvas-api';
import { usePendingIds } from '@/lib/use-pending-ids';
import type { CanvasNotify } from '@/lib/use-canvas-connections';
import type { V5Application } from '@/types';

export type IngressModalState = {
    application: V5Application;
    domains: string;
    internalPort: string;
    error: string | null;
};

export function isValidDomain(domain: string): boolean {
    if (domain.length < 1 || domain.length > 253 || domain.startsWith('.') || domain.endsWith('.')) {
        return false;
    }

    return domain.split('.').every((label) => /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/.test(label));
}

/**
 * Owns the application ingress modal and the enable/disable persistence flow,
 * tracking per-application saving state through usePendingIds.
 */
export function useApplicationIngress(options: {
    notify: CanvasNotify;
    onApplicationUpdated: (application: V5Application) => void;
}) {
    const { notify, onApplicationUpdated } = options;
    const [ingressModal, setIngressModal] = useState<IngressModalState | null>(null);
    const savingIngressApplications = usePendingIds<string>();
    const ingressModalRef = useRef<IngressModalState | null>(null);

    ingressModalRef.current = ingressModal;

    const saveApplicationIngress = useCallback(
        async (application: V5Application, enabled: boolean, domains: string[], internalPort: number | null): Promise<void> => {
            notify(null);
            savingIngressApplications.start(application.id);

            const reportError = (message: string): void => {
                if (ingressModalRef.current) {
                    setIngressModal((currentModal) => (currentModal ? { ...currentModal, error: message } : currentModal));
                } else {
                    notify(message);
                }
            };

            try {
                const response = await canvasRequest(`/v5/applications/${application.id}/ingress`, {
                    method: 'PATCH',
                    body: {
                        ingress_enabled: enabled,
                        internal_port: internalPort,
                        domains,
                    },
                });

                if (!response.ok) {
                    const payload = (await response.json().catch(() => null)) as { message?: string } | null;

                    reportError(payload?.message ?? 'Could not update application ingress.');

                    return;
                }

                const payload = (await response.json()) as { application: V5Application };

                onApplicationUpdated(payload.application);
                setIngressModal(null);
            } catch (error) {
                reportError(error instanceof Error ? error.message : 'Could not update application ingress.');
            } finally {
                savingIngressApplications.finish(application.id);
            }
        },
        [notify, onApplicationUpdated, savingIngressApplications.start, savingIngressApplications.finish],
    );

    const openApplicationIngressModal = useCallback(
        (application: V5Application): void => {
            notify(null);

            if (!application.serverIngressEnabled) {
                notify('Enable ingress on the server before enabling app ingress.');

                return;
            }

            setIngressModal({
                application,
                domains: application.domains.join(', '),
                internalPort: application.internalPort ? String(application.internalPort) : '',
                error: null,
            });
        },
        [notify],
    );

    const toggleApplicationIngress = useCallback(
        (application: V5Application): void => {
            if (application.ingressEnabled) {
                void saveApplicationIngress(application, false, application.domains, application.internalPort);
            } else {
                openApplicationIngressModal(application);
            }
        },
        [saveApplicationIngress, openApplicationIngressModal],
    );

    const submitApplicationIngress = useCallback(async (): Promise<void> => {
        const currentModal = ingressModalRef.current;

        if (!currentModal) {
            return;
        }

        const domains = currentModal.domains
            .split(',')
            .map((domain) => domain.trim().toLowerCase())
            .filter(Boolean);
        const internalPort = Number(currentModal.internalPort);
        const invalidDomain = domains.find((domain) => !isValidDomain(domain));

        if (domains.length === 0) {
            setIngressModal({ ...currentModal, error: 'Add at least one valid domain.' });

            return;
        }

        if (invalidDomain) {
            setIngressModal({ ...currentModal, error: `${invalidDomain} is not a valid domain.` });

            return;
        }

        if (!Number.isInteger(internalPort) || internalPort < 1 || internalPort > 65535) {
            setIngressModal({ ...currentModal, error: 'Choose a valid internal port between 1 and 65535.' });

            return;
        }

        await saveApplicationIngress(currentModal.application, true, [...new Set(domains)], internalPort);
    }, [saveApplicationIngress]);

    const closeIngressModal = useCallback((): void => {
        setIngressModal(null);
    }, []);

    const setIngressModalDomains = useCallback((domains: string): void => {
        setIngressModal((currentModal) => (currentModal ? { ...currentModal, domains, error: null } : currentModal));
    }, []);

    const setIngressModalInternalPort = useCallback((internalPort: string): void => {
        setIngressModal((currentModal) => (currentModal ? { ...currentModal, internalPort, error: null } : currentModal));
    }, []);

    return {
        ingressModal,
        closeIngressModal,
        setIngressModalDomains,
        setIngressModalInternalPort,
        submitApplicationIngress,
        toggleApplicationIngress,
        savingIngressApplications,
    };
}
