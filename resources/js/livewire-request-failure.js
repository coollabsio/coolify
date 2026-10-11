export const INFRASTRUCTURE_FAILURE_STATUSES = new Set([502, 503, 504, 520, 521, 522, 523, 524, 525, 526, 527, 530]);

const USER_GESTURE_EVENTS = ['click', 'submit', 'keydown', 'input', 'change'];

// A request sent within this window of a trusted user gesture is treated as
// user-initiated. It must cover Alpine $nextTick deferrals and wire:model
// debounces, while staying short enough to exclude most wire:poll requests.
export const GESTURE_WINDOW_MS = 2_000;

const WARN_COOLDOWN_MS = 10_000;
const WARN_CONTENT_MAX_LENGTH = 2_000;

export function createLivewireRequestFailureHandler({ now = Date.now } = {}) {
    let lastWarnAt = Number.NEGATIVE_INFINITY;
    let lastToastGestureAt = Number.NEGATIVE_INFINITY;

    return ({ status, content, preventDefault, gestureAt = Number.NEGATIVE_INFINITY }) => {
        if (!INFRASTRUCTURE_FAILURE_STATUSES.has(status)) {
            return;
        }

        preventDefault();

        const currentTime = now();
        if (currentTime - lastWarnAt >= WARN_COOLDOWN_MS) {
            lastWarnAt = currentTime;
            console.warn('Livewire request failed', {
                status,
                content: typeof content === 'string' ? content.slice(0, WARN_CONTENT_MAX_LENGTH) : content,
            });
        }

        // One toast per user gesture: a single click that fails several
        // component requests toasts once, while a retry (a new gesture)
        // always toasts again. Background requests carry no gesture.
        if (gestureAt > lastToastGestureAt) {
            lastToastGestureAt = gestureAt;
            window.toast?.('Action could not be completed', {
                type: 'danger',
                description: 'Coolify did not receive a response. Please try again.',
            });
        }
    };
}

export function registerLivewireRequestFailureHandler(Livewire, documentObject = document, { now = Date.now } = {}) {
    let lastGestureAt = Number.NEGATIVE_INFINITY;

    const markUserGesture = (event) => {
        if (event.isTrusted) {
            lastGestureAt = now();
        }
    };

    USER_GESTURE_EVENTS.forEach((eventName) => {
        documentObject.addEventListener(eventName, markUserGesture, true);
    });

    const handleFailure = createLivewireRequestFailureHandler({ now });

    Livewire.hook('request', ({ fail }) => {
        // Classify at send time: infrastructure failures (522/524) can arrive
        // long after the gesture, so the failure timestamp is meaningless.
        const gestureAt = now() - lastGestureAt <= GESTURE_WINDOW_MS ? lastGestureAt : Number.NEGATIVE_INFINITY;

        fail((failure) => handleFailure({ ...failure, gestureAt }));
    });
}
