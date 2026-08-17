import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import type { V5Application } from '@/types';

type ApplicationIngressButtonProps = {
    application: V5Application;
    isSaving: boolean;
    onToggle: (application: V5Application) => void;
};

export function ApplicationIngressButton({ application, isSaving, onToggle }: ApplicationIngressButtonProps) {
    const isDisabled = !application.ingressEnabled && !application.serverIngressEnabled;
    const button = (
        <button
            type="button"
            onPointerDown={(event) => event.stopPropagation()}
            disabled={isDisabled || isSaving}
            onClick={(event) => {
                event.stopPropagation();
                onToggle(application);
            }}
            className="rounded-sm border border-border px-2 py-1 text-[0.625rem] font-semibold uppercase tracking-wide text-foreground transition hover:bg-muted disabled:cursor-not-allowed disabled:opacity-50"
        >
            {isSaving ? 'Saving...' : application.ingressEnabled ? 'Disable' : 'Enable'}
        </button>
    );

    if (!isDisabled) {
        return button;
    }

    return (
        <Tooltip>
            <TooltipTrigger render={<span className="inline-flex" />}>{button}</TooltipTrigger>
            <TooltipContent side="top">
                <p>You need to enable ingress in server settings first.</p>
            </TooltipContent>
        </Tooltip>
    );
}
