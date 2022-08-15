import type { FastifyReply, FastifyRequest } from 'fastify';
import fs from 'fs/promises';
import yaml from 'js-yaml';
import bcrypt from 'bcryptjs';
import { prisma, uniqueName, asyncExecShell, getServiceImage, configureServiceType, getServiceFromDB, getContainerUsage, removeService, isDomainConfigured, saveUpdateableFields, fixType, decrypt, encrypt, getServiceMainPort, createDirectories, ComposeFile, makeLabelForServices, getFreePublicPort, getDomain, errorHandler, generatePassword, isDev, stopTcpHttpProxy, supportedServiceTypesAndVersions, executeDockerCmd, listSettings, getFreeExposedPort, checkDomainsIsValidInDNS, persistentVolumes } from '../../../../lib/common';
import { day } from '../../../../lib/dayjs';
import { checkContainer, isContainerExited, removeContainer } from '../../../../lib/docker';
import cuid from 'cuid';

import type { OnlyId } from '../../../../types';
import type { ActivateWordpressFtp, CheckService, CheckServiceDomain, DeleteServiceSecret, DeleteServiceStorage, GetServiceLogs, SaveService, SaveServiceDestination, SaveServiceSecret, SaveServiceSettings, SaveServiceStorage, SaveServiceType, SaveServiceVersion, ServiceStartStop, SetWordpressSettings } from './types';
import { defaultServiceComposeConfiguration, defaultServiceConfigurations } from '../../../../lib/services';

// async function startServiceNew(request: FastifyRequest<OnlyId>) {
//     try {
//         const { id } = request.params;
//         const teamId = request.user.teamId;
//         const service = await getServiceFromDB({ id, teamId });
//         const { type, version, destinationDockerId, destinationDocker, serviceSecret, exposePort } =
//             service;
//         const network = destinationDockerId && destinationDocker.network;
//         const host = getEngine(destinationDocker.engine);
//         const port = getServiceMainPort(type);

//         const { workdir } = await createDirectories({ repository: type, buildId: id });
//         const image = getServiceImage(type);
//         const config = (await getAvailableServices()).find((name) => name.name === type).compose
//         const environmentVariables = {}
//         if (serviceSecret.length > 0) {
//             serviceSecret.forEach((secret) => {
//                 environmentVariables[secret.name] = secret.value;
//             });
//         }
//         config.newVolumes = {}
//         for (const service of Object.entries(config.services)) {
//             const name = service[0]
//             const details: any = service[1]
//             config.services[`${id}-${name}`] = JSON.parse(JSON.stringify(details))
//             config.services[`${id}-${name}`].container_name = `${id}-${name}`
//             config.services[`${id}-${name}`].restart = "always"
//             config.services[`${id}-${name}`].networks = [network]
//             config.services[`${id}-${name}`].labels = makeLabelForServices(type)
//             if (name === config.name) {
//                 config.services[`${id}-${name}`].image = `${details.image.split(':')[0]}:${version}`
//                 config.services[`${id}-${name}`].ports = (exposePort ? [`${exposePort}:${port}`] : [])
//                 config.services[`${id}-${name}`].environment = environmentVariables
//             }
//             config.services[`${id}-${name}`].deploy = {
//                 restart_policy: {
//                     condition: 'on-failure',
//                     delay: '5s',
//                     max_attempts: 3,
//                     window: '120s'
//                 }
//             }
//             if (config.services[`${id}-${name}`]?.volumes?.length > 0) {
//                 config.services[`${id}-${name}`].volumes.forEach((volume, index) => {
//                     let oldVolumeName = volume.split(':')[0]
//                     const path = volume.split(':')[1]
//                     // if (config?.volumes[oldVolumeName]) delete config?.volumes[oldVolumeName]
//                     const newName = convertTolOldVolumeNames(type)
//                     if (newName) oldVolumeName = newName

//                     const volumeName = `${id}-${oldVolumeName}`
//                     config.newVolumes[volumeName] = {
//                         name: volumeName
//                     }
//                     config.services[`${id}-${name}`].volumes[index] = `${volumeName}:${path}`
//                 })
//                 config.services[`${id}-${config.name}`] = {
//                     ...config.services[`${id}-${config.name}`],
//                     environment: environmentVariables
//                 }
//             }
//             config.networks = {
//                 [network]: {
//                     external: true
//                 }
//             }

//             config.volumes = config.newVolumes

//             // config.services[`${id}-${name}`]?.volumes?.length > 0 && config.services[`${id}-${name}`].volumes.forEach((volume, index) => {
//             //     let oldVolumeName = volume.split(':')[0]
//             //     const path = volume.split(':')[1]
//             //     oldVolumeName = convertTolOldVolumeNames(type)
//             //     const volumeName = `${id}-${oldVolumeName}`
//             //     config.volumes[volumeName] = {
//             //         name: volumeName
//             //     }
//             //     config.services[`${id}-${name}`].volumes[index] = `${volumeName}:${path}`
//             // })
//             // config.services[`${id}-${config.name}`] = {
//             //     ...config.services[`${id}-${config.name}`],
//             //     environment: environmentVariables
//             // }
//             delete config.services[name]

//         }
//         console.log(config.services)
//         console.log(config.volumes)

