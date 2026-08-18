import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import type { IngressModalState } from '@/lib/use-application-ingress';

type IngressDialogProps = {
    modal: IngressModalState;
    isSaving: boolean;
    onDomainsChange: (domains: string) => void;
    onInternalPortChange: (internalPort: string) => void;
    onSubmit: () => void;
    onClose: () => void;
};

export function IngressDialog({ modal, isSaving, onDomainsChange, onInternalPortChange, onSubmit, onClose }: IngressDialogProps) {
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !isSaving) {
                    onClose();
                }
            }}
        >
            <DialogContent className="max-w-lg" showCloseButton>
                <DialogHeader>
                    <DialogTitle>Enable app ingress</DialogTitle>
                    <DialogDescription>
                        Route public domains to {modal.application.name} through the server ingress.
                    </DialogDescription>
                </DialogHeader>

                <form
                    className="mt-6 flex flex-col gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        onSubmit();
                    }}
                >
                    <Field>
                        <FieldLabel>Domains</FieldLabel>
                        <Input
                            type="text"
                            value={modal.domains}
                            onChange={(event) => onDomainsChange(event.target.value)}
                            placeholder="example.com, www.example.com"
                        />
                        <span className="text-xs text-muted-foreground">
                            Use hostnames only, separated by commas. No scheme, path, wildcard, or port.
                        </span>
                    </Field>

                    <Field>
                        <FieldLabel>Internal port</FieldLabel>
                        <Input
                            type="number"
                            min="1"
                            max="65535"
                            value={modal.internalPort}
                            onChange={(event) => onInternalPortChange(event.target.value)}
                            placeholder="3000"
                        />
                    </Field>

                    {modal.error && (
                        <div className="rounded-lg border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive">
                            <p className="font-medium">Ingress update failed</p>
                            <p className="mt-1 text-destructive/90">{modal.error}</p>
                        </div>
                    )}

                    <div className="flex justify-end">
                        <Button type="submit" variant="coolify" disabled={isSaving}>
                            {isSaving ? 'Saving...' : 'Enable ingress'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
