import { parentPort } from 'node:worker_threads';
import axios from 'axios';
import { compareVersions } from 'compare-versions';
import { asyncExecShell, cleanupDockerStorage, executeDockerCmd, isDev, prisma, startTraefikTCPProxy, generateDatabaseConfiguration, startTraefikProxy, listSettings, version, createRemoteEngineConfiguration, decrypt, executeSSHCmd } from '../lib/common';
import { checkContainer } from '../lib/docker';
import fs from 'fs/promises'
async function autoUpdater() {
    try {
        const currentVersion = version;
        const { data: versions } = await axios
            .get(
                `https://get.coollabs.io/versions.json`
                , {
                    params: {
                        appId: process.env['COOLIFY_APP_ID'] || undefined,
                        version: currentVersion
                    }
                })
        const latestVersion = versions['coolify'].main.version;
        const isUpdateAvailable = compareVersions(latestVersion, currentVersion);
        if (isUpdateAvailable === 1) {
            const activeCount = 0
            if (activeCount === 0) {
                if (!isDev) {
                    const { isAutoUpdateEnabled } = await prisma.setting.findFirst();
                    if (isAutoUpdateEnabled) {
                        await asyncExecShell(`docker pull coollabsio/coolify:${latestVersion}`);
                        await asyncExecShell(`env | grep COOLIFY > .env`);
                        await asyncExecShell(
                            `sed -i '/COOLIFY_AUTO_UPDATE=/cCOOLIFY_AUTO_UPDATE=${isAutoUpdateEnabled}' .env`
                        );
                        await asyncExecShell(
                            `docker run --rm -tid --env-file .env -v /var/run/docker.sock:/var/run/docker.sock -v coolify-db coollabsio/coolify:${latestVersion} /bin/sh -c "env | grep COOLIFY > .env && echo 'TAG=${latestVersion}' >> .env && docker stop -t 0 coolify coolify-fluentbit && docker rm coolify coolify-fluentbit && docker compose pull && docker compose up -d --force-recreate"`
                        );
                    }
                } else {
                    console.log('Updating (not really in dev mode).');
                }
            }
        }
    } catch (error) { }
}
async function checkFluentBit() {
    if (!isDev) {
        const engine = '/var/run/docker.sock';
        const { id } = await prisma.destinationDocker.findFirst({
            where: { engine, network: 'coolify' }
        });
        const { found } = await checkContainer({ dockerId: id, container: 'coolify-fluentbit' });
        if (!found) {
            await asyncExecShell(`env | grep COOLIFY > .env`);
            await asyncExecShell(`docker compose up -d fluent-bit`);
        }
    }
}
async function copySSLCertificates() {
    try {
        const certificates = await prisma.certificate.findMany({ include: { team: true } })
        const teamIds = certificates.map(c => c.teamId)
        const destinations = await prisma.destinationDocker.findMany({ where: { isCoolifyProxyUsed: true, teams: { some: { id: { in: [...teamIds] } } } } })
        for (const destination of destinations) {
            if (destination.remoteEngine) {

                const { id: dockerId, remoteIpAddress, remoteVerified } = destination
                if (!remoteVerified) {
                    continue;
                }
                for (const certificate of certificates) {
                    try {
                        const { id, key, cert } = certificate
                        const decryptedKey = decrypt(key)
                        await fs.writeFile(`/tmp/${id}-key.pem`, decryptedKey)
                        await fs.writeFile(`/tmp/${id}-cert.pem`, cert)
                        await asyncExecShell(`scp /tmp/${id}-cert.pem /tmp/${id}-key.pem ${remoteIpAddress}:/tmp/`)
                        await fs.rm(`/tmp/${id}-key.pem`)
                        await fs.rm(`/tmp/${id}-cert.pem`)
                        await executeSSHCmd({ dockerId, command: `docker exec coolify-proxy sh -c 'test -d /etc/traefik/acme/custom/ || mkdir -p /etc/traefik/acme/custom/'` })
                        await executeSSHCmd({ dockerId, command: `docker cp /tmp/${id}-key.pem coolify-proxy:/etc/traefik/acme/custom/ && rm /tmp/${id}-key.pem` })
                        await executeSSHCmd({ dockerId, command: `docker cp /tmp/${id}-cert.pem coolify-proxy:/etc/traefik/acme/custom/ && rm /tmp/${id}-cert.pem` })
                    } catch (error) {
                        console.log('Error copying SSL certificates to remote engine', error)
                    }
                }

            } else {

                for (const certificate of certificates) {
                    try {
                        const { id, key, cert } = certificate
                        const decryptedKey = decrypt(key)
                        await asyncExecShell(`docker exec coolify-proxy sh -c 'test -d /etc/traefik/acme/custom/ || mkdir -p /etc/traefik/acme/custom/'`)
                        await fs.writeFile(`/tmp/${id}-key.pem`, decryptedKey)
                        await fs.writeFile(`/tmp/${id}-cert.pem`, cert)
                        await asyncExecShell(`docker cp /tmp/${id}-key.pem coolify-proxy:/etc/traefik/acme/custom/`)
                        await asyncExecShell(`docker cp /tmp/${id}-cert.pem coolify-proxy:/etc/traefik/acme/custom/`)
                        await fs.rm(`/tmp/${id}-key.pem`)
                        await fs.rm(`/tmp/${id}-cert.pem`)
                    } catch (error) {
                        console.log('Error copying SSL certificates to remote engine', error)
                    }
                }
            }
        }
    } catch (error) {
        console.log('Error copying SSL certificates', error)
    }
}
async function checkProxies() {
    try {
        const { default: isReachable } = await import('is-port-reachable');
        let portReachable;

        const { arch, ipv4, ipv6 } = await listSettings();

        // Coolify Proxy local
        const engine = '/var/run/docker.sock';
        const localDocker = await prisma.destinationDocker.findFirst({
            where: { engine, network: 'coolify', isCoolifyProxyUsed: true }
        });
        if (localDocker) {
            portReachable = await isReachable(80, { host: ipv4 || ipv6 })
            if (!portReachable) {
                await startTraefikProxy(localDocker.id);
            }
        }
        // Coolify Proxy remote
        const remoteDocker = await prisma.destinationDocker.findMany({
            where: { remoteEngine: true, remoteVerified: true }
        });
        if (remoteDocker.length > 0) {
            for (const docker of remoteDocker) {
                if (docker.isCoolifyProxyUsed) {
                    portReachable = await isReachable(80, { host: docker.remoteIpAddress })
                    if (!portReachable) {
                        await startTraefikProxy(docker.id);
                    }
                }
                try {
                    await createRemoteEngineConfiguration(docker.id)
                } catch (error) { }
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
                if (!portReachable) {
                    await startTraefikTCPProxy(destinationDocker, id, publicPort, 9000);
                }
            }
        }
    } catch (error) {

    }
}
async function cleanupPrismaEngines() {
    if (!isDev) {
        try {
            const { stdout } = await asyncExecShell(`ps -ef | grep /app/prisma-engines/query-engine | grep -v grep | wc -l | xargs`)
            if (stdout.trim() != null && stdout.trim() != '' && Number(stdout.trim()) > 1) {
                await asyncExecShell(`killall -q -e /app/prisma-engines/query-engine -o 1m`)
            }
        } catch (error) { }
    }
}
async function cleanupStorage() {
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
        } catch (error) { }
        await cleanupDockerStorage(destination.id, lowDiskSpace, false)
    }
}

(async () => {
    let status = {
        cleanupStorage: false,
        autoUpdater: false
    }
    if (parentPort) {
        parentPort.on('message', async (message) => {
            if (parentPort) {
                if (message === 'error') throw new Error('oops');
                if (message === 'cancel') {
                    parentPort.postMessage('cancelled');
                    process.exit(1);
                }
                if (message === 'action:cleanupStorage') {
                    if (!status.autoUpdater) {
                        status.cleanupStorage = true
                        await cleanupStorage();
                        status.cleanupStorage = false
                    }
                    return;
                }
                if (message === 'action:cleanupPrismaEngines') {
                    await cleanupPrismaEngines();
                    return;
                }
                if (message === 'action:checkProxies') {
                    await checkProxies();
                    return;
                }
                if (message === 'action:checkFluentBit') {
                    await checkFluentBit();
                    return;
                }
                if (message === 'action:copySSLCertificates') {
                    await copySSLCertificates();
                    return;
                }
                if (message === 'action:autoUpdater') {
                    if (!status.cleanupStorage) {
                        status.autoUpdater = true
                        await autoUpdater();
                        status.autoUpdater = false
                    }
                    return;
                }
            }
        });
    } else process.exit(0);
})();