//         // config.services[id] = JSON.parse(JSON.stringify(config.services[type]))
//         // config.services[id].container_name = id
//         // config.services[id].image = `${image}:${version}`
//         // config.services[id].ports = (exposePort ? [`${exposePort}:${port}`] : []),
//         //     config.services[id].restart = "always"
//         // config.services[id].networks = [network]
//         // config.services[id].labels = makeLabelForServices(type)
//         // config.services[id].deploy = {
//         //     restart_policy: {
//         //         condition: 'on-failure',
//         //         delay: '5s',
//         //         max_attempts: 3,
//         //         window: '120s'
//         //     }
//         // }
//         // config.networks = {
//         //     [network]: {
//         //         external: true
//         //     }
//         // }
//         // config.volumes = {}
//         // config.services[id].volumes.forEach((volume, index) => {
//         //     let oldVolumeName = volume.split(':')[0]
//         //     const path = volume.split(':')[1]
//         //     oldVolumeName = convertTolOldVolumeNames(type)
//         //     const volumeName = `${id}-${oldVolumeName}`
//         //     config.volumes[volumeName] = {
//         //         name: volumeName
//         //     }
//         //     config.services[id].volumes[index] = `${volumeName}:${path}`
//         // })
//         // delete config.services[type]
//         // config.services[id].environment = environmentVariables
//         const composeFileDestination = `${workdir}/docker-compose.yaml`;
//         // await fs.writeFile(composeFileDestination, yaml.dump(config));
//         // await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} pull`);
//         // await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} up -d`);
//         return {}
//     } catch ({ status, message }) {
//         return errorHandler({ status, message })
//     }
// }

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
        if (exposePort) {
            if (exposePort < 1024 || exposePort > 65535) {
                throw { status: 500, message: `Exposed Port needs to be between 1024 and 65535.` }
            }

            if (configuredPort !== exposePort) {
                const availablePort = await getFreeExposedPort(id, exposePort, dockerId, remoteIpAddress);
                if (availablePort.toString() !== exposePort.toString()) {
                    throw { status: 500, message: `Port ${exposePort} is already in use.` }
                }
            }
        }
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
                value = encrypt(value);
                await prisma.serviceSecret.create({
                    data: { name, value, service: { connect: { id } } }
                });
            }
        } else {
            value = encrypt(value);
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

export async function startService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { type } = request.params
        if (type === 'plausibleanalytics') {
            return await startPlausibleAnalyticsService(request)
        }
        if (type === 'nocodb') {
            return await startNocodbService(request)
        }
        if (type === 'minio') {
            return await startMinioService(request)
        }
        if (type === 'vscodeserver') {
            return await startVscodeService(request)
        }
        if (type === 'wordpress') {
            return await startWordpressService(request)
        }
        if (type === 'vaultwarden') {
            return await startVaultwardenService(request)
        }
        if (type === 'languagetool') {
            return await startLanguageToolService(request)
        }
        if (type === 'n8n') {
            return await startN8nService(request)
        }
        if (type === 'uptimekuma') {
            return await startUptimekumaService(request)
        }
        if (type === 'ghost') {
            return await startGhostService(request)
        }
        if (type === 'meilisearch') {
            return await startMeilisearchService(request)
        }
        if (type === 'umami') {
            return await startUmamiService(request)
        }
        if (type === 'hasura') {
            return await startHasuraService(request)
        }
        if (type === 'fider') {
            return await startFiderService(request)
        }
        if (type === 'moodle') {
            return await startMoodleService(request)
        }
        if (type === 'appwrite') {
            return await startAppWriteService(request)
        }
        if (type === 'glitchTip') {
            return await startGlitchTipService(request)
        }
        throw `Service type ${type} not supported.`
    } catch (error) {
        throw { status: 500, message: error?.message || error }
    }
}
export async function stopService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { type } = request.params
        if (type === 'plausibleanalytics') {
            return await stopPlausibleAnalyticsService(request)
        }
        if (type === 'nocodb') {
            return await stopNocodbService(request)
        }
        if (type === 'minio') {
            return await stopMinioService(request)
        }
        if (type === 'vscodeserver') {
            return await stopVscodeService(request)
        }
        if (type === 'wordpress') {
            return await stopWordpressService(request)
        }
        if (type === 'vaultwarden') {
            return await stopVaultwardenService(request)
        }
        if (type === 'languagetool') {
            return await stopLanguageToolService(request)
        }
        if (type === 'n8n') {
            return await stopN8nService(request)
        }
        if (type === 'uptimekuma') {
            return await stopUptimekumaService(request)
        }
        if (type === 'ghost') {
            return await stopGhostService(request)
        }
        if (type === 'meilisearch') {
            return await stopMeilisearchService(request)
        }
        if (type === 'umami') {
            return await stopUmamiService(request)
        }
        if (type === 'hasura') {
            return await stopHasuraService(request)
        }
        if (type === 'fider') {
            return await stopFiderService(request)
        }
        if (type === 'appwrite') {
            return await stopAppWriteService(request)
        }
        if (type === 'moodle') {
            return await stopMoodleService(request)
        }
        if (type === 'glitchTip') {
            return await stopGlitchTipService(request)
        }
        throw `Service type ${type} not supported.`
    } catch (error) {
        throw { status: 500, message: error?.message || error }
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

async function startPlausibleAnalyticsService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const {
            type,
            version,
            fqdn,
            destinationDockerId,
            destinationDocker,
            serviceSecret,
            persistentStorage,
            exposePort,
            plausibleAnalytics: {
                id: plausibleDbId,
                username,
                email,
                password,
                postgresqlDatabase,
                postgresqlPassword,
                postgresqlUser,
                secretKeyBase
            }
        } = service;
        const image = getServiceImage(type);

        const config = {
            plausibleAnalytics: {
                image: `${image}:${version}`,
                environmentVariables: {
                    ADMIN_USER_EMAIL: email,
                    ADMIN_USER_NAME: username,
                    ADMIN_USER_PWD: password,
                    BASE_URL: fqdn,
                    SECRET_KEY_BASE: secretKeyBase,
                    DISABLE_AUTH: 'false',
                    DISABLE_REGISTRATION: 'true',
                    DATABASE_URL: `postgresql://${postgresqlUser}:${postgresqlPassword}@${id}-postgresql:5432/${postgresqlDatabase}`,
                    CLICKHOUSE_DATABASE_URL: `http://${id}-clickhouse:8123/plausible`
                }
            },
            postgresql: {
                volume: `${plausibleDbId}-postgresql-data:/bitnami/postgresql/`,
                image: 'bitnami/postgresql:13.2.0',
                environmentVariables: {
                    POSTGRESQL_PASSWORD: postgresqlPassword,
                    POSTGRESQL_USERNAME: postgresqlUser,
                    POSTGRESQL_DATABASE: postgresqlDatabase
                }
            },
            clickhouse: {
                volume: `${plausibleDbId}-clickhouse-data:/var/lib/clickhouse`,
                image: 'yandex/clickhouse-server:21.3.2.5',
                environmentVariables: {},
                ulimits: {
                    nofile: {
                        soft: 262144,
                        hard: 262144
                    }
                }
            }
        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.plausibleAnalytics.environmentVariables[secret.name] = secret.value;
            });
        }
        const network = destinationDockerId && destinationDocker.network;
        const port = getServiceMainPort('plausibleanalytics');

        const { workdir } = await createDirectories({ repository: type, buildId: id });

        const clickhouseConfigXml = `
        <yandex>
          <logger>
              <level>warning</level>
              <console>true</console>
          </logger>
    
          <!-- Stop all the unnecessary logging -->
          <query_thread_log remove="remove"/>
          <query_log remove="remove"/>
          <text_log remove="remove"/>
          <trace_log remove="remove"/>
          <metric_log remove="remove"/>
          <asynchronous_metric_log remove="remove"/>
          <session_log remove="remove"/>
          <part_log remove="remove"/>
      </yandex>`;
        const clickhouseUserConfigXml = `
        <yandex>
          <profiles>
              <default>
                  <log_queries>0</log_queries>
                  <log_query_threads>0</log_query_threads>
              </default>
          </profiles>
      </yandex>`;

        const initQuery = 'CREATE DATABASE IF NOT EXISTS plausible;';
        const initScript = 'clickhouse client --queries-file /docker-entrypoint-initdb.d/init.query';
        await fs.writeFile(`${workdir}/clickhouse-config.xml`, clickhouseConfigXml);
        await fs.writeFile(`${workdir}/clickhouse-user-config.xml`, clickhouseUserConfigXml);
        await fs.writeFile(`${workdir}/init.query`, initQuery);
        await fs.writeFile(`${workdir}/init-db.sh`, initScript);

        const Dockerfile = `
FROM ${config.clickhouse.image}
COPY ./clickhouse-config.xml /etc/clickhouse-server/users.d/logging.xml
COPY ./clickhouse-user-config.xml /etc/clickhouse-server/config.d/logging.xml
COPY ./init.query /docker-entrypoint-initdb.d/init.query
COPY ./init-db.sh /docker-entrypoint-initdb.d/init-db.sh`;

        await fs.writeFile(`${workdir}/Dockerfile`, Dockerfile);

        const { volumes, volumeMounts } = persistentVolumes(id, persistentStorage, config.plausibleAnalytics)

        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.plausibleAnalytics.image,
                    volumes,
                    command:
                        'sh -c "sleep 10 && /entrypoint.sh db createdb && /entrypoint.sh db migrate && /entrypoint.sh db init-admin && /entrypoint.sh run"',
                    networks: [network],
                    environment: config.plausibleAnalytics.environmentVariables,
                    restart: 'always',
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    depends_on: [`${id}-postgresql`, `${id}-clickhouse`],
                    labels: makeLabelForServices('plausibleAnalytics'),
                    deploy: {
                        restart_policy: {
                            condition: 'on-failure',
                            delay: '10s',
                            max_attempts: 5,
                            window: '120s'
                        }
                    }
                },
                [`${id}-postgresql`]: {
                    container_name: `${id}-postgresql`,
                    image: config.postgresql.image,
                    networks: [network],
                    environment: config.postgresql.environmentVariables,
                    volumes: [config.postgresql.volume],
                    restart: 'always',
                    deploy: {
                        restart_policy: {
                            condition: 'on-failure',
                            delay: '10s',
                            max_attempts: 5,
                            window: '120s'
                        }
                    }
                },
                [`${id}-clickhouse`]: {
                    build: workdir,
                    container_name: `${id}-clickhouse`,
                    networks: [network],
                    environment: config.clickhouse.environmentVariables,
                    volumes: [config.clickhouse.volume],
                    restart: 'always',
                    deploy: {
                        restart_policy: {
                            condition: 'on-failure',
                            delay: '10s',
                            max_attempts: 5,
                            window: '120s'
                        }
                    }
                }
            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: {
                ...volumeMounts,
                [config.postgresql.volume.split(':')[0]]: {
                    name: config.postgresql.volume.split(':')[0]
                },
                [config.clickhouse.volume.split(':')[0]]: {
                    name: config.clickhouse.volume.split(':')[0]
                }
            }
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));
        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} pull` })
        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} up --build -d` })
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
async function stopPlausibleAnalyticsService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const { destinationDockerId, destinationDocker, fqdn } = service;
        if (destinationDockerId) {
            const engine = destinationDocker.engine;

            let found = await checkContainer({ dockerId: destinationDocker.id, container: id });
            if (found) {
                await removeContainer({ id, dockerId: destinationDocker.id });
            }
            found = await checkContainer({ dockerId: destinationDocker.id, container: `${id}-postgresql` });
            if (found) {
                await removeContainer({ id: `${id}-postgresql`, dockerId: destinationDocker.id });
            }
            found = await checkContainer({ dockerId: destinationDocker.id, container: `${id}-clickhouse` });
            if (found) {
                await removeContainer({ id: `${id}-clickhouse`, dockerId: destinationDocker.id });
            }
        }

        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

async function startNocodbService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const { type, version, destinationDockerId, destinationDocker, serviceSecret, exposePort, persistentStorage } =
            service;
        const network = destinationDockerId && destinationDocker.network;
        const port = getServiceMainPort('nocodb');

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);

        const config = {
            image: `${image}:${version}`,
            volume: `${id}-nc:/usr/app/data`,
            environmentVariables: {}
        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumes, volumeMounts } = persistentVolumes(id, persistentStorage, config)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.image,
                    networks: [network],
                    volumes,
                    environment: config.environmentVariables,
                    restart: 'always',
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    labels: makeLabelForServices('nocodb'),
                    deploy: {
                        restart_policy: {
                            condition: 'on-failure',
                            delay: '5s',
                            max_attempts: 3,
                            window: '120s'
                        }
                    }
                }
            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: volumeMounts
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));
        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} pull` })
        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} up --build -d` })
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
async function stopNocodbService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const { destinationDockerId, destinationDocker, fqdn } = service;
        if (destinationDockerId) {
            const found = await checkContainer({ dockerId: destinationDocker.id, container: id });
            if (found) {
                await removeContainer({ id, dockerId: destinationDocker.id });
            }
        }
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

async function startMinioService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const {
            type,
            version,
            fqdn,
            destinationDockerId,
            destinationDocker,
            persistentStorage,
            exposePort,
            minio: { rootUser, rootUserPassword },
            serviceSecret
        } = service;

        const network = destinationDockerId && destinationDocker.network;
        const port = getServiceMainPort('minio');

        const { service: { destinationDocker: { id: dockerId } } } = await prisma.minio.findUnique({ where: { serviceId: id }, include: { service: { include: { destinationDocker: true } } } })
        const publicPort = await getFreePublicPort(id, dockerId);

        const consolePort = 9001;
        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);

        const config = {
            image: `${image}:${version}`,
            volume: `${id}-minio-data:/data`,
            environmentVariables: {
                MINIO_ROOT_USER: rootUser,
                MINIO_ROOT_PASSWORD: rootUserPassword,
                MINIO_BROWSER_REDIRECT_URL: fqdn
            }
        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumes, volumeMounts } = persistentVolumes(id, persistentStorage, config)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.image,
                    command: `server /data --console-address ":${consolePort}"`,
                    environment: config.environmentVariables,
                    networks: [network],
                    volumes,
                    restart: 'always',
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    labels: makeLabelForServices('minio'),
                    deploy: {
                        restart_policy: {
                            condition: 'on-failure',
                            delay: '5s',
                            max_attempts: 3,
                            window: '120s'
                        }
                    }
                }
            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: volumeMounts
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));
        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} pull` })
        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} up --build -d` })
        await prisma.minio.update({ where: { serviceId: id }, data: { publicPort } });
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
async function stopMinioService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const { destinationDockerId, destinationDocker } = service;
        await prisma.minio.update({ where: { serviceId: id }, data: { publicPort: null } })
        if (destinationDockerId) {
            const found = await checkContainer({ dockerId: destinationDocker.id, container: id });
            if (found) {
                await removeContainer({ id, dockerId: destinationDocker.id });
            }
        }
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

