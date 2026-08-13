type CanvasNoticeProps = {
    message: string;
    description?: string;
    onDismiss: () => void;
    variant?: 'danger' | 'success' | 'info';
};

const noticeClasses = {
    danger: 'border-destructive/40 text-destructive',
    success: 'border-emerald-500/40 text-emerald-400',
    info: 'border-blue-500/40 text-blue-400',
};

export function CanvasNotice({ message, description, onDismiss, variant = 'danger' }: CanvasNoticeProps) {
    return (
        <div
            className={`fixed right-4 top-20 z-50 flex max-w-sm items-start gap-3 rounded-lg border bg-card p-3 text-sm shadow-lg ${noticeClasses[variant]}`}
        >
            <div className="min-w-0">
                <p className="font-medium">{message}</p>
                {description ? (
                    <p className="mt-1 max-h-32 overflow-auto whitespace-pre-wrap break-words text-xs text-muted-foreground">
                        {description}
                    </p>
                ) : null}
            </div>
            <button
                type="button"
                aria-label="Dismiss notice"
                onClick={onDismiss}
                className="-m-1 rounded p-1 opacity-80 transition hover:bg-background/20 hover:opacity-100"
            >
                <span aria-hidden="true">×</span>
                <span className="sr-only">Dismiss notice</span>
            </button>
        </div>
    );
}
