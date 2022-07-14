import type { FastifyReply, FastifyRequest } from 'fastify';
import fs from 'fs/promises';
import yaml from 'js-yaml';
import bcrypt from 'bcryptjs';
import { prisma, uniqueName, asyncExecShell, getServiceImage, getServiceImages, configureServiceType, getServiceFromDB, getContainerUsage, removeService, isDomainConfigured, saveUpdateableFields, fixType, decrypt, encrypt, getServiceMainPort, createDirectories, ComposeFile, makeLabelForServices, getFreePort, getDomain, errorHandler, supportedServiceTypesAndVersions, generatePassword, isDev, stopTcpHttpProxy, getAvailableServices, convertTolOldVolumeNames } from '../../../../lib/common';
import { day } from '../../../../lib/dayjs';
import { checkContainer, dockerInstance, getEngine, removeContainer } from '../../../../lib/docker';
import cuid from 'cuid';

import type { OnlyId } from '../../../../types';
import type { ActivateWordpressFtp, CheckService, DeleteServiceSecret, DeleteServiceStorage, GetServiceLogs, SaveService, SaveServiceDestination, SaveServiceSecret, SaveServiceSettings, SaveServiceStorage, SaveServiceType, SaveServiceVersion, ServiceStartStop, SetWordpressSettings } from './types';

async function startServiceNew(request: FastifyRequest<OnlyId>) {
    try {
        const { id } = request.params;
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        const { type, version, destinationDockerId, destinationDocker, serviceSecret, exposePort } =
            service;
        const network = destinationDockerId && destinationDocker.network;
        const host = getEngine(destinationDocker.engine);
        const port = getServiceMainPort(type);

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);
        const config = (await getAvailableServices()).find((name) => name.name === type).compose
        const environmentVariables = {}
        if (serviceSecret.length > 0) {
            serviceSecret.forEach((secret) => {
                environmentVariables[secret.name] = secret.value;
            });
        }
        config.services[id] = JSON.parse(JSON.stringify(config.services[type]))
        config.services[id].container_name = id
        config.services[id].image = `${image}:${version}`
        config.services[id].ports = (exposePort ? [`${exposePort}:${port}`] : []),
            config.services[id].restart = "always"
        config.services[id].networks = [network]
        config.services[id].labels = makeLabelForServices(type)
        config.services[id].deploy = {
            restart_policy: {
                condition: 'on-failure',
                delay: '5s',
                max_attempts: 3,
                window: '120s'
            }
        }
        config.networks = {
            [network]: {
                external: true
            }
        }
        config.volumes = {}
        config.services[id].volumes.forEach((volume, index) => {
            let oldVolumeName = volume.split(':')[0]
            const path = volume.split(':')[1]
            oldVolumeName = convertTolOldVolumeNames(type)
            const volumeName = `${id}-${oldVolumeName}`
            config.volumes[volumeName] = {
                name: volumeName
            }
            config.services[id].volumes[index] = `${volumeName}:${path}`
        })
        delete config.services[type]
        config.services[id].environment = environmentVariables
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(config));
        await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} pull`);
        await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} up -d`);
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}


