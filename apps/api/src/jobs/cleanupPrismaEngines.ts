import { parentPort } from 'node:worker_threads';
import { asyncExecShell, isDev, prisma } from '../lib/common';

(async () => {
    if (parentPort) {
        if (!isDev) {
            try {
                await asyncExecShell(`killall -q -e /app/prisma-engines/query-engine -o 10m`)
            } catch (error) {
                console.log(error);
            } finally {
                await prisma.$disconnect();
            }
        }
    } else process.exit(0);
})();
