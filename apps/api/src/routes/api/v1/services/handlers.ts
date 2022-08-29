import type { FastifyReply, FastifyRequest } from 'fastify';
import fs from 'fs/promises';
import yaml from 'js-yaml';
import bcrypt from 'bcryptjs';
import { prisma, uniqueName, asyncExecShell, getServiceImage, getServiceFromDB, getContainerUsage,isDomainConfigured, saveUpdateableFields, fixType, decrypt, encrypt, getServiceMainPort, createDirectories, ComposeFile, makeLabelForServices, getFreePublicPort, getDomain, errorHandler, generatePassword, isDev, stopTcpHttpProxy, executeDockerCmd, checkDomainsIsValidInDNS, persistentVolumes, asyncSleep, isARM, defaultComposeConfiguration, checkExposedPort } from '../../../../lib/common';
import { day } from '../../../../lib/dayjs';
import { checkContainer, isContainerExited, removeContainer } from '../../../../lib/docker';
import cuid from 'cuid';

import type { OnlyId } from '../../../../types';
import type { ActivateWordpressFtp, CheckService, CheckServiceDomain, DeleteServiceSecret, DeleteServiceStorage, GetServiceLogs, SaveService, SaveServiceDestination, SaveServiceSecret, SaveServiceSettings, SaveServiceStorage, SaveServiceType, SaveServiceVersion, ServiceStartStop, SetWordpressSettings } from './types';
import { defaultServiceConfigurations } from '../../../../lib/services';
import { supportedServiceTypesAndVersions } from '../../../../lib/services/supportedVersions';
import { configureServiceType, removeService } from '../../../../lib/services/common';

