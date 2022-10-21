import type { FastifyReply, FastifyRequest } from 'fastify';
import fs from 'fs/promises';
import yaml from 'js-yaml';
import bcrypt from 'bcryptjs';
import crypto from 'crypto';
import { prisma, uniqueName, asyncExecShell, getServiceFromDB, getContainerUsage, isDomainConfigured, saveUpdateableFields, fixType, decrypt, encrypt, ComposeFile, getFreePublicPort, getDomain, errorHandler, generatePassword, isDev, stopTcpHttpProxy, executeDockerCmd, checkDomainsIsValidInDNS, checkExposedPort, listSettings } from '../../../../lib/common';
import { day } from '../../../../lib/dayjs';
import { checkContainer, isContainerExited } from '../../../../lib/docker';
import cuid from 'cuid';

import type { OnlyId } from '../../../../types';
import type { ActivateWordpressFtp, CheckService, CheckServiceDomain, DeleteServiceSecret, DeleteServiceStorage, GetServiceLogs, SaveService, SaveServiceDestination, SaveServiceSecret, SaveServiceSettings, SaveServiceStorage, SaveServiceType, SaveServiceVersion, ServiceStartStop, SetGlitchTipSettings, SetWordpressSettings } from './types';
import { supportedServiceTypesAndVersions } from '../../../../lib/services/supportedVersions';
import { configureServiceType, removeService } from '../../../../lib/services/common';
import { hashPassword } from '../handlers';
import { getTemplates } from '../../../../lib/services';

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
export async function cleanupUnconfiguredServices(request: FastifyRequest) {
    try {
        const teamId = request.user.teamId;
        let services = await prisma.service.findMany({
            where: { teams: { some: { id: teamId === "0" ? undefined : teamId } } },
            include: { destinationDocker: true, teams: true },
        });
        for (const service of services) {
            if (!service.fqdn) {
                if (service.destinationDockerId) {
                    await executeDockerCmd({
                        dockerId: service.destinationDockerId,
                        command: `docker ps -a --filter 'label=com.docker.compose.project=${service.id}' --format {{.ID}}|xargs -r -n 1 docker stop -t 0`
                    })
                    await executeDockerCmd({
                        dockerId: service.destinationDockerId,
                        command: `docker ps -a --filter 'label=com.docker.compose.project=${service.id}' --format {{.ID}}|xargs -r -n 1 docker rm --force`
                    })
                }
                await removeService({ id: service.id });
            }
        }
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function getServiceStatus(request: FastifyRequest<OnlyId>) {
    try {
        const teamId = request.user.teamId;
        const { id } = request.params;
        const service = await getServiceFromDB({ id, teamId });
        const { destinationDockerId, settings } = service;
        let payload = {}
        if (destinationDockerId) {
            const { stdout: containers } = await executeDockerCmd({
                dockerId: service.destinationDocker.id,
                command:
                    `docker ps -a --filter "label=com.docker.compose.project=${id}" --format '{{json .}}'`
            });
            const containersArray = containers.trim().split('\n');
            if (containersArray.length > 0 && containersArray[0] !== '') {
                for (const container of containersArray) {
                    let isRunning = false;
                    let isExited = false;
                    let isRestarting = false;
                    const containerObj = JSON.parse(container);
                    const status = containerObj.State
                    if (status === 'running') {
                        isRunning = true;
                    }
                    if (status === 'exited') {
                        isExited = true;
                    }
                    if (status === 'restarting') {
                        isRestarting = true;
                    }
                    payload[containerObj.Names] = {
                        status: {
                            isRunning,
                            isExited,
                            isRestarting
                        }

                    }
                }
            }
        }
        return payload
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function parseAndFindServiceTemplates(service: any, workdir?: string, isDeploy: boolean = false) {
    const templates = await getTemplates()
    const foundTemplate = templates.find(t => t.name.toLowerCase() === service.type.toLowerCase())
    let parsedTemplate = {}
    if (foundTemplate) {
        if (!isDeploy) {
            for (const [key, value] of Object.entries(foundTemplate.services)) {
                const realKey = key.replace('$$id', service.id)
                parsedTemplate[realKey] = {
                    name: value.name,
                    image: value.image,
                    environment: [],
                    proxy: {}
                }
                if (value.environment?.length > 0) {
                    for (const env of value.environment) {
                        const [envKey, envValue] = env.split('=')
                        const variable = foundTemplate.variables.find(v => v.name === envKey) || foundTemplate.variables.find(v => v.id === envValue)
                        const label = variable?.label
                        const description = variable?.description
                        const defaultValue = variable?.defaultValue
                        const extras = variable?.extras
                        if (envValue.startsWith('$$config') || extras?.isVisibleOnUI) {
                            if (envValue.startsWith('$$config_coolify')) {
                                continue
                                console.log({ envValue, envKey })
                            }
                            parsedTemplate[realKey].environment.push(
                                { name: envKey, value: envValue, label, description, defaultValue, extras }
                            )
                        }
                    }
                }
                // TODO: seconday domains are not working - kinda working
                if (value?.proxy && value.proxy.length > 0) {
                    for (const proxyValue of value.proxy) {
                        if (proxyValue.domain) {
                            const variable = foundTemplate.variables.find(v => v.id === proxyValue.domain)
                            if (variable) {
                                const { name, label, description, defaultValue, extras } = variable
                                const found = await prisma.serviceSetting.findFirst({ where: { variableName: proxyValue.domain } })
                                parsedTemplate[realKey].environment.push(
                                    { name, value: found.value || '', label, description, defaultValue, extras }
                                )
                            }

                        }
                    }
                }
            }
        } else {
            parsedTemplate = foundTemplate
        }
        let strParsedTemplate = JSON.stringify(parsedTemplate)

        // replace $$id and $$workdir
        strParsedTemplate = strParsedTemplate.replaceAll('$$id', service.id)
        strParsedTemplate = strParsedTemplate.replaceAll('$$core_version', service.version || foundTemplate.defaultVersion)

        // replace $$fqdn
        if (workdir) {
            strParsedTemplate = strParsedTemplate.replaceAll('$$workdir', workdir)
        }

        // replace $$config
        if (service.serviceSetting.length > 0) {
            for (const setting of service.serviceSetting) {
                const { value, variableName } = setting
                if (variableName.startsWith('$$config_coolify')) {
                    continue;
                }
                if (service.fqdn && value === '$$generate_fqdn') {
                    strParsedTemplate = strParsedTemplate.replaceAll(variableName, service.fqdn)
                } else if (service.fqdn && value === '$$generate_domain') {
                    strParsedTemplate = strParsedTemplate.replaceAll(variableName, getDomain(service.fqdn))
                } else if (service.destinationDocker?.network && value === '$$generate_network') {
                    strParsedTemplate = strParsedTemplate.replaceAll(variableName, service.destinationDocker.network)
                } else {
                    strParsedTemplate = strParsedTemplate.replaceAll(variableName, value)
                }
            }
        }

        // replace $$secret
        if (service.serviceSecret.length > 0) {
            for (const secret of service.serviceSecret) {
                const { name, value } = secret
                const regexHashed = new RegExp(`\\$\\$hashed\\$\\$secret_${name}\\"`, 'gi')
                const regex = new RegExp(`\\$\\$secret_${name}\\"`, 'gi')
                if (value) {
                    strParsedTemplate = strParsedTemplate.replaceAll(regexHashed, bcrypt.hashSync(value, 10) + "\"")
                    strParsedTemplate = strParsedTemplate.replaceAll(regex, value + "\"")
                }
            }
        }
        parsedTemplate = JSON.parse(strParsedTemplate)
    }
    return parsedTemplate
}

export async function getService(request: FastifyRequest<OnlyId>) {
    try {
        const teamId = request.user.teamId;
        const { id } = request.params;
        const service = await getServiceFromDB({ id, teamId });
        if (!service) {
            throw { status: 404, message: 'Service not found.' }
        }
        let template = {}
        if (service.type) {
            template = await parseAndFindServiceTemplates(service)
        }
        return {
            settings: await listSettings(),
            service,
            template,
        }
    } catch ({ status, message }) {
        console.log(status, message)
        return errorHandler({ status, message })
    }
}
export async function getServiceType(request: FastifyRequest) {
    try {
        return {
            services: await getTemplates()
        }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function saveServiceType(request: FastifyRequest<SaveServiceType>, reply: FastifyReply) {
    try {
        const { id } = request.params;
        const { type } = request.body;
        const templates = await getTemplates()
        let foundTemplate = templates.find(t => t.name === type)
        if (foundTemplate) {
            foundTemplate = JSON.parse(JSON.stringify(foundTemplate).replaceAll('$$id', id))
            if (foundTemplate.variables.length > 0) {
                for (const variable of foundTemplate.variables) {
                    const { defaultValue } = variable;
                    const regex = /^\$\$.*\((\d+)\)$/g;
                    const length = Number(regex.exec(defaultValue)?.[1]) || undefined
                    if (variable.defaultValue.startsWith('$$generate_password')) {
                        variable.value = generatePassword({ length });
                    } else if (variable.defaultValue.startsWith('$$generate_hex')) {
                        variable.value = generatePassword({ length, isHex: true });
                    } else if (variable.defaultValue.startsWith('$$generate_username')) {
                        variable.value = cuid();
                    } else {
                        variable.value = variable.defaultValue || '';
                    }
                }
            }
            for (const variable of foundTemplate.variables) {
                if (variable.id.startsWith('$$secret_')) {
                    const found = await prisma.serviceSecret.findFirst({ where: { name: variable.name, serviceId: id } })
                    if (!found) {
                        await prisma.serviceSecret.create({
                            data: { name: variable.name, value: encrypt(variable.value) || '', service: { connect: { id } } }
                        })
                    }

                }
                if (variable.id.startsWith('$$config_')) {
                    const found = await prisma.serviceSetting.findFirst({ where: { name: variable.name, serviceId: id } })
                    if (!found) {
                        await prisma.serviceSetting.create({
                            data: { name: variable.name, value: variable.value.toString(), variableName: variable.id, service: { connect: { id } } }
                        })
                    }
                }
            }
            for (const service of Object.keys(foundTemplate.services)) {
                if (foundTemplate.services[service].volumes) {
                    for (const volume of foundTemplate.services[service].volumes) {
                        const [volumeName, path] = volume.split(':')
                        if (!volumeName.startsWith('/')) {
                            const found = await prisma.servicePersistentStorage.findFirst({ where: { volumeName, serviceId: id } })
                            if (!found) {
                                await prisma.servicePersistentStorage.create({
                                    data: { volumeName, path, containerId: service, predefined: true, service: { connect: { id } } }
                                });
                            }
                        }
                    }
                }
            }
            await prisma.service.update({ where: { id }, data: { type, version: foundTemplate.defaultVersion, templateVersion: foundTemplate.templateVersion } })
            return reply.code(201).send()
        } else {
            throw { status: 404, message: 'Service type not found.' }
        }

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
        const { id, containerId } = request.params;
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
                const { default: ansi } = await import('strip-ansi')
                const { stdout, stderr } = await executeDockerCmd({ dockerId, command: `docker logs --since ${since} --tail 5000 --timestamps ${containerId}` })
                const stripLogsStdout = stdout.toString().split('\n').map((l) => ansi(l)).filter((a) => a);
                const stripLogsStderr = stderr.toString().split('\n').map((l) => ansi(l)).filter((a) => a);
                const logs = stripLogsStderr.concat(stripLogsStdout)
                const sortedLogs = logs.sort((a, b) => (day(a.split(' ')[0]).isAfter(day(b.split(' ')[0])) ? 1 : -1))
                return { logs: sortedLogs }
                // }
            } catch (error) {
                const { statusCode, stderr } = error;
                if (stderr.startsWith('Error: No such container')) {
                    return { logs: [], noContainer: true }
                }
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

        const { destinationDocker: { remoteIpAddress, remoteEngine, engine }, exposePort: configuredPort } = await prisma.service.findUnique({ where: { id }, include: { destinationDocker: true } })
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
        if (exposePort) await checkExposedPort({ id, configuredPort, exposePort, engine, remoteEngine, remoteIpAddress })
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
        let { name, fqdn, exposePort, type, serviceSetting } = request.body;
        if (fqdn) fqdn = fqdn.toLowerCase();
        if (exposePort) exposePort = Number(exposePort);

        type = fixType(type)
        // const update = saveUpdateableFields(type, request.body[type])
        const data = {
            fqdn,
            name,
            exposePort,
        }
        // if (Object.keys(update).length > 0) {
        //     data[type] = { update: update }
        // }
        for (const setting of serviceSetting) {
            const { id: settingId, name, value, changed = false, isNew = false } = setting
            if (changed) {
                await prisma.serviceSetting.update({ where: { id: settingId }, data: { value } })
            }
            if (isNew) {
                await prisma.serviceSetting.create({ data: { name, value, service: { connect: { id } } } })
            }
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

export async function setSettingsService(request: FastifyRequest<ServiceStartStop & SetWordpressSettings & SetGlitchTipSettings>, reply: FastifyReply) {
    try {
        const { type } = request.params
        if (type === 'wordpress') {
            return await setWordpressSettings(request, reply)
        }
        if (type === 'glitchtip') {
            return await setGlitchTipSettings(request, reply)
        }
        throw `Service type ${type} not supported.`
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
async function setGlitchTipSettings(request: FastifyRequest<SetGlitchTipSettings>, reply: FastifyReply) {
    try {
        const { id } = request.params
        const { enableOpenUserRegistration, emailSmtpUseSsl, emailSmtpUseTls } = request.body
        await prisma.glitchTip.update({
            where: { serviceId: id },
            data: { enableOpenUserRegistration, emailSmtpUseSsl, emailSmtpUseTls }
        });
        return reply.code(201).send()
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
                command: `docker exec ${id}-postgresql psql -H postgresql://${postgresqlUser}:${postgresqlPassword}@localhost:5432/${postgresqlDatabase} -c "UPDATE users SET email_verified = true;"`
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
                command: `docker exec ${id}-clickhouse /usr/bin/clickhouse-client -q \\"SELECT name FROM system.tables WHERE name LIKE '%log%';\\"| xargs -I{} /usr/bin/clickhouse-client -q \"TRUNCATE TABLE system.{};\"`
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

    const { service: { destinationDocker: { engine, remoteEngine, remoteIpAddress } } } = await prisma.wordpress.findUnique({ where: { serviceId: id }, include: { service: { include: { destinationDocker: true } } } })

    const publicPort = await getFreePublicPort({ id, remoteEngine, engine, remoteIpAddress });

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
                    const { found: isRunning } = await checkContainer({ dockerId: destinationDocker.id, container: `${id}-ftp` });
                    if (isRunning) {
                        await executeDockerCmd({
                            dockerId: destinationDocker.id,
                            command: `docker stop -t 0 ${id}-ftp && docker rm ${id}-ftp`
                        })
                    }
                } catch (error) { }
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
        } catch (error) { }

    }

}