async function startVscodeService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const {
            type,
            version,
            destinationDockerId,
            destinationDocker,
            serviceSecret,
            persistentStorage,
            exposePort,
            vscodeserver: { password }
        } = service;

        const network = destinationDockerId && destinationDocker.network;
        const port = getServiceMainPort('vscodeserver');

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);

        const config = {
            image: `${image}:${version}`,
            volume: `${id}-vscodeserver-data:/home/coder`,
            environmentVariables: {
                PASSWORD: password
            }
        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumes, volumeMounts } = persistentVolumes(id, persistentStorage, config)

        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.image,
                    environment: config.environmentVariables,
                    networks: [network],
                    volumes,
                    restart: 'always',
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    labels: makeLabelForServices('vscodeServer'),
                    deploy: {
                        restart_policy: {
                            condition: 'on-failure',
                            delay: '5s',
                            max_attempts: 3,
                            window: '120s'
                        }
                    }
                }
            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: volumeMounts
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} pull` })
        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} up --build -d` })

        const changePermissionOn = persistentStorage.map((p) => p.path);
        if (changePermissionOn.length > 0) {
            await executeDockerCmd({
                dockerId: destinationDocker.id, command: `docker exec -u root ${id} chown -R 1000:1000 ${changePermissionOn.join(
                    ' '
                )}`
            })
        }
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
async function stopVscodeService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const { destinationDockerId, destinationDocker } = service;
        if (destinationDockerId) {
            const found = await checkContainer({ dockerId: destinationDocker.id, container: id });
            if (found) {
                await removeContainer({ id, dockerId: destinationDocker.id });
            }
        }
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

async function startWordpressService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const {
            type,
            version,
            destinationDockerId,
            serviceSecret,
            destinationDocker,
            persistentStorage,
            exposePort,
            wordpress: {
                mysqlDatabase,
                mysqlHost,
                mysqlPort,
                mysqlUser,
                mysqlPassword,
                extraConfig,
                mysqlRootUser,
                mysqlRootUserPassword,
                ownMysql
            }
        } = service;

        const network = destinationDockerId && destinationDocker.network;
        const image = getServiceImage(type);
        const port = getServiceMainPort('wordpress');

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const config = {
            wordpress: {
                image: `${image}:${version}`,
                volume: `${id}-wordpress-data:/var/www/html`,
                environmentVariables: {
                    WORDPRESS_DB_HOST: ownMysql ? `${mysqlHost}:${mysqlPort}` : `${id}-mysql`,
                    WORDPRESS_DB_USER: mysqlUser,
                    WORDPRESS_DB_PASSWORD: mysqlPassword,
                    WORDPRESS_DB_NAME: mysqlDatabase,
                    WORDPRESS_CONFIG_EXTRA: extraConfig
                }
            },
            mysql: {
                image: `bitnami/mysql:5.7`,
                volume: `${id}-mysql-data:/bitnami/mysql/data`,
                environmentVariables: {
                    MYSQL_ROOT_PASSWORD: mysqlRootUserPassword,
                    MYSQL_ROOT_USER: mysqlRootUser,
                    MYSQL_USER: mysqlUser,
                    MYSQL_PASSWORD: mysqlPassword,
                    MYSQL_DATABASE: mysqlDatabase
                }
            }
        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.wordpress.environmentVariables[secret.name] = secret.value;
            });
        }

        const { volumes, volumeMounts } = persistentVolumes(id, persistentStorage, config.wordpress)

        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.wordpress.image,
                    environment: config.wordpress.environmentVariables,
                    volumes,
                    networks: [network],
                    restart: 'always',
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    labels: makeLabelForServices('wordpress'),
                    deploy: {
                        restart_policy: {
                            condition: 'on-failure',
                            delay: '5s',
                            max_attempts: 3,
                            window: '120s'
                        }
                    }
                }
            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: volumeMounts
        };
        if (!ownMysql) {
            composeFile.services[id].depends_on = [`${id}-mysql`];
            composeFile.services[`${id}-mysql`] = {
                container_name: `${id}-mysql`,
                image: config.mysql.image,
                volumes: [config.mysql.volume],
                environment: config.mysql.environmentVariables,
                networks: [network],
                restart: 'always',
                deploy: {
                    restart_policy: {
                        condition: 'on-failure',
                        delay: '5s',
                        max_attempts: 3,
                        window: '120s'
                    }
                }
            };

            composeFile.volumes[config.mysql.volume.split(':')[0]] = {
                name: config.mysql.volume.split(':')[0]
            };
        }
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} pull` })
        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} up --build -d` })

        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
async function stopWordpressService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const {
            destinationDockerId,
            destinationDocker,
            wordpress: { ftpEnabled }
        } = service;
        if (destinationDockerId) {
            try {
                const found = await checkContainer({ dockerId: destinationDocker.id, container: id });
                if (found) {
                    await removeContainer({ id, dockerId: destinationDocker.id });
                }
            } catch (error) {
                console.error(error);
            }
            try {
                const found = await checkContainer({ dockerId: destinationDocker.id, container: `${id}-mysql` });
                if (found) {
                    await removeContainer({ id: `${id}-mysql`, dockerId: destinationDocker.id });
                }
            } catch (error) {
                console.error(error);
            }
            try {
                if (ftpEnabled) {
                    const found = await checkContainer({ dockerId: destinationDocker.id, container: `${id}-ftp` });
                    if (found) {
                        await removeContainer({ id: `${id}-ftp`, dockerId: destinationDocker.id });
                    }
                    await prisma.wordpress.update({
                        where: { serviceId: id },
                        data: { ftpEnabled: false }
                    });
                }
            } catch (error) {
                console.error(error);
            }
        }
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

async function startVaultwardenService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const { type, version, destinationDockerId, destinationDocker, serviceSecret, exposePort, persistentStorage } =
            service;

        const network = destinationDockerId && destinationDocker.network;
        const port = getServiceMainPort('vaultwarden');

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);

        const config = {
            image: `${image}:${version}`,
            volume: `${id}-vaultwarden-data:/data/`,
            environmentVariables: {}
        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumes, volumeMounts } = persistentVolumes(id, persistentStorage, config)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.image,
                    environment: config.environmentVariables,
                    networks: [network],
                    volumes,
                    restart: 'always',
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    labels: makeLabelForServices('vaultWarden'),
                    deploy: {
                        restart_policy: {
                            condition: 'on-failure',
                            delay: '5s',
                            max_attempts: 3,
                            window: '120s'
                        }
                    }
                }
            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: volumeMounts
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} pull` })
        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} up --build -d` })

        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
async function stopVaultwardenService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const { destinationDockerId, destinationDocker } = service;
        if (destinationDockerId) {
            try {
                const found = await checkContainer({ dockerId: destinationDocker.id, container: id });
                if (found) {
                    await removeContainer({ id, dockerId: destinationDocker.id });
                }
            } catch (error) {
                console.error(error);
            }
        }
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

async function startLanguageToolService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const { type, version, destinationDockerId, destinationDocker, serviceSecret, exposePort, persistentStorage } =
            service;
        const network = destinationDockerId && destinationDocker.network;
        const port = getServiceMainPort('languagetool');

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);

        const config = {
            image: `${image}:${version}`,
            volume: `${id}-ngrams:/ngrams`,
            environmentVariables: {}
        };

        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumes, volumeMounts } = persistentVolumes(id, persistentStorage, config)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.image,
                    networks: [network],
                    environment: config.environmentVariables,
                    restart: 'always',
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    volumes,
                    labels: makeLabelForServices('languagetool'),
                    deploy: {
                        restart_policy: {
                            condition: 'on-failure',
                            delay: '5s',
                            max_attempts: 3,
                            window: '120s'
                        }
                    }
                }
            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: volumeMounts
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} pull` })
        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} up --build -d` })

        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
async function stopLanguageToolService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const { destinationDockerId, destinationDocker } = service;
        if (destinationDockerId) {
            try {
                const found = await checkContainer({ dockerId: destinationDocker.id, container: id });
                if (found) {
                    await removeContainer({ id, dockerId: destinationDocker.id });
                }
            } catch (error) {
                console.error(error);
            }
        }
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

async function startN8nService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const { type, version, destinationDockerId, destinationDocker, serviceSecret, exposePort, persistentStorage } =
            service;
        const network = destinationDockerId && destinationDocker.network;
        const port = getServiceMainPort('n8n');

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);

        const config = {
            image: `${image}:${version}`,
            volume: `${id}-n8n:/root/.n8n`,
            environmentVariables: {
                WEBHOOK_URL: `${service.fqdn}`
            }
        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumes, volumeMounts } = persistentVolumes(id, persistentStorage, config)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.image,
                    networks: [network],
                    volumes,
                    environment: config.environmentVariables,
                    restart: 'always',
                    labels: makeLabelForServices('n8n'),
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    deploy: {
                        restart_policy: {
                            condition: 'on-failure',
                            delay: '5s',
                            max_attempts: 3,
                            window: '120s'
                        }
                    }
                }
            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: volumeMounts
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} pull` })
        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} up --build -d` })

        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
