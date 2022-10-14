export function getStatusOfService(service: any) {
    if (service) {
        if (service.status.isRunning === 'running') {
            return 'running';
        }
        if (service.status.isExited === 'exited') {
            return 'stopped';
        }
        if (service.status.isRestarting === 'degraded') {
            return 'degraded';
        }
    }
   
    return 'stopped';
}