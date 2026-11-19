export const MAX_TERMINAL_SESSION_SECONDS = 8 * 60 * 60;
export const TERMINAL_SESSION_WARNING_SECONDS = 30 * 60;
export const TERMINAL_SESSION_DANGER_SECONDS = 5 * 60;

export function formatTerminalSessionRemainingTime(seconds) {
    const remainingSeconds = Math.max(0, Math.ceil(seconds));

    if (remainingSeconds === 0) {
        return 'expired';
    }

    const totalMinutes = Math.floor(remainingSeconds / 60);
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;
    const secondsPart = remainingSeconds % 60;

    if (hours === 0) {
        return `${minutes}m ${String(secondsPart).padStart(2, '0')}s`;
    }

    return `${hours}h ${String(minutes).padStart(2, '0')}m ${String(secondsPart).padStart(2, '0')}s`;
}