async function stopN8nService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const { destinationDockerId, destinationDocker } = service;
        if (destinationDockerId) {
            try {
                const found = await checkContainer({ dockerId: destinationDocker.id, container: id });
                if (found) {
                    await removeContainer({ id, dockerId: destinationDocker.id });
                }
            } catch (error) {
                console.error(error);
            }
        }
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

async function startUptimekumaService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const { type, version, destinationDockerId, destinationDocker, serviceSecret, exposePort, persistentStorage } =
            service;
        const network = destinationDockerId && destinationDocker.network;
        const port = getServiceMainPort('uptimekuma');

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);

        const config = {
            image: `${image}:${version}`,
            volume: `${id}-uptimekuma:/app/data`,
            environmentVariables: {}
        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumes, volumeMounts } = persistentVolumes(id, persistentStorage, config)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.image,
                    networks: [network],
                    volumes,
                    environment: config.environmentVariables,
                    restart: 'always',
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    labels: makeLabelForServices('uptimekuma'),
                    deploy: {
                        restart_policy: {
                            condition: 'on-failure',
                            delay: '5s',
                            max_attempts: 3,
                            window: '120s'
                        }
                    }
                }
            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: volumeMounts
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} pull` })
        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} up --build -d` })

        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
async function stopUptimekumaService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const { destinationDockerId, destinationDocker } = service;
        if (destinationDockerId) {
            try {
                const found = await checkContainer({ dockerId: destinationDocker.id, container: id });
                if (found) {
                    await removeContainer({ id, dockerId: destinationDocker.id });
                }
            } catch (error) {
                console.error(error);
            }
        }
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

async function startGhostService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const {
            type,
            version,
            destinationDockerId,
            destinationDocker,
            serviceSecret,
            persistentStorage,
            exposePort,
            fqdn,
            ghost: {
                defaultEmail,
                defaultPassword,
                mariadbRootUser,
                mariadbRootUserPassword,
                mariadbDatabase,
                mariadbPassword,
                mariadbUser
            }
        } = service;
        const network = destinationDockerId && destinationDocker.network;

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);
        const domain = getDomain(fqdn);
        const port = getServiceMainPort('ghost');
        const isHttps = fqdn.startsWith('https://');
        const config = {
            ghost: {
                image: `${image}:${version}`,
                volume: `${id}-ghost:/bitnami/ghost`,
                environmentVariables: {
                    url: fqdn,
                    GHOST_HOST: domain,
                    GHOST_ENABLE_HTTPS: isHttps ? 'yes' : 'no',
                    GHOST_EMAIL: defaultEmail,
                    GHOST_PASSWORD: defaultPassword,
                    GHOST_DATABASE_HOST: `${id}-mariadb`,
                    GHOST_DATABASE_USER: mariadbUser,
                    GHOST_DATABASE_PASSWORD: mariadbPassword,
                    GHOST_DATABASE_NAME: mariadbDatabase,
                    GHOST_DATABASE_PORT_NUMBER: 3306
                }
            },
            mariadb: {
                image: `bitnami/mariadb:latest`,
                volume: `${id}-mariadb:/bitnami/mariadb`,
                environmentVariables: {
                    MARIADB_USER: mariadbUser,
                    MARIADB_PASSWORD: mariadbPassword,
                    MARIADB_DATABASE: mariadbDatabase,
                    MARIADB_ROOT_USER: mariadbRootUser,
                    MARIADB_ROOT_PASSWORD: mariadbRootUserPassword
                }
            }
        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.ghost.environmentVariables[secret.name] = secret.value;
            });
        }

        const { volumes, volumeMounts } = persistentVolumes(id, persistentStorage, config.ghost)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.ghost.image,
                    networks: [network],
                    volumes,
                    environment: config.ghost.environmentVariables,
                    restart: 'always',
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    labels: makeLabelForServices('ghost'),
                    depends_on: [`${id}-mariadb`],
                    deploy: {
                        restart_policy: {
                            condition: 'on-failure',
                            delay: '5s',
                            max_attempts: 3,
                            window: '120s'
                        }
                    }
                },
                [`${id}-mariadb`]: {
                    container_name: `${id}-mariadb`,
                    image: config.mariadb.image,
                    networks: [network],
                    volumes: [config.mariadb.volume],
                    environment: config.mariadb.environmentVariables,
                    restart: 'always',
                    deploy: {
                        restart_policy: {
                            condition: 'on-failure',
                            delay: '5s',
                            max_attempts: 3,
                            window: '120s'
                        }
                    }
                }
            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: {
                ...volumeMounts,
                [config.mariadb.volume.split(':')[0]]: {
                    name: config.mariadb.volume.split(':')[0]
                }
            }
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} pull` })
        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} up --build -d` })

        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
async function stopGhostService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const { destinationDockerId, destinationDocker } = service;
        if (destinationDockerId) {
            try {
                let found = await checkContainer({ dockerId: destinationDocker.id, container: id });
                if (found) {
                    await removeContainer({ id, dockerId: destinationDocker.id });
                }
                found = await checkContainer({ dockerId: destinationDocker.id, container: `${id}-mariadb` });
                if (found) {
                    await removeContainer({ id: `${id}-mariadb`, dockerId: destinationDocker.id });
                }
            } catch (error) {
                console.error(error);
            }
        }
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

async function startMeilisearchService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const {
            meiliSearch: { masterKey }
        } = service;
        const { type, version, destinationDockerId, destinationDocker, serviceSecret, exposePort, persistentStorage } =
            service;
        const network = destinationDockerId && destinationDocker.network;
        const port = getServiceMainPort('meilisearch');

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);

        const config = {
            image: `${image}:${version}`,
            volume: `${id}-datams:/data.ms`,
            environmentVariables: {
                MEILI_MASTER_KEY: masterKey
            }
        };

        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumes, volumeMounts } = persistentVolumes(id, persistentStorage, config)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.image,
                    networks: [network],
                    environment: config.environmentVariables,
                    restart: 'always',
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    volumes,
                    labels: makeLabelForServices('meilisearch'),
                    deploy: {
                        restart_policy: {
                            condition: 'on-failure',
                            delay: '5s',
                            max_attempts: 3,
                            window: '120s'
                        }
                    }
                }
            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: volumeMounts
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));
        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} pull` })
        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} up --build -d` })
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
async function stopMeilisearchService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const { destinationDockerId, destinationDocker } = service;
        if (destinationDockerId) {
            try {
                const found = await checkContainer({ dockerId: destinationDocker.id, container: id });
                if (found) {
                    await removeContainer({ id, dockerId: destinationDocker.id });
                }
            } catch (error) {
                console.error(error);
            }
        }
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

async function startUmamiService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const {
            type,
            version,
            destinationDockerId,
            destinationDocker,
            serviceSecret,
            persistentStorage,
            exposePort,
            umami: {
                umamiAdminPassword,
                postgresqlUser,
                postgresqlPassword,
                postgresqlDatabase,
                hashSalt
            }
        } = service;
        const network = destinationDockerId && destinationDocker.network;
        const port = getServiceMainPort('umami');

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);

        const config = {
            umami: {
                image: `${image}:${version}`,
                environmentVariables: {
                    DATABASE_URL: `postgresql://${postgresqlUser}:${postgresqlPassword}@${id}-postgresql:5432/${postgresqlDatabase}`,
                    DATABASE_TYPE: 'postgresql',
                    HASH_SALT: hashSalt
                }
            },
            postgresql: {
                image: 'postgres:12-alpine',
                volume: `${id}-postgresql-data:/var/lib/postgresql/data`,
                environmentVariables: {
                    POSTGRES_USER: postgresqlUser,
                    POSTGRES_PASSWORD: postgresqlPassword,
                    POSTGRES_DB: postgresqlDatabase
                }
            }
        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.umami.environmentVariables[secret.name] = secret.value;
            });
        }

        const initDbSQL = `
		drop table if exists event;
		drop table if exists pageview;
		drop table if exists session;
		drop table if exists website;
		drop table if exists account;
		
		create table account (
			user_id serial primary key,
			username varchar(255) unique not null,
			password varchar(60) not null,
			is_admin bool not null default false,
			created_at timestamp with time zone default current_timestamp,
			updated_at timestamp with time zone default current_timestamp
		);
		
		create table website (
			website_id serial primary key,
			website_uuid uuid unique not null,
			user_id int not null references account(user_id) on delete cascade,
			name varchar(100) not null,
			domain varchar(500),
			share_id varchar(64) unique,
			created_at timestamp with time zone default current_timestamp
		);
		
		create table session (
			session_id serial primary key,
			session_uuid uuid unique not null,
			website_id int not null references website(website_id) on delete cascade,
			created_at timestamp with time zone default current_timestamp,
			hostname varchar(100),
			browser varchar(20),
			os varchar(20),
			device varchar(20),
			screen varchar(11),
			language varchar(35),
			country char(2)
		);
		
		create table pageview (
			view_id serial primary key,
			website_id int not null references website(website_id) on delete cascade,
			session_id int not null references session(session_id) on delete cascade,
			created_at timestamp with time zone default current_timestamp,
			url varchar(500) not null,
			referrer varchar(500)
		);
		
		create table event (
			event_id serial primary key,
			website_id int not null references website(website_id) on delete cascade,
			session_id int not null references session(session_id) on delete cascade,
			created_at timestamp with time zone default current_timestamp,
			url varchar(500) not null,
			event_type varchar(50) not null,
			event_value varchar(50) not null
		);
		
		create index website_user_id_idx on website(user_id);
		
		create index session_created_at_idx on session(created_at);
		create index session_website_id_idx on session(website_id);
		
		create index pageview_created_at_idx on pageview(created_at);
		create index pageview_website_id_idx on pageview(website_id);
		create index pageview_session_id_idx on pageview(session_id);
		create index pageview_website_id_created_at_idx on pageview(website_id, created_at);
		create index pageview_website_id_session_id_created_at_idx on pageview(website_id, session_id, created_at);
		
		create index event_created_at_idx on event(created_at);
		create index event_website_id_idx on event(website_id);
		create index event_session_id_idx on event(session_id);
		
		insert into account (username, password, is_admin) values ('admin', '${bcrypt.hashSync(
            umamiAdminPassword,
            10
        )}', true);`;
        await fs.writeFile(`${workdir}/schema.postgresql.sql`, initDbSQL);
        const Dockerfile = `
	  FROM ${config.postgresql.image}
	  COPY ./schema.postgresql.sql /docker-entrypoint-initdb.d/schema.postgresql.sql`;
        await fs.writeFile(`${workdir}/Dockerfile`, Dockerfile);
        const { volumes, volumeMounts } = persistentVolumes(id, persistentStorage, config.umami)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.umami.image,
                    environment: config.umami.environmentVariables,
                    networks: [network],
                    volumes,
                    restart: 'always',
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    labels: makeLabelForServices('umami'),
                    deploy: {
                        restart_policy: {
                            condition: 'on-failure',
                            delay: '5s',
                            max_attempts: 3,
                            window: '120s'
                        }
                    },
                    depends_on: [`${id}-postgresql`]
                },
                [`${id}-postgresql`]: {
                    build: workdir,
                    container_name: `${id}-postgresql`,
                    environment: config.postgresql.environmentVariables,
                    networks: [network],
                    volumes: [config.postgresql.volume],
                    restart: 'always',
                    deploy: {
                        restart_policy: {
                            condition: 'on-failure',
                            delay: '5s',
                            max_attempts: 3,
                            window: '120s'
                        }
                    }
                }
            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: {
                ...volumeMounts,
                [config.postgresql.volume.split(':')[0]]: {
                    name: config.postgresql.volume.split(':')[0]
                }
            }
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));
        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} pull` })
        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} up --build -d` })
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
async function stopUmamiService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const { destinationDockerId, destinationDocker } = service;
        if (destinationDockerId) {
            try {
                const found = await checkContainer({ dockerId: destinationDocker.id, container: id });
                if (found) {
                    await removeContainer({ id, dockerId: destinationDocker.id });
                }
            } catch (error) {
                console.error(error);
            }
            try {
                const found = await checkContainer({ dockerId: destinationDocker.id, container: `${id}-postgresql` });
                if (found) {
                    await removeContainer({ id: `${id}-postgresql`, dockerId: destinationDocker.id });
                }
            } catch (error) {
                console.error(error);
            }
        }
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