export async function listServices(request: FastifyRequest) {
    try {
        const teamId = request.user.teamId;
        let services = []
        if (teamId === '0') {
            services = await prisma.service.findMany({ include: { teams: true } });
        } else {
            services = await prisma.service.findMany({
                where: { teams: { some: { id: teamId } } },
                include: { teams: true }
            });
        }
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
export async function getService(request: FastifyRequest<OnlyId>) {
    try {
        const teamId = request.user.teamId;
        const { id } = request.params;
        const service = await getServiceFromDB({ id, teamId });

        if (!service) {
            throw { status: 404, message: 'Service not found.' }
        }

        const { destinationDockerId, destinationDocker, type, version, settings } = service;
        let isRunning = false;
        if (destinationDockerId) {
            const host = getEngine(destinationDocker.engine);
            const docker = dockerInstance({ destinationDocker });
            const baseImage = getServiceImage(type);
            const images = getServiceImages(type);
            docker.engine.pull(`${baseImage}:${version}`);
            if (images?.length > 0) {
                for (const image of images) {
                    docker.engine.pull(`${image}:latest`);
                }
            }
            try {
                const { stdout } = await asyncExecShell(
                    `DOCKER_HOST=${host} docker inspect --format '{{json .State}}' ${id}`
                );

                if (JSON.parse(stdout).Running) {
                    isRunning = true;
                }
            } catch (error) {
                //
            }
        }
        return {
            isRunning,
            service,
            settings
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
        const teamId = request.user.teamId;
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
            [usage] = await Promise.all([getContainerUsage(service.destinationDocker.engine, id)]);
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
        const { destinationDockerId, destinationDocker } = await prisma.service.findUnique({
            where: { id },
            include: { destinationDocker: true }
        });
        if (destinationDockerId) {
            const docker = dockerInstance({ destinationDocker });
            try {
                const container = await docker.engine.getContainer(id);
                if (container) {
                    const { default: ansi } = await import('strip-ansi')
                    const logs = (
                        await container.logs({
                            stdout: true,
                            stderr: true,
                            timestamps: true,
                            since,
                            tail: 5000
                        })
                    )
                        .toString()
                        .split('\n')
                        .map((l) => ansi(l.slice(8)))
                        .filter((a) => a);
                    return {
                        logs
                    };
                }
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
export async function checkService(request: FastifyRequest<CheckService>) {
    try {
        const { id } = request.params;
        let { fqdn, exposePort, otherFqdns } = request.body;

        if (fqdn) fqdn = fqdn.toLowerCase();
        if (otherFqdns && otherFqdns.length > 0) otherFqdns = otherFqdns.map((f) => f.toLowerCase());
        if (exposePort) exposePort = Number(exposePort);

        let found = await isDomainConfigured({ id, fqdn });
        if (found) {
            throw { status: 500, message: `Domain ${getDomain(fqdn).replace('www.', '')} is already in use!` }
        }
        if (otherFqdns && otherFqdns.length > 0) {
            for (const ofqdn of otherFqdns) {
                found = await isDomainConfigured({ id, fqdn: ofqdn, checkOwn: true });
                if (found) {
                    throw { status: 500, message: `Domain ${getDomain(ofqdn).replace('www.', '')} is already in use!` }
                }
            }
        }
        if (exposePort) {
            const { default: getPort } = await import('get-port');
            exposePort = Number(exposePort);

            if (exposePort < 1024 || exposePort > 65535) {
                throw { status: 500, message: `Exposed Port needs to be between 1024 and 65535.` }
            }

            const publicPort = await getPort({ port: exposePort });
            if (publicPort !== exposePort) {
                throw { status: 500, message: `Port ${exposePort} is already in use.` }
            }
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
        const host = getEngine(destinationDocker.engine);
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
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.plausibleAnalytics.image,
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
        await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} pull`);
        await asyncExecShell(
            `DOCKER_HOST=${host} docker compose -f ${composeFileDestination} up --build -d`
        );
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

            let found = await checkContainer(engine, id);
            if (found) {
                await removeContainer({ id, engine });
            }
            found = await checkContainer(engine, `${id}-postgresql`);
            if (found) {
                await removeContainer({ id: `${id}-postgresql`, engine });
            }
            found = await checkContainer(engine, `${id}-clickhouse`);
            if (found) {
                await removeContainer({ id: `${id}-clickhouse`, engine });
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
        const { type, version, destinationDockerId, destinationDocker, serviceSecret, exposePort } =
            service;
        const network = destinationDockerId && destinationDocker.network;
        const host = getEngine(destinationDocker.engine);
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
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.image,
                    networks: [network],
                    volumes: [config.volume],
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
            volumes: {
                [config.volume.split(':')[0]]: {
                    name: config.volume.split(':')[0]
                }
            }
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));
        await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} pull`);
        await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} up -d`);
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
            const engine = destinationDocker.engine;
            const found = await checkContainer(engine, id);
            if (found) {
                await removeContainer({ id, engine });
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
            exposePort,
            minio: { rootUser, rootUserPassword },
            serviceSecret
        } = service;

        const network = destinationDockerId && destinationDocker.network;
        const host = getEngine(destinationDocker.engine);
        const port = getServiceMainPort('minio');

        const publicPort = await getFreePort();

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
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.image,
                    command: `server /data --console-address ":${consolePort}"`,
                    environment: config.environmentVariables,
                    networks: [network],
                    volumes: [config.volume],
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
            volumes: {
                [config.volume.split(':')[0]]: {
                    name: config.volume.split(':')[0]
                }
            }
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));
        await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} pull`);
        await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} up -d`);
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
        const { destinationDockerId, destinationDocker, fqdn } = service;
        await prisma.minio.update({ where: { serviceId: id }, data: { publicPort: null } })
        if (destinationDockerId) {
            const engine = destinationDocker.engine;
            const found = await checkContainer(engine, id);
            if (found) {
                await removeContainer({ id, engine });
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
        const host = getEngine(destinationDocker.engine);
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

        const volumes =
            persistentStorage?.map((storage) => {
                return `${id}${storage.path.replace(/\//gi, '-')}:${storage.path}`;
            }) || [];

        const composeVolumes = volumes.map((volume) => {
            return {
                [`${volume.split(':')[0]}`]: {
                    name: volume.split(':')[0]
                }
            };
        });
        const volumeMounts = Object.assign(
            {},
            {
                [config.volume.split(':')[0]]: {
                    name: config.volume.split(':')[0]
                }
            },
            ...composeVolumes
        );
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.image,
                    environment: config.environmentVariables,
                    networks: [network],
                    volumes: [config.volume, ...volumes],
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

        await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} pull`);
        await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} up -d`);

        const changePermissionOn = persistentStorage.map((p) => p.path);
        if (changePermissionOn.length > 0) {
            await asyncExecShell(
                `DOCKER_HOST=${host} docker exec -u root ${id} chown -R 1000:1000 ${changePermissionOn.join(
                    ' '
                )}`
            );
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
        const { destinationDockerId, destinationDocker, fqdn } = service;
        if (destinationDockerId) {
            const engine = destinationDocker.engine;
            const found = await checkContainer(engine, id);
            if (found) {
                await removeContainer({ id, engine });
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
        const host = getEngine(destinationDocker.engine);
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
        let composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.wordpress.image,
                    environment: config.wordpress.environmentVariables,
                    volumes: [config.wordpress.volume],
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
            volumes: {
                [config.wordpress.volume.split(':')[0]]: {
                    name: config.wordpress.volume.split(':')[0]
                }
            }
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
        await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} pull`);
        await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} up -d`);
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
            const engine = destinationDocker.engine;
            try {
                const found = await checkContainer(engine, id);
                if (found) {
                    await removeContainer({ id, engine });
                }
            } catch (error) {
                console.error(error);
            }
            try {
                const found = await checkContainer(engine, `${id}-mysql`);
                if (found) {
                    await removeContainer({ id: `${id}-mysql`, engine });
                }
            } catch (error) {
                console.error(error);
            }
            try {
                if (ftpEnabled) {
                    const found = await checkContainer(engine, `${id}-ftp`);
                    if (found) {
                        await removeContainer({ id: `${id}-ftp`, engine });
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
        const { type, version, destinationDockerId, destinationDocker, serviceSecret, exposePort } =
            service;

        const network = destinationDockerId && destinationDocker.network;
        const host = getEngine(destinationDocker.engine);
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
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.image,
                    environment: config.environmentVariables,
                    networks: [network],
                    volumes: [config.volume],
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
            volumes: {
                [config.volume.split(':')[0]]: {
                    name: config.volume.split(':')[0]
                }
            }
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));
        await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} pull`);
        await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} up -d`);
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
        const { destinationDockerId, destinationDocker, fqdn } = service;
        if (destinationDockerId) {
            const engine = destinationDocker.engine;

            try {
                const found = await checkContainer(engine, id);
                if (found) {
                    await removeContainer({ id, engine });
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
        const { type, version, destinationDockerId, destinationDocker, serviceSecret, exposePort } =
            service;
        const network = destinationDockerId && destinationDocker.network;
        const host = getEngine(destinationDocker.engine);
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
                    volumes: [config.volume],
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
            volumes: {
                [config.volume.split(':')[0]]: {
                    name: config.volume.split(':')[0]
                }
            }
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

        await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} pull`);
        await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} up -d`);
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
        const { destinationDockerId, destinationDocker, fqdn } = service;
        if (destinationDockerId) {
            const engine = destinationDocker.engine;

            try {
                const found = await checkContainer(engine, id);
                if (found) {
                    await removeContainer({ id, engine });
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
        const { type, version, destinationDockerId, destinationDocker, serviceSecret, exposePort } =
            service;
        const network = destinationDockerId && destinationDocker.network;
        const host = getEngine(destinationDocker.engine);
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
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.image,
                    networks: [network],
                    volumes: [config.volume],
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
            volumes: {
                [config.volume.split(':')[0]]: {
                    name: config.volume.split(':')[0]
                }
            }
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));
        await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} pull`);
        await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} up -d`);
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
        const { destinationDockerId, destinationDocker, fqdn } = service;
        if (destinationDockerId) {
            const engine = destinationDocker.engine;

            try {
                const found = await checkContainer(engine, id);
                if (found) {
                    await removeContainer({ id, engine });
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
        const { type, version, destinationDockerId, destinationDocker, serviceSecret, exposePort } =
            service;
        const network = destinationDockerId && destinationDocker.network;
        const host = getEngine(destinationDocker.engine);
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
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.image,
                    networks: [network],
                    volumes: [config.volume],
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
            volumes: {
                [config.volume.split(':')[0]]: {
                    name: config.volume.split(':')[0]
                }
            }
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

        await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} pull`);
        await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} up -d`);
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
        const { destinationDockerId, destinationDocker, fqdn } = service;
        if (destinationDockerId) {
            const engine = destinationDocker.engine;

            try {
                const found = await checkContainer(engine, id);
                if (found) {
                    await removeContainer({ id, engine });
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
        const host = getEngine(destinationDocker.engine);

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
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.ghost.image,
                    networks: [network],
                    volumes: [config.ghost.volume],
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
                [config.ghost.volume.split(':')[0]]: {
                    name: config.ghost.volume.split(':')[0]
                },
                [config.mariadb.volume.split(':')[0]]: {
                    name: config.mariadb.volume.split(':')[0]
                }
            }
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

        await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} pull`);
        await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} up -d`);
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
        const { destinationDockerId, destinationDocker, fqdn } = service;
        if (destinationDockerId) {
            const engine = destinationDocker.engine;

            try {
                let found = await checkContainer(engine, id);
                if (found) {
                    await removeContainer({ id, engine });
                }
                found = await checkContainer(engine, `${id}-mariadb`);
                if (found) {
                    await removeContainer({ id: `${id}-mariadb`, engine });
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
        const { type, version, destinationDockerId, destinationDocker, serviceSecret, exposePort } =
            service;
        const network = destinationDockerId && destinationDocker.network;
        const host = getEngine(destinationDocker.engine);
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
                    volumes: [config.volume],
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
            volumes: {
                [config.volume.split(':')[0]]: {
                    name: config.volume.split(':')[0]
                }
            }
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

        await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} pull`);
        await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} up -d`);
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
        const { destinationDockerId, destinationDocker, fqdn } = service;
        if (destinationDockerId) {
            const engine = destinationDocker.engine;

            try {
                const found = await checkContainer(engine, id);
                if (found) {
                    await removeContainer({ id, engine });
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
        const host = getEngine(destinationDocker.engine);
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
        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.umami.image,
                    environment: config.umami.environmentVariables,
                    networks: [network],
                    volumes: [],
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
                [config.postgresql.volume.split(':')[0]]: {
                    name: config.postgresql.volume.split(':')[0]
                }
            }
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

        await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} pull`);
        await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} up -d`);
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
        const { destinationDockerId, destinationDocker, fqdn } = service;
        if (destinationDockerId) {
            const engine = destinationDocker.engine;

            try {
                const found = await checkContainer(engine, id);
                if (found) {
                    await removeContainer({ id, engine });
                }
            } catch (error) {
                console.error(error);
            }
            try {
                const found = await checkContainer(engine, `${id}-postgresql`);
                if (found) {
                    await removeContainer({ id: `${id}-postgresql`, engine });
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
            serviceSecret,
            exposePort,
            hasura: { postgresqlUser, postgresqlPassword, postgresqlDatabase }
        } = service;
        const network = destinationDockerId && destinationDocker.network;
        const host = getEngine(destinationDocker.engine);
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

        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.hasura.image,
                    environment: config.hasura.environmentVariables,
                    networks: [network],
                    volumes: [],
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
                [config.postgresql.volume.split(':')[0]]: {
                    name: config.postgresql.volume.split(':')[0]
                }
            }
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

        await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} pull`);
        await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} up -d`);
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
        const { destinationDockerId, destinationDocker, fqdn } = service;
        if (destinationDockerId) {
            const engine = destinationDocker.engine;

            try {
                const found = await checkContainer(engine, id);
                if (found) {
                    await removeContainer({ id, engine });
                }
            } catch (error) {
                console.error(error);
            }
            try {
                const found = await checkContainer(engine, `${id}-postgresql`);
                if (found) {
                    await removeContainer({ id: `${id}-postgresql`, engine });
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
        const host = getEngine(destinationDocker.engine);
        const port = getServiceMainPort('fider');

        const { workdir } = await createDirectories({ repository: type, buildId: id });
        const image = getServiceImage(type);
        const domain = getDomain(fqdn);
        const config = {
            fider: {
                image: `${image}:${version}`,
                environmentVariables: {
                    BASE_URL: domain,
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

        const composeFile: ComposeFile = {
            version: '3.8',
            services: {
                [id]: {
                    container_name: id,
                    image: config.fider.image,
                    environment: config.fider.environmentVariables,
                    networks: [network],
                    volumes: [],
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
                [config.postgresql.volume.split(':')[0]]: {
                    name: config.postgresql.volume.split(':')[0]
                }
            }
        };
        const composeFileDestination = `${workdir}/docker-compose.yaml`;
        await fs.writeFile(composeFileDestination, yaml.dump(composeFile));

        await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} pull`);
        await asyncExecShell(`DOCKER_HOST=${host} docker compose -f ${composeFileDestination} up -d`);

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
        const { destinationDockerId, destinationDocker, fqdn } = service;
        if (destinationDockerId) {
            const engine = destinationDocker.engine;

            try {
                const found = await checkContainer(engine, id);
                if (found) {
                    await removeContainer({ id, engine });
                }
            } catch (error) {
                console.error(error);
            }
            try {
                const found = await checkContainer(engine, `${id}-postgresql`);
                if (found) {
                    await removeContainer({ id: `${id}-postgresql`, engine });
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
            const docker = dockerInstance({ destinationDocker });
            const container = await docker.engine.getContainer(id);
            const command = await container.exec({
                Cmd: [
                    `psql -H postgresql://${postgresqlUser}:${postgresqlPassword}@localhost:5432/${postgresqlDatabase} -c "UPDATE users SET email_verified = true;"`
                ]
            });
            await command.start();
            return await reply.code(201).send()
        }
        throw { status: 500, message: 'Could not activate users.' }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function activateWordpressFtp(request: FastifyRequest<ActivateWordpressFtp>, reply: FastifyReply) {
    const { id } = request.params
    const { ftpEnabled } = request.body;

    const publicPort = await getFreePort();
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
        const host = getEngine(engine);
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
                    const isRunning = await checkContainer(engine, `${id}-ftp`);
                    if (isRunning) {
                        await asyncExecShell(
                            `DOCKER_HOST=${host} docker stop -t 0 ${id}-ftp && docker rm ${id}-ftp`
                        );
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
                await asyncExecShell(
                    `DOCKER_HOST=${host} docker compose -f ${hostkeyDir}/${id}-docker-compose.yml up -d`
                );
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
                await asyncExecShell(
                    `DOCKER_HOST=${host} docker stop -t 0 ${id}-ftp && docker rm ${id}-ftp`
                );
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
