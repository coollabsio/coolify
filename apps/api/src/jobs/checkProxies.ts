import { parentPort } from 'node:worker_threads';
import { prisma, startTraefikTCPProxy, generateDatabaseConfiguration, startTraefikProxy, executeDockerCmd } from '../lib/common';
import { checkContainer } from '../lib/docker';

(async () => {
    if (parentPort) {
        // Coolify Proxy local
        const engine = '/var/run/docker.sock';
        const localDocker = await prisma.destinationDocker.findFirst({
            where: { engine, network: 'coolify' }
        });
        if (localDocker && localDocker.isCoolifyProxyUsed) {
            // Remove HAProxy
            const found = await checkContainer({ dockerId: localDocker.id, container: 'coolify-haproxy' });
            if (found) {
                await executeDockerCmd({
                    dockerId: localDocker.id,
                    command: `docker stop -t 0 coolify-haproxy && docker rm coolify-haproxy`
                })
            }
            await startTraefikProxy(localDocker.id);

        }

        // TCP Proxies
        const databasesWithPublicPort = await prisma.database.findMany({
            where: { publicPort: { not: null } },
            include: { settings: true, destinationDocker: true }
        });
        for (const database of databasesWithPublicPort) {
            const { destinationDockerId, destinationDocker, publicPort, id } = database;
            if (destinationDockerId && destinationDocker.isCoolifyProxyUsed) {
                const { privatePort } = generateDatabaseConfiguration(database);
                // Remove HAProxy
                const found = await checkContainer({
                    dockerId: localDocker.id, container: `haproxy-for-${publicPort}`
                });
                if (found) {
                    await executeDockerCmd({
                        dockerId: localDocker.id,
                        command: `docker stop -t 0 haproxy-for-${publicPort} && docker rm haproxy-for-${publicPort}`
                    })
                }
                await startTraefikTCPProxy(destinationDocker, id, publicPort, privatePort);

            }
        }
        const wordpressWithFtp = await prisma.wordpress.findMany({
            where: { ftpPublicPort: { not: null } },
            include: { service: { include: { destinationDocker: true } } }
        });
        for (const ftp of wordpressWithFtp) {
            const { service, ftpPublicPort } = ftp;
            const { destinationDockerId, destinationDocker, id } = service;
            if (destinationDockerId && destinationDocker.isCoolifyProxyUsed) {
                // Remove HAProxy
                const found = await checkContainer({ dockerId: localDocker.id, container: `haproxy-for-${ftpPublicPort}` });
                if (found) {
                    await executeDockerCmd({
                        dockerId: localDocker.id,
                        command: `docker stop -t 0 haproxy -for-${ftpPublicPort} && docker rm haproxy-for-${ftpPublicPort}`
                    })
                }
                await startTraefikTCPProxy(destinationDocker, id, ftpPublicPort, 22, 'wordpressftp');
            }
        }

        // HTTP Proxies
        const minioInstances = await prisma.minio.findMany({
            where: { publicPort: { not: null } },
            include: { service: { include: { destinationDocker: true } } }
        });
        for (const minio of minioInstances) {
            const { service, publicPort } = minio;
            const { destinationDockerId, destinationDocker, id } = service;
            if (destinationDockerId && destinationDocker.isCoolifyProxyUsed) {
                // Remove HAProxy
                const found = await checkContainer({ dockerId: localDocker.id, container: `${id}-${publicPort}` });
                if (found) {
                    await executeDockerCmd({
                        dockerId: localDocker.id,
                        command: `docker stop -t 0 ${id}-${publicPort} && docker rm ${id}-${publicPort} `
                    })
                }
                await startTraefikTCPProxy(destinationDocker, id, publicPort, 9000);
            }
        }
        await prisma.$disconnect();
    } else process.exit(0);
})();