async function startHasuraService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const {
            type,
            version,
            destinationDockerId,
            destinationDocker,
            persistentStorage,
            serviceSecret,
            exposePort,
            hasura: { postgresqlUser, postgresqlPassword, postgresqlDatabase }
        } = service;
        const network = destinationDockerId && destinationDocker.network;
        const port = getServiceMainPort('hasura');

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);

        const config = {
            hasura: {
                image: `${image}:${version}`,
                environmentVariables: {
                    HASURA_GRAPHQL_METADATA_DATABASE_URL: `postgresql://${postgresqlUser}:${postgresqlPassword}@${id}-postgresql:5432/${postgresqlDatabase}`
                }
            },
            postgresql: {
                image: 'postgres:12-alpine',
                volume: `${id}-postgresql-data:/var/lib/postgresql/data`,
                environmentVariables: {
                    POSTGRES_USER: postgresqlUser,
                    POSTGRES_PASSWORD: postgresqlPassword,
                    POSTGRES_DB: postgresqlDatabase
                }
            }
        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.hasura.environmentVariables[secret.name] = secret.value;
            });
        }

        const { volumes, volumeMounts } = persistentVolumes(id, persistentStorage, config.hasura)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.hasura.image,
                    environment: config.hasura.environmentVariables,
                    networks: [network],
                    volumes,
                    restart: 'always',
                    labels: makeLabelForServices('hasura'),
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    deploy: {
                        restart_policy: {
                            condition: 'on-failure',
                            delay: '5s',
                            max_attempts: 3,
                            window: '120s'
                        }
                    },
                    depends_on: [`${id}-postgresql`]
                },
                [`${id}-postgresql`]: {
                    image: config.postgresql.image,
                    container_name: `${id}-postgresql`,
                    environment: config.postgresql.environmentVariables,
                    networks: [network],
                    volumes: [config.postgresql.volume],
                    restart: 'always',
                    deploy: {
                        restart_policy: {
                            condition: 'on-failure',
                            delay: '5s',
                            max_attempts: 3,
                            window: '120s'
                        }
                    }
                }
            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: {
                ...volumeMounts,
                [config.postgresql.volume.split(':')[0]]: {
                    name: config.postgresql.volume.split(':')[0]
                }
            }
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} pull` })
        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} up --build -d` })

        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
async function stopHasuraService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const { destinationDockerId, destinationDocker } = service;
        if (destinationDockerId) {
            try {
                const found = await checkContainer({ dockerId: destinationDocker.id, container: id });
                if (found) {
                    await removeContainer({ id, dockerId: destinationDocker.id });
                }
            } catch (error) {
                console.error(error);
            }
            try {
                const found = await checkContainer({ dockerId: destinationDocker.id, container: `${id}-postgresql` });
                if (found) {
                    await removeContainer({ id: `${id}-postgresql`, dockerId: destinationDocker.id });
                }
            } catch (error) {
                console.error(error);
            }
        }
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

async function startFiderService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const {
            type,
            version,
            fqdn,
            destinationDockerId,
            destinationDocker,
            serviceSecret,
            persistentStorage,
            exposePort,
            fider: {
                postgresqlUser,
                postgresqlPassword,
                postgresqlDatabase,
                jwtSecret,
                emailNoreply,
                emailMailgunApiKey,
                emailMailgunDomain,
                emailMailgunRegion,
                emailSmtpHost,
                emailSmtpPort,
                emailSmtpUser,
                emailSmtpPassword,
                emailSmtpEnableStartTls
            }
        } = service;
        const network = destinationDockerId && destinationDocker.network;
        const port = getServiceMainPort('fider');

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);
        const config = {
            fider: {
                image: `${image}:${version}`,
                environmentVariables: {
                    BASE_URL: fqdn,
                    DATABASE_URL: `postgresql://${postgresqlUser}:${postgresqlPassword}@${id}-postgresql:5432/${postgresqlDatabase}?sslmode=disable`,
                    JWT_SECRET: `${jwtSecret.replace(/\$/g, '$$$')}`,
                    EMAIL_NOREPLY: emailNoreply,
                    EMAIL_MAILGUN_API: emailMailgunApiKey,
                    EMAIL_MAILGUN_REGION: emailMailgunRegion,
                    EMAIL_MAILGUN_DOMAIN: emailMailgunDomain,
                    EMAIL_SMTP_HOST: emailSmtpHost,
                    EMAIL_SMTP_PORT: emailSmtpPort,
                    EMAIL_SMTP_USER: emailSmtpUser,
                    EMAIL_SMTP_PASSWORD: emailSmtpPassword,
                    EMAIL_SMTP_ENABLE_STARTTLS: emailSmtpEnableStartTls
                }
            },
            postgresql: {
                image: 'postgres:12-alpine',
                volume: `${id}-postgresql-data:/var/lib/postgresql/data`,
                environmentVariables: {
                    POSTGRES_USER: postgresqlUser,
                    POSTGRES_PASSWORD: postgresqlPassword,
                    POSTGRES_DB: postgresqlDatabase
                }
            }
        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.fider.environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumes, volumeMounts } = persistentVolumes(id, persistentStorage, config.fider)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.fider.image,
                    environment: config.fider.environmentVariables,
                    networks: [network],
                    volumes,
                    restart: 'always',
                    labels: makeLabelForServices('fider'),
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    deploy: {
                        restart_policy: {
                            condition: 'on-failure',
                            delay: '5s',
                            max_attempts: 3,
                            window: '120s'
                        }
                    },
                    depends_on: [`${id}-postgresql`]
                },
                [`${id}-postgresql`]: {
                    image: config.postgresql.image,
                    container_name: `${id}-postgresql`,
                    environment: config.postgresql.environmentVariables,
                    networks: [network],
                    volumes: [config.postgresql.volume],
                    restart: 'always',
                    deploy: {
                        restart_policy: {
                            condition: 'on-failure',
                            delay: '5s',
                            max_attempts: 3,
                            window: '120s'
                        }
                    }
                }
            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: {
                ...volumeMounts,
                [config.postgresql.volume.split(':')[0]]: {
                    name: config.postgresql.volume.split(':')[0]
                }
            }
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} pull` })
        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} up --build -d` })

        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
