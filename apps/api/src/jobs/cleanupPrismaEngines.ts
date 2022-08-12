import { parentPort } from 'node:worker_threads';
import { asyncExecShell, isDev, prisma } from '../lib/common';

(async () => {
    if (parentPort) {
        if (!isDev) {
            try {
                const { stdout } = await asyncExecShell(`ps -ef | grep /app/prisma-engines/query-engine | grep -v grep | wc -l | xargs`)
                if (stdout.trim() != null && stdout.trim() != '' && Number(stdout.trim()) > 1) {
                    await asyncExecShell(`killall -q -e /app/prisma-engines/query-engine -o 10m`)
                }
            } catch (error) {
                console.log(error);
            } finally {
                await prisma.$disconnect();
            }
        }
    } else process.exit(0);
})();