export async function listServices(request: FastifyRequest) {
    try {
        const teamId = request.user.teamId;
        const services = await prisma.service.findMany({
            where: { teams: { some: { id: teamId === '0' ? undefined : teamId } } },
            include: { teams: true, destinationDocker: true }
        });
        return {
            services
        }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function newService(request: FastifyRequest, reply: FastifyReply) {
    try {
        const teamId = request.user.teamId;
        const name = uniqueName();

        const { id } = await prisma.service.create({ data: { name, teams: { connect: { id: teamId } } } });
        return reply.status(201).send({ id });
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function getServiceStatus(request: FastifyRequest<OnlyId>) {
    try {
        const teamId = request.user.teamId;
        const { id } = request.params;

        let isRunning = false;
        let isExited = false

        const service = await getServiceFromDB({ id, teamId });
        const { destinationDockerId, settings } = service;

        if (destinationDockerId) {
            isRunning = await checkContainer({ dockerId: service.destinationDocker.id, container: id });
            isExited = await isContainerExited(service.destinationDocker.id, id);
        }
        return {
            isRunning,
            isExited,
            settings
        }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function getService(request: FastifyRequest<OnlyId>) {
    try {
        const teamId = request.user.teamId;
        const { id } = request.params;
        const service = await getServiceFromDB({ id, teamId });
        if (!service) {
            throw { status: 404, message: 'Service not found.' }
        }
        return {
            service
        }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function getServiceType(request: FastifyRequest) {
    try {
        return {
            types: supportedServiceTypesAndVersions
        }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function saveServiceType(request: FastifyRequest<SaveServiceType>, reply: FastifyReply) {
    try {
        const { id } = request.params;
        const { type } = request.body;
        await configureServiceType({ id, type });
        return reply.code(201).send()
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function getServiceVersions(request: FastifyRequest<OnlyId>) {
    try {
        const teamId = request.user.teamId;
        const { id } = request.params;
        const { type } = await getServiceFromDB({ id, teamId });
        return {
            type,
            versions: supportedServiceTypesAndVersions.find((name) => name.name === type).versions
        }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function saveServiceVersion(request: FastifyRequest<SaveServiceVersion>, reply: FastifyReply) {
    try {
        const { id } = request.params;
        const { version } = request.body;
        await prisma.service.update({
            where: { id },
            data: { version }
        });
        return reply.code(201).send({})
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function saveServiceDestination(request: FastifyRequest<SaveServiceDestination>, reply: FastifyReply) {
    try {
        const { id } = request.params;
        const { destinationId } = request.body;
        await prisma.service.update({
            where: { id },
            data: { destinationDocker: { connect: { id: destinationId } } }
        });
        return reply.code(201).send({})
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function getServiceUsage(request: FastifyRequest<OnlyId>) {
    try {
        const teamId = request.user.teamId;
        const { id } = request.params;
        let usage = {};

        const service = await getServiceFromDB({ id, teamId });
        if (service.destinationDockerId) {
            [usage] = await Promise.all([getContainerUsage(service.destinationDocker.id, id)]);
        }
        return {
            usage
        }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }

}
export async function getServiceLogs(request: FastifyRequest<GetServiceLogs>) {
    try {
        const { id } = request.params;
        let { since = 0 } = request.query
        if (since !== 0) {
            since = day(since).unix();
        }
        const { destinationDockerId, destinationDocker: { id: dockerId } } = await prisma.service.findUnique({
            where: { id },
            include: { destinationDocker: true }
        });
        if (destinationDockerId) {
            try {
                // const found = await checkContainer({ dockerId, container: id })
                // if (found) {
                const { default: ansi } = await import('strip-ansi')
                const { stdout, stderr } = await executeDockerCmd({ dockerId, command: `docker logs --since ${since} --tail 5000 --timestamps ${id}` })
                const stripLogsStdout = stdout.toString().split('\n').map((l) => ansi(l)).filter((a) => a);
                const stripLogsStderr = stderr.toString().split('\n').map((l) => ansi(l)).filter((a) => a);
                const logs = stripLogsStderr.concat(stripLogsStdout)
                const sortedLogs = logs.sort((a, b) => (day(a.split(' ')[0]).isAfter(day(b.split(' ')[0])) ? 1 : -1))
                return { logs: sortedLogs }
                // }
            } catch (error) {
                const { statusCode } = error;
                if (statusCode === 404) {
                    return {
                        logs: []
                    };
                }
            }
        }
        return {
            message: 'No logs found.'
        }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function deleteService(request: FastifyRequest<OnlyId>) {
    try {
        const { id } = request.params;
        await removeService({ id });
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function saveServiceSettings(request: FastifyRequest<SaveServiceSettings>, reply: FastifyReply) {
    try {
        const { id } = request.params;
        const { dualCerts } = request.body;
        await prisma.service.update({
            where: { id },
            data: { dualCerts }
        });
        return reply.code(201).send()
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function checkServiceDomain(request: FastifyRequest<CheckServiceDomain>) {
    try {
        const { id } = request.params
        const { domain } = request.query
        const { fqdn, dualCerts } = await prisma.service.findUnique({ where: { id } })
        return await checkDomainsIsValidInDNS({ hostname: domain, fqdn, dualCerts });
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function checkService(request: FastifyRequest<CheckService>) {
    try {
        const { id } = request.params;
        let { fqdn, exposePort, forceSave, otherFqdns, dualCerts } = request.body;

        if (fqdn) fqdn = fqdn.toLowerCase();
        if (otherFqdns && otherFqdns.length > 0) otherFqdns = otherFqdns.map((f) => f.toLowerCase());
        if (exposePort) exposePort = Number(exposePort);

        const { destinationDocker: { id: dockerId, remoteIpAddress, remoteEngine }, exposePort: configuredPort } = await prisma.service.findUnique({ where: { id }, include: { destinationDocker: true } })
        const { isDNSCheckEnabled } = await prisma.setting.findFirst({});

        let found = await isDomainConfigured({ id, fqdn, remoteIpAddress });
        if (found) {
            throw { status: 500, message: `Domain ${getDomain(fqdn).replace('www.', '')} is already in use!` }
        }
        if (otherFqdns && otherFqdns.length > 0) {
            for (const ofqdn of otherFqdns) {
                found = await isDomainConfigured({ id, fqdn: ofqdn, remoteIpAddress });
                if (found) {
                    throw { status: 500, message: `Domain ${getDomain(ofqdn).replace('www.', '')} is already in use!` }
                }
            }
        }
        if (exposePort) await checkExposedPort({ id, configuredPort, exposePort, dockerId, remoteIpAddress })
        if (isDNSCheckEnabled && !isDev && !forceSave) {
            let hostname = request.hostname.split(':')[0];
            if (remoteEngine) hostname = remoteIpAddress;
            return await checkDomainsIsValidInDNS({ hostname, fqdn, dualCerts });
        }
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function saveService(request: FastifyRequest<SaveService>, reply: FastifyReply) {
    try {
        const { id } = request.params;
        let { name, fqdn, exposePort, type } = request.body;

        if (fqdn) fqdn = fqdn.toLowerCase();
        if (exposePort) exposePort = Number(exposePort);

        type = fixType(type)

        const update = saveUpdateableFields(type, request.body[type])
        const data = {
            fqdn,
            name,
            exposePort,
        }
        if (Object.keys(update).length > 0) {
            data[type] = { update: update }
        }
        await prisma.service.update({
            where: { id }, data
        });
        return reply.code(201).send()
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function getServiceSecrets(request: FastifyRequest<OnlyId>) {
    try {
        const { id } = request.params
        let secrets = await prisma.serviceSecret.findMany({
            where: { serviceId: id },
            orderBy: { createdAt: 'desc' }
        });
        secrets = secrets.map((secret) => {
            secret.value = decrypt(secret.value);
            return secret;
        });

        return {
            secrets
        }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function saveServiceSecret(request: FastifyRequest<SaveServiceSecret>, reply: FastifyReply) {
    try {
        const { id } = request.params
        let { name, value, isNew } = request.body

        if (isNew) {
            const found = await prisma.serviceSecret.findFirst({ where: { name, serviceId: id } });
            if (found) {
                throw `Secret ${name} already exists.`
            } else {
                value = encrypt(value.trim());
                await prisma.serviceSecret.create({
                    data: { name, value, service: { connect: { id } } }
                });
            }
        } else {
            value = encrypt(value.trim());
            const found = await prisma.serviceSecret.findFirst({ where: { serviceId: id, name } });

            if (found) {
                await prisma.serviceSecret.updateMany({
                    where: { serviceId: id, name },
                    data: { value }
                });
            } else {
                await prisma.serviceSecret.create({
                    data: { name, value, service: { connect: { id } } }
                });
            }
        }
        return reply.code(201).send()
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function deleteServiceSecret(request: FastifyRequest<DeleteServiceSecret>) {
    try {
        const { id } = request.params
        const { name } = request.body
        await prisma.serviceSecret.deleteMany({ where: { serviceId: id, name } });
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function getServiceStorages(request: FastifyRequest<OnlyId>) {
    try {
        const { id } = request.params
        const persistentStorages = await prisma.servicePersistentStorage.findMany({
            where: { serviceId: id }
        });
        return {
            persistentStorages
        }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function saveServiceStorage(request: FastifyRequest<SaveServiceStorage>, reply: FastifyReply) {
    try {
        const { id } = request.params
        const { path, newStorage, storageId } = request.body

        if (newStorage) {
            await prisma.servicePersistentStorage.create({
                data: { path, service: { connect: { id } } }
            });
        } else {
            await prisma.servicePersistentStorage.update({
                where: { id: storageId },
                data: { path }
            });
        }
        return reply.code(201).send()
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function deleteServiceStorage(request: FastifyRequest<DeleteServiceStorage>) {
    try {
        const { id } = request.params
        const { path } = request.body
        await prisma.servicePersistentStorage.deleteMany({ where: { serviceId: id, path } });
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function setSettingsService(request: FastifyRequest<ServiceStartStop & SetWordpressSettings>, reply: FastifyReply) {
    try {
        const { type } = request.params
        if (type === 'wordpress') {
            return await setWordpressSettings(request, reply)
        }
        throw `Service type ${type} not supported.`
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
async function setWordpressSettings(request: FastifyRequest<ServiceStartStop & SetWordpressSettings>, reply: FastifyReply) {
    try {
        const { id } = request.params
        const { ownMysql } = request.body
        await prisma.wordpress.update({
            where: { serviceId: id },
            data: { ownMysql }
        });
        return reply.code(201).send()
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}


export async function activatePlausibleUsers(request: FastifyRequest<OnlyId>, reply: FastifyReply) {
    try {
        const { id } = request.params
        const teamId = request.user.teamId;
        const {
            destinationDockerId,
            destinationDocker,
            plausibleAnalytics: { postgresqlUser, postgresqlPassword, postgresqlDatabase }
        } = await getServiceFromDB({ id, teamId });
        if (destinationDockerId) {
            await executeDockerCmd({
                dockerId: destinationDocker.id,
                command: `docker exec ${id} 'psql -H postgresql://${postgresqlUser}:${postgresqlPassword}@localhost:5432/${postgresqlDatabase} -c "UPDATE users SET email_verified = true;"'`
            })
            return await reply.code(201).send()
        }
        throw { status: 500, message: 'Could not activate users.' }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function cleanupPlausibleLogs(request: FastifyRequest<OnlyId>, reply: FastifyReply) {
    try {
        const { id } = request.params
        const teamId = request.user.teamId;
        const {
            destinationDockerId,
            destinationDocker,
        } = await getServiceFromDB({ id, teamId });
        if (destinationDockerId) {
            await executeDockerCmd({
                dockerId: destinationDocker.id,
                command: `docker exec ${id}-clickhouse sh -c "/usr/bin/clickhouse-client -q \\"SELECT name FROM system.tables WHERE name LIKE '%log%';\\"| xargs -I{} /usr/bin/clickhouse-client -q \"TRUNCATE TABLE system.{};\""`
            })
            return await reply.code(201).send()
        }
        throw { status: 500, message: 'Could cleanup logs.' }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function activateWordpressFtp(request: FastifyRequest<ActivateWordpressFtp>, reply: FastifyReply) {
    const { id } = request.params
    const { ftpEnabled } = request.body;

    const { service: { destinationDocker: { id: dockerId } } } = await prisma.wordpress.findUnique({ where: { serviceId: id }, include: { service: { include: { destinationDocker: true } } } })

    const publicPort = await getFreePublicPort(id, dockerId);

    let ftpUser = cuid();
    let ftpPassword = generatePassword({});

    const hostkeyDir = isDev ? '/tmp/hostkeys' : '/app/ssl/hostkeys';
    try {
        const data = await prisma.wordpress.update({
            where: { serviceId: id },
            data: { ftpEnabled },
            include: { service: { include: { destinationDocker: true } } }
        });
        const {
            service: { destinationDockerId, destinationDocker },
            ftpPublicPort,
            ftpUser: user,
            ftpPassword: savedPassword,
            ftpHostKey,
            ftpHostKeyPrivate
        } = data;
        const { network, engine } = destinationDocker;
        if (ftpEnabled) {
            if (user) ftpUser = user;
            if (savedPassword) ftpPassword = decrypt(savedPassword);

            const { stdout: password } = await asyncExecShell(
                `echo ${ftpPassword} | openssl passwd -1 -stdin`
            );
            if (destinationDockerId) {
                try {
                    await fs.stat(hostkeyDir);
                } catch (error) {
                    await asyncExecShell(`mkdir -p ${hostkeyDir}`);
                }
                if (!ftpHostKey) {
                    await asyncExecShell(
                        `ssh-keygen -t ed25519 -f ssh_host_ed25519_key -N "" -q -f ${hostkeyDir}/${id}.ed25519`
                    );
                    const { stdout: ftpHostKey } = await asyncExecShell(`cat ${hostkeyDir}/${id}.ed25519`);
                    await prisma.wordpress.update({
                        where: { serviceId: id },
                        data: { ftpHostKey: encrypt(ftpHostKey) }
                    });
                } else {
                    await asyncExecShell(`echo "${decrypt(ftpHostKey)}" > ${hostkeyDir}/${id}.ed25519`);
                }
                if (!ftpHostKeyPrivate) {
                    await asyncExecShell(`ssh-keygen -t rsa -b 4096 -N "" -f ${hostkeyDir}/${id}.rsa`);
                    const { stdout: ftpHostKeyPrivate } = await asyncExecShell(`cat ${hostkeyDir}/${id}.rsa`);
                    await prisma.wordpress.update({
                        where: { serviceId: id },
                        data: { ftpHostKeyPrivate: encrypt(ftpHostKeyPrivate) }
                    });
                } else {
                    await asyncExecShell(`echo "${decrypt(ftpHostKeyPrivate)}" > ${hostkeyDir}/${id}.rsa`);
                }

                await prisma.wordpress.update({
                    where: { serviceId: id },
                    data: {
                        ftpPublicPort: publicPort,
                        ftpUser: user ? undefined : ftpUser,
                        ftpPassword: savedPassword ? undefined : encrypt(ftpPassword)
                    }
                });

                try {
                    const isRunning = await checkContainer({ dockerId: destinationDocker.id, container: `${id}-ftp` });
                    if (isRunning) {
                        await executeDockerCmd({
                            dockerId: destinationDocker.id,
                            command: `docker stop -t 0 ${id}-ftp && docker rm ${id}-ftp`
                        })
                    }
                } catch (error) {
                    console.log(error);
                    //
                }
                const volumes = [
                    `${id}-wordpress-data:/home/${ftpUser}/wordpress`,
                    `${isDev ? hostkeyDir : '/var/lib/docker/volumes/coolify-ssl-certs/_data/hostkeys'
                    }/${id}.ed25519:/etc/ssh/ssh_host_ed25519_key`,
                    `${isDev ? hostkeyDir : '/var/lib/docker/volumes/coolify-ssl-certs/_data/hostkeys'
                    }/${id}.rsa:/etc/ssh/ssh_host_rsa_key`,
                    `${isDev ? hostkeyDir : '/var/lib/docker/volumes/coolify-ssl-certs/_data/hostkeys'
                    }/${id}.sh:/etc/sftp.d/chmod.sh`
                ];

                const compose: ComposeFile = {
                    version: '3.8',
                    services: {
                        [`${id}-ftp`]: {
                            image: `atmoz/sftp:alpine`,
                            command: `'${ftpUser}:${password.replace('\n', '').replace(/\$/g, '$$$')}:e:33'`,
                            extra_hosts: ['host.docker.internal:host-gateway'],
                            container_name: `${id}-ftp`,
                            volumes,
                            networks: [network],
                            depends_on: [],
                            restart: 'always'
                        }
                    },
                    networks: {
                        [network]: {
                            external: true
                        }
                    },
                    volumes: {
                        [`${id}-wordpress-data`]: {
                            external: true,
                            name: `${id}-wordpress-data`
                        }
                    }
                };
                await fs.writeFile(
                    `${hostkeyDir}/${id}.sh`,
                    `#!/bin/bash\nchmod 600 /etc/ssh/ssh_host_ed25519_key /etc/ssh/ssh_host_rsa_key\nuserdel -f xfs\nchown -R 33:33 /home/${ftpUser}/wordpress/`
                );
                await asyncExecShell(`chmod +x ${hostkeyDir}/${id}.sh`);
                await fs.writeFile(`${hostkeyDir}/${id}-docker-compose.yml`, yaml.dump(compose));
                await executeDockerCmd({
                    dockerId: destinationDocker.id,
                    command: `docker compose -f ${hostkeyDir}/${id}-docker-compose.yml up -d`
                })

            }
            return reply.code(201).send({
                publicPort,
                ftpUser,
                ftpPassword
            })
        } else {
            await prisma.wordpress.update({
                where: { serviceId: id },
                data: { ftpPublicPort: null }
            });
            try {
                await executeDockerCmd({
                    dockerId: destinationDocker.id,
                    command: `docker stop -t 0 ${id}-ftp && docker rm ${id}-ftp`
                })

            } catch (error) {
                //
            }
            await stopTcpHttpProxy(id, destinationDocker, ftpPublicPort);
            return {
            };
        }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    } finally {
        try {
            await asyncExecShell(
                `rm -fr ${hostkeyDir}/${id}-docker-compose.yml ${hostkeyDir}/${id}.ed25519 ${hostkeyDir}/${id}.ed25519.pub ${hostkeyDir}/${id}.rsa ${hostkeyDir}/${id}.rsa.pub ${hostkeyDir}/${id}.sh`
            );
        } catch (error) {
            console.log(error)
        }

    }

}