async function stopFiderService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const { destinationDockerId, destinationDocker } = service;
        if (destinationDockerId) {
            try {
                const found = await checkContainer({ dockerId: destinationDocker.id, container: id });
                if (found) {
                    await removeContainer({ id, dockerId: destinationDocker.id });
                }
            } catch (error) {
                console.error(error);
            }
            try {
                const found = await checkContainer({ dockerId: destinationDocker.id, container: `${id}-postgresql` });
                if (found) {
                    await removeContainer({ id: `${id}-postgresql`, dockerId: destinationDocker.id });
                }
            } catch (error) {
                console.error(error);
            }
        }
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
async function startAppWriteService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const { version, fqdn, destinationDocker, secrets, exposePort, network, port, workdir, image, appwrite } = await defaultServiceConfigurations({ id, teamId })

        let isStatsEnabled = false
        if (secrets._APP_USAGE_STATS) {
            isStatsEnabled = true
        }
        const {
            opensslKeyV1,
            executorSecret,
            mariadbHost,
            mariadbPort,
            mariadbUser,
            mariadbPassword,
            mariadbRootUser,
            mariadbRootUserPassword,
            mariadbDatabase
        } = appwrite;

        const dockerCompose = {
            [id]: {
                ...defaultServiceComposeConfiguration(network),
                image: `${image}:${version}`,
                container_name: id,
                labels: makeLabelForServices('appwrite'),
                ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                "volumes": [
                    `${id}-uploads:/storage/uploads:rw`,
                    `${id}-cache:/storage/cache:rw`,
                    `${id}-config:/storage/config:rw`,
                    `${id}-certificates:/storage/certificates:rw`,
                    `${id}-functions:/storage/functions:rw`
                ],
                "depends_on": [
                    `${id}-mariadb`,
                    `${id}-redis`,
                ],
                "environment": [
                    "_APP_ENV=production",
                    "_APP_LOCALE=en",
                    `_APP_OPENSSL_KEY_V1=${opensslKeyV1}`,
                    `_APP_DOMAIN=${fqdn}`,
                    `_APP_DOMAIN_TARGET=${fqdn}`,
                    `_APP_REDIS_HOST=${id}-redis`,
                    "_APP_REDIS_PORT=6379",
                    `_APP_DB_HOST=${mariadbHost}`,
                    `_APP_DB_PORT=${mariadbPort}`,
                    `_APP_DB_SCHEMA=${mariadbDatabase}`,
                    `_APP_DB_USER=${mariadbUser}`,
                    `_APP_DB_PASS=${mariadbPassword}`,
                    `_APP_INFLUXDB_HOST=${id}-influxdb`,
                    "_APP_INFLUXDB_PORT=8806",
                    `_APP_EXECUTOR_SECRET=${executorSecret}`,
                    `_APP_EXECUTOR_HOST=http://${id}-executor/v1`,
                    `_APP_STATSD_HOST=${id}-telegraf`,
                    "_APP_STATSD_PORT=8125",
                    ...secrets
                ]
            },
            [`${id}-realtime`]: {
                ...defaultServiceComposeConfiguration(network),
                image: `${image}:${version}`,
                container_name: `${id}-realtime`,
                entrypoint: "realtime",
                labels: makeLabelForServices('appwrite'),
                "depends_on": [
                    `${id}-mariadb`,
                    `${id}-redis`,
                ],
                "environment": [
                    "_APP_ENV=production",
                    `_APP_OPENSSL_KEY_V1=${opensslKeyV1}`,
                    `_APP_REDIS_HOST=${id}-redis`,
                    "_APP_REDIS_PORT=6379",
                    `_APP_DB_HOST=${mariadbHost}`,
                    `_APP_DB_PORT=${mariadbPort}`,
                    `_APP_DB_SCHEMA=${mariadbDatabase}`,
                    `_APP_DB_USER=${mariadbUser}`,
                    `_APP_DB_PASS=${mariadbPassword}`,
                    ...secrets
                ]
            },
            [`${id}-worker-audits`]: {
                ...defaultServiceComposeConfiguration(network),
                image: `${image}:${version}`,
                container_name: `${id}-worker-audits`,
                labels: makeLabelForServices('appwrite'),
                "entrypoint": "worker-audits",
                "depends_on": [
                    `${id}-mariadb`,
                    `${id}-redis`,
                ],
                "environment": [
                    "_APP_ENV=production",
                    `_APP_OPENSSL_KEY_V1=${opensslKeyV1}`,
                    `_APP_REDIS_HOST=${id}-redis`,
                    "_APP_REDIS_PORT=6379",
                    `_APP_DB_HOST=${mariadbHost}`,
                    `_APP_DB_PORT=${mariadbPort}`,
                    `_APP_DB_SCHEMA=${mariadbDatabase}`,
                    `_APP_DB_USER=${mariadbUser}`,
                    `_APP_DB_PASS=${mariadbPassword}`,
                    ...secrets
                ]
            },
            [`${id}-worker-webhooks`]: {
                ...defaultServiceComposeConfiguration(network),
                image: `${image}:${version}`,
                container_name: `${id}-worker-webhooks`,
                labels: makeLabelForServices('appwrite'),
                "entrypoint": "worker-webhooks",
                "depends_on": [
                    `${id}-mariadb`,
                    `${id}-redis`,
                ],
                "environment": [
                    "_APP_ENV=production",
                    `_APP_OPENSSL_KEY_V1=${opensslKeyV1}`,
                    `_APP_REDIS_HOST=${id}-redis`,
                    "_APP_REDIS_PORT=6379",
                    ...secrets
                ]
            },
            [`${id}-worker-deletes`]: {
                ...defaultServiceComposeConfiguration(network),
                image: `${image}:${version}`,
                container_name: `${id}-worker-deletes`,
                labels: makeLabelForServices('appwrite'),
                "entrypoint": "worker-deletes",
                "depends_on": [
                    `${id}-mariadb`,
                    `${id}-redis`,
                ],
                "volumes": [
                    `${id}-uploads:/storage/uploads:rw`,
                    `${id}-cache:/storage/cache:rw`,
                    `${id}-config:/storage/config:rw`,
                    `${id}-certificates:/storage/certificates:rw`,
                    `${id}-functions:/storage/functions:rw`,
                    `${id}-builds:/storage/builds:rw`,
                ],
                "environment": [
                    "_APP_ENV=production",
                    `_APP_OPENSSL_KEY_V1=${opensslKeyV1}`,
                    `_APP_REDIS_HOST=${id}-redis`,
                    "_APP_REDIS_PORT=6379",
                    `_APP_DB_HOST=${mariadbHost}`,
                    `_APP_DB_PORT=${mariadbPort}`,
                    `_APP_DB_SCHEMA=${mariadbDatabase}`,
                    `_APP_DB_USER=${mariadbUser}`,
                    `_APP_DB_PASS=${mariadbPassword}`,
                    `_APP_EXECUTOR_SECRET=${executorSecret}`,
                    `_APP_EXECUTOR_HOST=http://${id}-executor/v1`,
                    ...secrets
                ]
            },
            [`${id}-worker-databases`]: {
                ...defaultServiceComposeConfiguration(network),
                image: `${image}:${version}`,
                container_name: `${id}-worker-databases`,
                labels: makeLabelForServices('appwrite'),
                "entrypoint": "worker-databases",
                "depends_on": [
                    `${id}-mariadb`,
                    `${id}-redis`,
                ],
                "environment": [
                    "_APP_ENV=production",
                    `_APP_OPENSSL_KEY_V1=${opensslKeyV1}`,
                    `_APP_REDIS_HOST=${id}-redis`,
                    "_APP_REDIS_PORT=6379",
                    `_APP_DB_HOST=${mariadbHost}`,
                    `_APP_DB_PORT=${mariadbPort}`,
                    `_APP_DB_SCHEMA=${mariadbDatabase}`,
                    `_APP_DB_USER=${mariadbUser}`,
                    `_APP_DB_PASS=${mariadbPassword}`,
                    ...secrets
                ]
            },
            [`${id}-worker-builds`]: {
                ...defaultServiceComposeConfiguration(network),
                image: `${image}:${version}`,
                container_name: `${id}-worker-builds`,
                labels: makeLabelForServices('appwrite'),
                "entrypoint": "worker-builds",
                "depends_on": [
                    `${id}-mariadb`,
                    `${id}-redis`,
                ],
                "environment": [
                    "_APP_ENV=production",
                    `_APP_OPENSSL_KEY_V1=${opensslKeyV1}`,
                    `_APP_EXECUTOR_SECRET=${executorSecret}`,
                    `_APP_EXECUTOR_HOST=http://${id}-executor/v1`,
                    `_APP_REDIS_HOST=${id}-redis`,
                    "_APP_REDIS_PORT=6379",
                    `_APP_DB_HOST=${mariadbHost}`,
                    `_APP_DB_PORT=${mariadbPort}`,
                    `_APP_DB_SCHEMA=${mariadbDatabase}`,
                    `_APP_DB_USER=${mariadbUser}`,
                    `_APP_DB_PASS=${mariadbPassword}`,
                    ...secrets
                ]
            },
            [`${id}-worker-certificates`]: {
                ...defaultServiceComposeConfiguration(network),
                image: `${image}:${version}`,
                container_name: `${id}-worker-certificates`,
                labels: makeLabelForServices('appwrite'),
                "entrypoint": "worker-certificates",
                "depends_on": [
                    `${id}-mariadb`,
                    `${id}-redis`,
                ],
                "volumes": [
                    `${id}-config:/storage/config:rw`,
                    `${id}-certificates:/storage/certificates:rw`,
                ],
                "environment": [
                    "_APP_ENV=production",
                    `_APP_OPENSSL_KEY_V1=${opensslKeyV1}`,
                    `_APP_DOMAIN=${fqdn}`,
                    `_APP_DOMAIN_TARGET=${fqdn}`,
                    `_APP_REDIS_HOST=${id}-redis`,
                    "_APP_REDIS_PORT=6379",
                    `_APP_DB_HOST=${mariadbHost}`,
                    `_APP_DB_PORT=${mariadbPort}`,
                    `_APP_DB_SCHEMA=${mariadbDatabase}`,
                    `_APP_DB_USER=${mariadbUser}`,
                    `_APP_DB_PASS=${mariadbPassword}`,
                    ...secrets
                ]
            },
            [`${id}-worker-functions`]: {
                ...defaultServiceComposeConfiguration(network),
                image: `${image}:${version}`,
                container_name: `${id}-worker-functions`,
                labels: makeLabelForServices('appwrite'),
                "entrypoint": "worker-functions",
                "depends_on": [
                    `${id}-mariadb`,
                    `${id}-redis`,
                    `${id}-executor`
                ],
                "environment": [
                    "_APP_ENV=production",
                    `_APP_OPENSSL_KEY_V1=${opensslKeyV1}`,
                    `_APP_REDIS_HOST=${id}-redis`,
                    "_APP_REDIS_PORT=6379",
                    `_APP_DB_HOST=${mariadbHost}`,
                    `_APP_DB_PORT=${mariadbPort}`,
                    `_APP_DB_SCHEMA=${mariadbDatabase}`,
                    `_APP_DB_USER=${mariadbUser}`,
                    `_APP_DB_PASS=${mariadbPassword}`,
                    `_APP_EXECUTOR_SECRET=${executorSecret}`,
                    `_APP_EXECUTOR_HOST=http://${id}-executor/v1`,
                    ...secrets
                ]
            },
            [`${id}-executor`]: {
                ...defaultServiceComposeConfiguration(network),
                image: `${image}:${version}`,
                container_name: `${id}-executor`,
                labels: makeLabelForServices('appwrite'),
                "entrypoint": "executor",
                "stop_signal": "SIGINT",
                "volumes": [
                    `${id}-functions:/storage/functions:rw`,
                    `${id}-builds:/storage/builds:rw`,
                    "/var/run/docker.sock:/var/run/docker.sock",
                    "/tmp:/tmp:rw"
                ],
                "depends_on": [
                    `${id}-mariadb`,
                    `${id}-redis`,
                    `${id}`
                ],
                "environment": [
                    "_APP_ENV=production",
                    `_APP_EXECUTOR_SECRET=${executorSecret}`,
                    ...secrets
                ]
            },
            [`${id}-worker-mails`]: {
                ...defaultServiceComposeConfiguration(network),
                image: `${image}:${version}`,
                container_name: `${id}-worker-mails`,
                labels: makeLabelForServices('appwrite'),
                "entrypoint": "worker-mails",
                "depends_on": [
                    `${id}-redis`,
                ],
                "environment": [
                    "_APP_ENV=production",
                    `_APP_OPENSSL_KEY_V1=${opensslKeyV1}`,
                    `_APP_REDIS_HOST=${id}-redis`,
                    "_APP_REDIS_PORT=6379",
                    ...secrets
                ]
            },
            [`${id}-worker-messaging`]: {
                ...defaultServiceComposeConfiguration(network),
                image: `${image}:${version}`,
                container_name: `${id}-worker-messaging`,
                labels: makeLabelForServices('appwrite'),
                "entrypoint": "worker-messaging",
                "depends_on": [
                    `${id}-redis`,
                ],
                "environment": [
                    "_APP_ENV=production",
                    `_APP_REDIS_HOST=${id}-redis`,
                    "_APP_REDIS_PORT=6379",
                    ...secrets
                ]
            },
            [`${id}-maintenance`]: {
                ...defaultServiceComposeConfiguration(network),
                image: `${image}:${version}`,
                container_name: `${id}-maintenance`,
                labels: makeLabelForServices('appwrite'),
                "entrypoint": "maintenance",
                "depends_on": [
                    `${id}-redis`,
                ],
                "environment": [
                    "_APP_ENV=production",
                    `_APP_OPENSSL_KEY_V1=${opensslKeyV1}`,
                    `_APP_DOMAIN=${fqdn}`,
                    `_APP_DOMAIN_TARGET=${fqdn}`,
                    `_APP_REDIS_HOST=${id}-redis`,
                    "_APP_REDIS_PORT=6379",
                    `_APP_DB_HOST=${mariadbHost}`,
                    `_APP_DB_PORT=${mariadbPort}`,
                    `_APP_DB_SCHEMA=${mariadbDatabase}`,
                    `_APP_DB_USER=${mariadbUser}`,
                    `_APP_DB_PASS=${mariadbPassword}`,
                    ...secrets
                ]
            },
            [`${id}-schedule`]: {
                ...defaultServiceComposeConfiguration(network),
                image: `${image}:${version}`,
                container_name: `${id}-schedule`,
                labels: makeLabelForServices('appwrite'),
                "entrypoint": "schedule",
                "depends_on": [
                    `${id}-redis`,
                ],
                "environment": [
                    "_APP_ENV=production",
                    `_APP_REDIS_HOST=${id}-redis`,
                    "_APP_REDIS_PORT=6379",
                    ...secrets
                ]
            },
            [`${id}-mariadb`]: {
                ...defaultServiceComposeConfiguration(network),
                "image": "mariadb:10.7",
                container_name: `${id}-mariadb`,
                labels: makeLabelForServices('appwrite'),
                "volumes": [
                    `${id}-mariadb:/var/lib/mysql:rw`
                ],
                "environment": [
                    `MYSQL_ROOT_USER=${mariadbRootUser}`,
                    `MYSQL_ROOT_PASSWORD=${mariadbRootUserPassword}`,
                    `MYSQL_USER=${mariadbUser}`,
                    `MYSQL_PASSWORD=${mariadbPassword}`,
                    `MYSQL_DATABASE=${mariadbDatabase}`
                ],
                "command": "mysqld --innodb-flush-method=fsync"
            },
            [`${id}-redis`]: {
                ...defaultServiceComposeConfiguration(network),
                "image": "redis:6.2-alpine",
                container_name: `${id}-redis`,
                "command": `redis-server --maxmemory 512mb --maxmemory-policy allkeys-lru --maxmemory-samples 5\n`,
                "volumes": [
                    `${id}-redis:/data:rw`
                ]
            },

        };
        if (isStatsEnabled) {
            dockerCompose.id.depends_on.push(`${id}-influxdb`);
            dockerCompose[`${id}-usage`] = {
                ...defaultServiceComposeConfiguration(network),
                image: `${image}:${version}`,
                container_name: `${id}-usage`,
                labels: makeLabelForServices('appwrite'),
                "entrypoint": "usage",
                "depends_on": [
                    `${id}-mariadb`,
                    `${id}-influxdb`,
                ],
                "environment": [
                    "_APP_ENV=production",
                    `_APP_OPENSSL_KEY_V1=${opensslKeyV1}`,
                    `_APP_DB_HOST=${mariadbHost}`,
                    `_APP_DB_PORT=${mariadbPort}`,
                    `_APP_DB_SCHEMA=${mariadbDatabase}`,
                    `_APP_DB_USER=${mariadbUser}`,
                    `_APP_DB_PASS=${mariadbPassword}`,
                    `_APP_INFLUXDB_HOST=${id}-influxdb`,
                    "_APP_INFLUXDB_PORT=8806",
                    `_APP_REDIS_HOST=${id}-redis`,
                    "_APP_REDIS_PORT=6379",
                    ...secrets
                ]
            }
            dockerCompose[`${id}-influxdb`] = {
                ...defaultServiceComposeConfiguration(network),
                "image": "appwrite/influxdb:1.5.0",
                container_name: `${id}-influxdb`,
                "volumes": [
                    `${id}-influxdb:/var/lib/influxdb:rw`
                ]
            }
            dockerCompose[`${id}-telegraf`] = {
                ...defaultServiceComposeConfiguration(network),
                "image": "appwrite/telegraf:1.4.0",
                container_name: `${id}-telegraf`,
                "environment": [
                    `_APP_INFLUXDB_HOST=${id}-influxdb`,
                    "_APP_INFLUXDB_PORT=8806",
                ]
            }
        }

        const composeFile: any = {
            version: '3.8',
            services: dockerCompose,
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: {
                [`${id}-uploads`]: {
                    name: `${id}-uploads`
                },
                [`${id}-cache`]: {
                    name: `${id}-cache`
                },
                [`${id}-config`]: {
                    name: `${id}-config`
                },
                [`${id}-certificates`]: {
                    name: `${id}-certificates`
                },
                [`${id}-functions`]: {
                    name: `${id}-functions`
                },
                [`${id}-builds`]: {
                    name: `${id}-builds`
                },
                [`${id}-mariadb`]: {
                    name: `${id}-mariadb`
                },
                [`${id}-redis`]: {
                    name: `${id}-redis`
                },
                [`${id}-influxdb`]: {
                    name: `${id}-influxdb`
                }
            }

        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} pull` })
        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} up --build -d` })

        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
