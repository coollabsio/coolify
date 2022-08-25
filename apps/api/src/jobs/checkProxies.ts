import { parentPort } from 'node:worker_threads';
import { prisma, startTraefikTCPProxy, generateDatabaseConfiguration, startTraefikProxy, executeDockerCmd, listSettings } from '../lib/common';
import { checkContainer } from '../lib/docker';

(async () => {
    if (parentPort) {
        try {
            const { default: isReachable } = await import('is-port-reachable');
            let portReachable;

            const { arch, ipv4, ipv6 } = await listSettings();
            // Coolify Proxy local
            const engine = '/var/run/docker.sock';
            const localDocker = await prisma.destinationDocker.findFirst({
                where: { engine, network: 'coolify' }
            });
            if (localDocker && localDocker.isCoolifyProxyUsed) {      
                portReachable = await isReachable(80, { host: ipv4 || ipv6 })
                console.log({ port: 80, portReachable });
                if (!portReachable) {
                    await startTraefikProxy(localDocker.id);
                }
            }

            // TCP Proxies
            const databasesWithPublicPort = await prisma.database.findMany({
                where: { publicPort: { not: null } },
                include: { settings: true, destinationDocker: true }
            });
            for (const database of databasesWithPublicPort) {
                const { destinationDockerId, destinationDocker, publicPort, id } = database;
                if (destinationDockerId && destinationDocker.isCoolifyProxyUsed) {
                    const { privatePort } = generateDatabaseConfiguration(database, arch);
                    portReachable = await isReachable(publicPort, { host: destinationDocker.remoteIpAddress || ipv4 || ipv6 })
                    console.log({ publicPort, portReachable });
                    if (!portReachable) {
                        await startTraefikTCPProxy(destinationDocker, id, publicPort, privatePort);
                    }
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
                    portReachable = await isReachable(ftpPublicPort, { host: destinationDocker.remoteIpAddress || ipv4 || ipv6 })
                    console.log({ ftpPublicPort, portReachable });
                    if (!portReachable) {
                        await startTraefikTCPProxy(destinationDocker, id, ftpPublicPort, 22, 'wordpressftp');
                    }
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
                    portReachable = await isReachable(publicPort, { host: destinationDocker.remoteIpAddress || ipv4 || ipv6 })
                    console.log({ publicPort, portReachable });
                    if (!portReachable) {
                        await startTraefikTCPProxy(destinationDocker, id, publicPort, 9000);
                    }
                }
            }

        } catch (error) {

        } finally {
        }

    } else process.exit(0);
})();
