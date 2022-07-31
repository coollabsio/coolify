import { parentPort } from 'node:worker_threads';
import { asyncExecShell, cleanupDockerStorage, executeDockerCmd, isDev, prisma, version } from '../lib/common';

(async () => {
    if (parentPort) {
        const destinationDockers = await prisma.destinationDocker.findMany();
        let enginesDone = new Set()
        for (const destination of destinationDockers) {
            if (enginesDone.has(destination.engine) || enginesDone.has(destination.remoteIpAddress)) return
            if (destination.engine) enginesDone.add(destination.engine)
            if (destination.remoteIpAddress) enginesDone.add(destination.remoteIpAddress)
            
            let lowDiskSpace = false;
            try {
                let stdout = null
                if (!isDev) {
                    const output = await executeDockerCmd({ dockerId: destination.id, command: `CONTAINER=$(docker ps -lq | head -1) && docker exec $CONTAINER sh -c 'df -kPT /'` })
                    stdout = output.stdout;
                } else {
                    const output = await asyncExecShell(
                        `df -kPT /`
                    );
                    stdout = output.stdout;
                }
                let lines = stdout.trim().split('\n');
                let header = lines[0];
                let regex =
                    /^Filesystem\s+|Type\s+|1024-blocks|\s+Used|\s+Available|\s+Capacity|\s+Mounted on\s*$/g;
                const boundaries = [];
                let match;

                while ((match = regex.exec(header))) {
                    boundaries.push(match[0].length);
                }

                boundaries[boundaries.length - 1] = -1;
                const data = lines.slice(1).map((line) => {
                    const cl = boundaries.map((boundary) => {
                        const column = boundary > 0 ? line.slice(0, boundary) : line;
                        line = line.slice(boundary);
                        return column.trim();
                    });
                    return {
                        capacity: Number.parseInt(cl[5], 10) / 100
                    };
                });
                if (data.length > 0) {
                    const { capacity } = data[0];
                    if (capacity > 0.8) {
                        lowDiskSpace = true;
                    }
                }
            } catch (error) {
                console.log(error);
            }
            await cleanupDockerStorage(destination.id, lowDiskSpace, false)
        }
        await prisma.$disconnect();
    } else process.exit(0);
})();