async function stopAppWriteService(request: FastifyRequest<ServiceStartStop>) {
    try {
        // TODO: Fix async for of
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const { destinationDockerId, destinationDocker } = service;
        const containers = [`${id}-mariadb`, `${id}-redis`, `${id}-influxdb`, `${id}-telegraf`, id, `${id}-realtime`, `${id}-worker-audits`, `${id}worker-webhooks`, `${id}-worker-deletes`, `${id}-worker-databases`, `${id}-worker-builds`, `${id}-worker-certificates`, `${id}-worker-functions`, `${id}-worker-mails`, `${id}-worker-messaging`, `${id}-maintenance`, `${id}-schedule`, `${id}-executor`, `${id}-usage`]
        if (destinationDockerId) {
            for (const container of containers) {
                const found = await checkContainer({ dockerId: destinationDocker.id, container });
                if (found) {
                    await removeContainer({ id, dockerId: destinationDocker.id });
                }

            }
        }
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
async function startMoodleService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const {
            type,
            version,
            fqdn,
            destinationDockerId,
            destinationDocker,
            serviceSecret,
            persistentStorage,
            exposePort,
            moodle: {
                defaultUsername,
                defaultPassword,
                defaultEmail,
                mariadbRootUser,
                mariadbRootUserPassword,
                mariadbDatabase,
                mariadbPassword,
                mariadbUser
            }
        } = service;
        const network = destinationDockerId && destinationDocker.network;
        const port = getServiceMainPort('moodle');

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);
        const config = {
            moodle: {
                image: `${image}:${version}`,
                volume: `${id}-data:/bitnami/moodle`,
                environmentVariables: {
                    MOODLE_USERNAME: defaultUsername,
                    MOODLE_PASSWORD: defaultPassword,
                    MOODLE_EMAIL: defaultEmail,
                    MOODLE_DATABASE_HOST: `${id}-mariadb`,
                    MOODLE_DATABASE_USER: mariadbUser,
                    MOODLE_DATABASE_PASSWORD: mariadbPassword,
                    MOODLE_DATABASE_NAME: mariadbDatabase,
                    MOODLE_REVERSEPROXY: 'yes'
                }
            },
            mariadb: {
                image: 'bitnami/mariadb:latest',
                volume: `${id}-mariadb-data:/bitnami/mariadb`,
                environmentVariables: {
                    MARIADB_USER: mariadbUser,
                    MARIADB_PASSWORD: mariadbPassword,
                    MARIADB_DATABASE: mariadbDatabase,
                    MARIADB_ROOT_USER: mariadbRootUser,
                    MARIADB_ROOT_PASSWORD: mariadbRootUserPassword
                }
            }
        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.moodle.environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumes, volumeMounts } = persistentVolumes(id, persistentStorage, config.moodle)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.moodle.image,
                    environment: config.moodle.environmentVariables,
                    networks: [network],
                    volumes,
                    restart: 'always',
                    labels: makeLabelForServices('moodle'),
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    deploy: {
                        restart_policy: {
                            condition: 'on-failure',
                            delay: '5s',
                            max_attempts: 3,
                            window: '120s'
                        }
                    },
                    depends_on: [`${id}-mariadb`]
                },
                [`${id}-mariadb`]: {
                    container_name: `${id}-mariadb`,
                    image: config.mariadb.image,
                    environment: config.mariadb.environmentVariables,
                    networks: [network],
                    volumes: [],
                    restart: 'always',
                    deploy: {
                        restart_policy: {
                            condition: 'on-failure',
                            delay: '5s',
                            max_attempts: 3,
                            window: '120s'
                        }
                    },
                    depends_on: []
                }

            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: {
                ...volumeMounts,
                [config.mariadb.volume.split(':')[0]]: {
                    name: config.mariadb.volume.split(':')[0]
                }
            }

        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} pull` })
        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} up --build -d` })

        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
