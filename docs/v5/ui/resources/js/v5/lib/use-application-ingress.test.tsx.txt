// @vitest-environment jsdom
import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { isValidDomain, useApplicationIngress } from '@/lib/use-application-ingress';
import type { V5Application } from '@/types';

vi.mock('@/lib/canvas-api', () => ({
    canvasRequest: vi.fn(),
}));

import { canvasRequest } from '@/lib/canvas-api';

const canvasRequestMock = vi.mocked(canvasRequest);

function application(overrides: Partial<V5Application> = {}): V5Application {
    return {
        id: 'app-1',
        name: 'nginx-test',
        serverIngressEnabled: true,
        ingressEnabled: false,
        internalPort: null,
        domains: [],
        ...overrides,
    } as V5Application;
}

function jsonResponse(payload: unknown, ok = true): Response {
    return { ok, json: async () => payload } as Response;
}

function renderIngress(notify = vi.fn(), onApplicationUpdated = vi.fn()) {
    const rendered = renderHook(() => useApplicationIngress({ notify, onApplicationUpdated }));

    return { ...rendered, notify, onApplicationUpdated };
}

beforeEach(() => {
    canvasRequestMock.mockReset();
});

describe('isValidDomain', () => {
    it.each(['example.com', 'sub.example.com', 'a.io', 'my-app.example.co.uk'])('accepts %s', (domain) => {
        expect(isValidDomain(domain)).toBe(true);
    });

    it.each(['', '.example.com', 'example.com.', '-bad.example.com', 'bad-.example.com', 'exa mple.com', 'UPPER.example.com'])(
        'rejects %j',
        (domain) => {
            expect(isValidDomain(domain)).toBe(false);
        },
    );
});

describe('toggleApplicationIngress', () => {
    it('refuses to open the modal when server ingress is disabled', () => {
        const { result, notify } = renderIngress();

        act(() => result.current.toggleApplicationIngress(application({ serverIngressEnabled: false })));

        expect(notify).toHaveBeenCalledWith('Enable ingress on the server before enabling app ingress.');
        expect(result.current.ingressModal).toBeNull();
        expect(canvasRequestMock).not.toHaveBeenCalled();
    });

    it('opens the modal prefilled from the application', () => {
        const { result } = renderIngress();

        act(() =>
            result.current.toggleApplicationIngress(application({ domains: ['a.example.com', 'b.example.com'], internalPort: 8080 })),
        );

        expect(result.current.ingressModal).toMatchObject({
            domains: 'a.example.com, b.example.com',
            internalPort: '8080',
            error: null,
        });
        expect(canvasRequestMock).not.toHaveBeenCalled();
    });

    it('disables ingress immediately for an enabled application', async () => {
        canvasRequestMock.mockResolvedValue(jsonResponse({ application: application() }));
        const enabledApplication = application({ ingressEnabled: true, domains: ['app.example.com'], internalPort: 8080 });
        const { result, onApplicationUpdated } = renderIngress();

        await act(() => result.current.toggleApplicationIngress(enabledApplication));

        expect(canvasRequestMock).toHaveBeenCalledWith('/v5/applications/app-1/ingress', {
            method: 'PATCH',
            body: { ingress_enabled: false, internal_port: 8080, domains: ['app.example.com'] },
        });
        expect(onApplicationUpdated).toHaveBeenCalledOnce();
    });
});

describe('submitApplicationIngress', () => {
    function openModal(result: { current: ReturnType<typeof useApplicationIngress> }, app = application()): void {
        act(() => result.current.toggleApplicationIngress(app));
    }

    it('requires at least one domain', async () => {
        const { result } = renderIngress();

        openModal(result);
        await act(() => result.current.submitApplicationIngress());

        expect(result.current.ingressModal?.error).toBe('Add at least one valid domain.');
        expect(canvasRequestMock).not.toHaveBeenCalled();
    });

    it('rejects invalid domains by name', async () => {
        const { result } = renderIngress();

        openModal(result);
        act(() => result.current.setIngressModalDomains('good.example.com, bad_domain'));
        act(() => result.current.setIngressModalInternalPort('8080'));
        await act(() => result.current.submitApplicationIngress());

        expect(result.current.ingressModal?.error).toBe('bad_domain is not a valid domain.');
        expect(canvasRequestMock).not.toHaveBeenCalled();
    });

    it.each(['', '0', '65536', 'abc'])('rejects invalid internal port %j', async (port) => {
        const { result } = renderIngress();

        openModal(result);
        act(() => result.current.setIngressModalDomains('app.example.com'));
        act(() => result.current.setIngressModalInternalPort(port));
        await act(() => result.current.submitApplicationIngress());

        expect(result.current.ingressModal?.error).toBe('Choose a valid internal port between 1 and 65535.');
        expect(canvasRequestMock).not.toHaveBeenCalled();
    });

    it('persists deduplicated lowercase domains and closes the modal', async () => {
        const updatedApplication = application({ ingressEnabled: true });
        canvasRequestMock.mockResolvedValue(jsonResponse({ application: updatedApplication }));
        const { result, onApplicationUpdated } = renderIngress();

        openModal(result);
        act(() => result.current.setIngressModalDomains('App.Example.com, app.example.com, other.example.com,'));
        act(() => result.current.setIngressModalInternalPort('8080'));
        await act(() => result.current.submitApplicationIngress());

        expect(canvasRequestMock).toHaveBeenCalledWith('/v5/applications/app-1/ingress', {
            method: 'PATCH',
            body: { ingress_enabled: true, internal_port: 8080, domains: ['app.example.com', 'other.example.com'] },
        });
        expect(onApplicationUpdated).toHaveBeenCalledWith(updatedApplication);
        expect(result.current.ingressModal).toBeNull();
    });

    it('keeps the modal open and shows the server error on failure', async () => {
        canvasRequestMock.mockResolvedValue(jsonResponse({ message: 'Domain already in use.' }, false));
        const { result, onApplicationUpdated } = renderIngress();

        openModal(result);
        act(() => result.current.setIngressModalDomains('app.example.com'));
        act(() => result.current.setIngressModalInternalPort('8080'));
        await act(() => result.current.submitApplicationIngress());

        expect(result.current.ingressModal?.error).toBe('Domain already in use.');
        expect(onApplicationUpdated).not.toHaveBeenCalled();
    });

    it('reports network failures inside the modal', async () => {
        canvasRequestMock.mockRejectedValue(new Error('Network down.'));
        const { result } = renderIngress();

        openModal(result);
        act(() => result.current.setIngressModalDomains('app.example.com'));
        act(() => result.current.setIngressModalInternalPort('8080'));
        await act(() => result.current.submitApplicationIngress());

        expect(result.current.ingressModal?.error).toBe('Network down.');
    });
});

describe('modal editing', () => {
    it('clears the error when inputs change', async () => {
        const { result } = renderIngress();

        act(() => result.current.toggleApplicationIngress(application()));
        await act(() => result.current.submitApplicationIngress());

        expect(result.current.ingressModal?.error).not.toBeNull();

        act(() => result.current.setIngressModalDomains('app.example.com'));

        expect(result.current.ingressModal?.error).toBeNull();
    });

    it('closes the modal on demand', () => {
        const { result } = renderIngress();

        act(() => result.current.toggleApplicationIngress(application()));
        act(() => result.current.closeIngressModal());

        expect(result.current.ingressModal).toBeNull();
    });
});
