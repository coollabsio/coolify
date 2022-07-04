import { parentPort } from 'node:worker_threads';
import { asyncExecShell, isDev, prisma, version } from '../lib/common';
import { getEngine } from '../lib/docker';

(async () => {
    if (parentPort) {
        const destinationDockers = await prisma.destinationDocker.findMany();
        const engines = [...new Set(destinationDockers.map(({ engine }) => engine))];
        for (const engine of engines) {
            let lowDiskSpace = false;
            const host = getEngine(engine);
            // try {
            //     let stdout = null
            //     if (!isDev) {
            //         const output = await asyncExecShell(
            //             `DOCKER_HOST=${host} docker exec coolify sh -c 'df -kPT /'`
            //         );
            //         stdout = output.stdout;
            //     } else {
            //         const output = await asyncExecShell(
            //             `df -kPT /`
            //         );
            //         stdout = output.stdout;
            //     }
            //     let lines = stdout.trim().split('\n');
            //     let header = lines[0];
            //     let regex =
            //         /^Filesystem\s+|Type\s+|1024-blocks|\s+Used|\s+Available|\s+Capacity|\s+Mounted on\s*$/g;
            //     const boundaries = [];
            //     let match;

            //     while ((match = regex.exec(header))) {
            //         boundaries.push(match[0].length);
            //     }

            //     boundaries[boundaries.length - 1] = -1;
            //     const data = lines.slice(1).map((line) => {
            //         const cl = boundaries.map((boundary) => {
            //             const column = boundary > 0 ? line.slice(0, boundary) : line;
            //             line = line.slice(boundary);
            //             return column.trim();
            //         });
            //         return {
            //             capacity: Number.parseInt(cl[5], 10) / 100
            //         };
            //     });
            //     if (data.length > 0) {
            //         const { capacity } = data[0];
            //         if (capacity > 0.6) {
            //             lowDiskSpace = true;
            //         }
            //     }
            // } catch (error) {
            //     console.log(error);
            // }
            if (!isDev) {
                // Cleanup old coolify images
                try {
                    let { stdout: images } = await asyncExecShell(
                        `DOCKER_HOST=${host} docker images coollabsio/coolify --filter before="coollabsio/coolify:${version}" -q | xargs `
                    );
                    images = images.trim();
                    if (images) {
                        await asyncExecShell(`DOCKER_HOST=${host} docker rmi -f ${images}`);
                    }
                } catch (error) {
                    //console.log(error);
                }
                try {
                    await asyncExecShell(`DOCKER_HOST=${host} docker container prune -f`);
                } catch (error) {
                    //console.log(error);
                }
                try {
                    await asyncExecShell(`DOCKER_HOST=${host} docker image prune -f`);
                } catch (error) {
                    //console.log(error);
                }
                try {
                    await asyncExecShell(`DOCKER_HOST=${host} docker image prune -a -f`);
                } catch (error) {
                    //console.log(error);
                }
            } else {
                console.log(`[DEV MODE] Low disk space: ${lowDiskSpace}`);
            }
        }
    } else process.exit(0);
})();