async function stopMoodleService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const { destinationDockerId, destinationDocker } = service;
        if (destinationDockerId) {
            try {
                const found = await checkContainer({ dockerId: destinationDocker.id, container: id });
                if (found) {
                    await removeContainer({ id, dockerId: destinationDocker.id });
                }
            } catch (error) {
                console.error(error);
            }
            try {
                const found = await checkContainer({ dockerId: destinationDocker.id, container: `${id}-mariadb` });
                if (found) {
                    await removeContainer({ id: `${id}-mariadb`, dockerId: destinationDocker.id });
                }
            } catch (error) {
                console.error(error);
            }
        }
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

async function startGlitchTipService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const {
            type,
            version,
            fqdn,
            destinationDockerId,
            destinationDocker,
            serviceSecret,
            persistentStorage,
            exposePort,
            glitchTip: {
                postgresqlDatabase,
                postgresqlPassword,
                postgresqlUser,
                secretKeyBase,
                defaultEmail,
                defaultUsername,
                defaultPassword,
                defaultFromEmail,
                emailSmtpHost,
                emailSmtpPort,
                emailSmtpUser,
                emailSmtpPassword,
                emailSmtpUseTls,
                emailSmtpUseSsl,
                emailBackend,
                mailgunApiKey,
                sendgridApiKey,
                enableOpenUserRegistration,
            }
        } = service;
        const network = destinationDockerId && destinationDocker.network;
        const port = getServiceMainPort('glitchTip');

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);

        const config = {
            glitchTip: {
                image: `${image}:${version}`,
                environmentVariables: {
                    PORT: port,
                    GLITCHTIP_DOMAIN: fqdn,
                    SECRET_KEY: secretKeyBase,
                    DATABASE_URL: `postgresql://${postgresqlUser}:${postgresqlPassword}@${id}-postgresql:5432/${postgresqlDatabase}`,
                    REDIS_URL: `redis://${id}-redis:6379/0`,
                    DEFAULT_FROM_EMAIL: defaultFromEmail,
                    EMAIL_HOST: emailSmtpHost,
                    EMAIL_PORT: emailSmtpPort,
                    EMAIL_HOST_USER: emailSmtpUser,
                    EMAIL_HOST_PASSWORD: emailSmtpPassword,
                    EMAIL_USE_TLS: emailSmtpUseTls,
                    EMAIL_USE_SSL: emailSmtpUseSsl,
                    EMAIL_BACKEND: emailBackend,
                    MAILGUN_API_KEY: mailgunApiKey,
                    SENDGRID_API_KEY: sendgridApiKey,
                    ENABLE_OPEN_USER_REGISTRATION: enableOpenUserRegistration,
                    DJANGO_SUPERUSER_EMAIL: defaultEmail,
                    DJANGO_SUPERUSER_USERNAME: defaultUsername,
                    DJANGO_SUPERUSER_PASSWORD: defaultPassword,
                }
            },
            postgresql: {
                image: 'postgres:14-alpine',
                volume: `${id}-postgresql-data:/var/lib/postgresql/data`,
                environmentVariables: {
                    POSTGRES_USER: postgresqlUser,
                    POSTGRES_PASSWORD: postgresqlPassword,
                    POSTGRES_DB: postgresqlDatabase
                }
            },
            redis: {
                image: 'redis:7-alpine',
                volume: `${id}-redis-data:/data`,
            }
        };
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                config.glitchTip.environmentVariables[secret.name] = secret.value;
            });
        }
        const { volumes, volumeMounts } = persistentVolumes(id, persistentStorage, config.glitchTip)
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.glitchTip.image,
                    environment: config.glitchTip.environmentVariables,
                    networks: [network],
                    volumes,
                    restart: 'always',
                    labels: makeLabelForServices('glitchTip'),
                    ...(exposePort ? { ports: [`${exposePort}:${port}`] } : {}),
                    deploy: {
                        restart_policy: {
                            condition: 'on-failure',
                            delay: '5s',
                            max_attempts: 3,
                            window: '120s'
                        }
                    },
                    depends_on: [`${id}-postgresql`, `${id}-redis`]
                },
                [`${id}-worker`]: {
                    container_name: `${id}-worker`,
                    image: config.glitchTip.image,
                    command: './bin/run-celery-with-beat.sh',
                    environment: config.glitchTip.environmentVariables,
                    networks: [network],
                    restart: 'always',
                    deploy: {
                        restart_policy: {
                            condition: 'on-failure',
                            delay: '5s',
                            max_attempts: 3,
                            window: '120s'
                        }
                    },
                    depends_on: [`${id}-postgresql`, `${id}-redis`]
                },
                [`${id}-setup`]: {
                    container_name: `${id}-setup`,
                    image: config.glitchTip.image,
                    command: 'sh -c "(./manage.py migrate || true) && (./manage.py createsuperuser --noinput || true)"',
                    environment: config.glitchTip.environmentVariables,
                    networks: [network],
                    restart: "no",
                    depends_on: [`${id}-postgresql`, `${id}-redis`]
                },
                [`${id}-postgresql`]: {
                    image: config.postgresql.image,
                    container_name: `${id}-postgresql`,
                    environment: config.postgresql.environmentVariables,
                    networks: [network],
                    volumes: [config.postgresql.volume],
                    restart: 'always',
                    deploy: {
                        restart_policy: {
                            condition: 'on-failure',
                            delay: '5s',
                            max_attempts: 3,
                            window: '120s'
                        }
                    }
                },
                [`${id}-redis`]: {
                    image: config.redis.image,
                    container_name: `${id}-redis`,
                    networks: [network],
                    volumes: [config.redis.volume],
                    restart: 'always',
                    deploy: {
                        restart_policy: {
                            condition: 'on-failure',
                            delay: '5s',
                            max_attempts: 3,
                            window: '120s'
                        }
                    }
                }
            },
            networks: {
                [network]: {
                    external: true
                }
            },
            volumes: {
                ...volumeMounts,
                [config.postgresql.volume.split(':')[0]]: {
                    name: config.postgresql.volume.split(':')[0]
                },
                [config.redis.volume.split(':')[0]]: {
                    name: config.redis.volume.split(':')[0]
                }
            }
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} pull` })
        await executeDockerCmd({ dockerId: destinationDocker.id, command: `docker compose -f ${composeFileDestination} up --build -d` })

        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
async function stopGlitchTipService(request: FastifyRequest<ServiceStartStop>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const { destinationDockerId, destinationDocker } = service;
        if (destinationDockerId) {
            try {
                const found = await checkContainer({ dockerId: destinationDocker.id, container: id });
                if (found) {
                    await removeContainer({ id, dockerId: destinationDocker.id });
                }
            } catch (error) {
                console.error(error);
            }
            try {
                const found = await checkContainer({ dockerId: destinationDocker.id, container: `${id}-worker` });
                if (found) {
                    await removeContainer({ id: `${id}-worker`, dockerId: destinationDocker.id });
                }
            } catch (error) {
                console.error(error);
            }
            try {
                const found = await checkContainer({ dockerId: destinationDocker.id, container: `${id}-setup` });
                if (found) {
                    await removeContainer({ id: `${id}-setup`, dockerId: destinationDocker.id });
                }
            } catch (error) {
                console.error(error);
            }
            try {
                const found = await checkContainer({ dockerId: destinationDocker.id, container: `${id}-postgresql` });
                if (found) {
                    await removeContainer({ id: `${id}-postgresql`, dockerId: destinationDocker.id });
                }
            } catch (error) {
                console.error(error);
            }
            try {
                const found = await checkContainer({ dockerId: destinationDocker.id, container: `${id}-redis` });
                if (found) {
                    await removeContainer({ id: `${id}-redis`, dockerId: destinationDocker.id });
                }
            } catch (error) {
                console.error(error);
            }
        }
        return {}
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
    let ftpPassword = generatePassword();

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
