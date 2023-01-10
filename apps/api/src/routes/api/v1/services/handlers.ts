import type { FastifyReply, FastifyRequest } from 'fastify';
import fs from 'fs/promises';
import yaml from 'js-yaml';
import bcrypt from 'bcryptjs';
import cuid from 'cuid';

import { prisma, uniqueName, getServiceFromDB, getContainerUsage, isDomainConfigured, fixType, decrypt, encrypt, ComposeFile, getFreePublicPort, getDomain, errorHandler, generatePassword, isDev, stopTcpHttpProxy, checkDomainsIsValidInDNS, checkExposedPort, listSettings, generateToken, executeCommand } from '../../../../lib/common';
import { day } from '../../../../lib/dayjs';
import { checkContainer, } from '../../../../lib/docker';
import { removeService } from '../../../../lib/services/common';
import { getTags, getTemplates } from '../../../../lib/services';

import type { ActivateWordpressFtp, CheckService, CheckServiceDomain, DeleteServiceSecret, DeleteServiceStorage, GetServiceLogs, SaveService, SaveServiceDestination, SaveServiceSecret, SaveServiceSettings, SaveServiceStorage, SaveServiceType, SaveServiceVersion, ServiceStartStop, SetGlitchTipSettings, SetWordpressSettings } from './types';
import type { OnlyId } from '../../../../types';

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
                    const { stdout: containers } = await executeCommand({
                        dockerId: service.destinationDockerId,
                        command: `docker ps -a --filter 'label=com.docker.compose.project=${service.id}' --format {{.ID}}`
                    })
                    if (containers) {
                        const containerArray = containers.split('\n');
                        if (containerArray.length > 0) {
                            for (const container of containerArray) {
                                await executeCommand({ dockerId: service.destinationDockerId, command: `docker stop -t 0 ${container}` })
                                await executeCommand({ dockerId: service.destinationDockerId, command: `docker rm --force ${container}` })
                            }
                        }
                    }
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
            const { stdout: containers } = await executeCommand({
                dockerId: service.destinationDocker.id,
                command:
                    `docker ps -a --filter "label=com.docker.compose.project=${id}" --format '{{json .}}'`
            });
            if (containers) {
                const containersArray = containers.trim().split('\n');
                if (containersArray.length > 0 && containersArray[0] !== '') {
                    const templates = await getTemplates();
                    let template = templates.find(t => t.type === service.type);
                    const templateStr = JSON.stringify(template)
                    if (templateStr) {
                        template = JSON.parse(templateStr.replaceAll('$$id', service.id));
                    }
                    for (const container of containersArray) {
                        let isRunning = false;
                        let isExited = false;
                        let isRestarting = false;
                        let isExcluded = false;
                        const containerObj = JSON.parse(container);
                        const exclude = template?.services[containerObj.Names]?.exclude;
                        if (exclude) {
                            payload[containerObj.Names] = {
                                status: {
                                    isExcluded: true,
                                    isRunning: false,
                                    isExited: false,
                                    isRestarting: false,
                                }
                            }
                            continue;
                        }

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
                                isExcluded,
                                isRunning,
                                isExited,
                                isRestarting
                            }
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
    const foundTemplate = templates.find(t => fixType(t.type) === service.type)
    let parsedTemplate = {}
    if (foundTemplate) {
        if (!isDeploy) {
            for (const [key, value] of Object.entries(foundTemplate.services)) {
                const realKey = key.replace('$$id', service.id)
                let name = value.name
                if (!name) {
                    if (Object.keys(foundTemplate.services).length === 1) {
                        name = foundTemplate.name || service.name.toLowerCase()
                    } else {
                        if (key === '$$id') {
                            name = foundTemplate.name || key.replaceAll('$$id-', '') || service.name.toLowerCase()
                        } else {
                            name = key.replaceAll('$$id-', '') || service.name.toLowerCase()
                        }
                    }
                }
                parsedTemplate[realKey] = {
                    value,
                    name,
                    documentation: value.documentation || foundTemplate.documentation || 'https://docs.coollabs.io',
                    image: value.image,
                    files: value?.files,
                    environment: [],
                    fqdns: [],
                    hostPorts: [],
                    proxy: {}
                }
                if (value.environment?.length > 0) {
                    for (const env of value.environment) {
                        let [envKey, ...envValue] = env.split('=')
                        envValue = envValue.join("=")
                        let variable = null
                        if (foundTemplate?.variables) {
                            variable = foundTemplate?.variables.find(v => v.name === envKey) || foundTemplate?.variables.find(v => v.id === envValue)
                        }
                        if (variable) {
                            const id = variable.id.replaceAll('$$', '')
                            const label = variable?.label
                            const description = variable?.description
                            const defaultValue = variable?.defaultValue
                            const main = variable?.main || '$$id'
                            const type = variable?.type || 'input'
                            const placeholder = variable?.placeholder || ''
                            const readOnly = variable?.readOnly || false
                            const required = variable?.required || false
                            if (envValue.startsWith('$$config') || variable?.showOnConfiguration) {
                                if (envValue.startsWith('$$config_coolify')) {
                                    continue
                                }
                                parsedTemplate[realKey].environment.push(
                                    { id, name: envKey, value: envValue, main, label, description, defaultValue, type, placeholder, required, readOnly }
                                )
                            }
                        }

                    }
                }
                if (value?.proxy && value.proxy.length > 0) {
                    for (const proxyValue of value.proxy) {
                        if (proxyValue.domain) {
                            const variable = foundTemplate?.variables.find(v => v.id === proxyValue.domain)
                            if (variable) {
                                const { id, name, label, description, defaultValue, required = false } = variable
                                const found = await prisma.serviceSetting.findFirst({ where: { serviceId: service.id, variableName: proxyValue.domain } })
                                parsedTemplate[realKey].fqdns.push(
                                    { id, name, value: found?.value || '', label, description, defaultValue, required }
                                )
                            }
                        }
                        if (proxyValue.hostPort) {
                            const variable = foundTemplate?.variables.find(v => v.id === proxyValue.hostPort)
                            if (variable) {
                                const { id, name, label, description, defaultValue, required = false } = variable
                                const found = await prisma.serviceSetting.findFirst({ where: { serviceId: service.id, variableName: proxyValue.hostPort } })
                                parsedTemplate[realKey].hostPorts.push(
                                    { id, name, value: found?.value || '', label, description, defaultValue, required }
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

        // replace $$workdir
        if (workdir) {
            strParsedTemplate = strParsedTemplate.replaceAll('$$workdir', workdir)
        }

        // replace $$config
        if (service.serviceSetting.length > 0) {
            for (const setting of service.serviceSetting) {
                const { value, variableName } = setting
                const regex = new RegExp(`\\$\\$config_${variableName.replace('$$config_', '')}\"`, 'gi')
                if (value === '$$generate_fqdn') {
                    strParsedTemplate = strParsedTemplate.replaceAll(regex, service.fqdn + '"' || '' + '"')
                } else if (value === '$$generate_fqdn_slash') {
                    strParsedTemplate = strParsedTemplate.replaceAll(regex, service.fqdn + '/' + '"')
                } else if (value === '$$generate_domain') {
                    strParsedTemplate = strParsedTemplate.replaceAll(regex, getDomain(service.fqdn) + '"')
                } else if (service.destinationDocker?.network && value === '$$generate_network') {
                    strParsedTemplate = strParsedTemplate.replaceAll(regex, service.destinationDocker.network + '"')
                } else {
                    strParsedTemplate = strParsedTemplate.replaceAll(regex, value + '"')
                }
            }
        }

        // replace $$secret
        if (service.serviceSecret.length > 0) {
            for (const secret of service.serviceSecret) {
                let { name, value } = secret
                name = name.toLowerCase()
                const regexHashed = new RegExp(`\\$\\$hashed\\$\\$secret_${name}`, 'gi')
                const regex = new RegExp(`\\$\\$secret_${name}`, 'gi')
                if (value) {
                    strParsedTemplate = strParsedTemplate.replaceAll(regexHashed, bcrypt.hashSync(value.replaceAll("\"", "\\\""), 10))
                    strParsedTemplate = strParsedTemplate.replaceAll(regex, value.replaceAll("\"", "\\\""))
                } else {
                    strParsedTemplate = strParsedTemplate.replaceAll(regexHashed, '')
                    strParsedTemplate = strParsedTemplate.replaceAll(regex, '')
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
        let tags = []
        if (service.type) {
            template = await parseAndFindServiceTemplates(service)
            tags = await getTags(service.type)
        }
        return {
            settings: await listSettings(),
            service,
            template,
            tags
        }
    } catch ({ status, message }) {
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
        let foundTemplate = templates.find(t => fixType(t.type) === fixType(type))
        if (foundTemplate) {
            foundTemplate = JSON.parse(JSON.stringify(foundTemplate).replaceAll('$$id', id))
            if (foundTemplate.variables) {
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
                        } else if (variable.defaultValue.startsWith('$$generate_token')) {
                            variable.value = generateToken()
                        } else {
                            variable.value = variable.defaultValue || '';
                        }
                        const foundVariableSomewhereElse = foundTemplate.variables.find(v => v.defaultValue.includes(variable.id))
                        if (foundVariableSomewhereElse) {
                            foundVariableSomewhereElse.value = foundVariableSomewhereElse.value.replaceAll(variable.id, variable.value)
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

            if (type.startsWith('wordpress')) {
                await prisma.service.update({ where: { id }, data: { wordpress: { create: {} } } })
            }
            return reply.code(201).send()
        } else {
            throw { status: 404, message: 'Service type not found.' }
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
                const { stdout, stderr } = await executeCommand({ dockerId, command: `docker logs --since ${since} --tail 5000 --timestamps ${containerId}` })
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
        // TODO: Disabled this because it is having problems with remote docker engines.
        // return await checkDomainsIsValidInDNS({ hostname: domain, fqdn, dualCerts });
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function checkService(request: FastifyRequest<CheckService>) {
    try {
        const { id } = request.params;
        let { fqdn, exposePort, forceSave, dualCerts, otherFqdn = false } = request.body;

        const domainsList = await prisma.serviceSetting.findMany({ where: { variableName: { startsWith: '$$config_coolify_fqdn' } } })

        if (fqdn) fqdn = fqdn.toLowerCase();
        if (exposePort) exposePort = Number(exposePort);

        const { destinationDocker: { remoteIpAddress, remoteEngine, engine }, exposePort: configuredPort } = await prisma.service.findUnique({ where: { id }, include: { destinationDocker: true } })
        const { isDNSCheckEnabled } = await prisma.setting.findFirst({});

        let found = await isDomainConfigured({ id, fqdn, remoteIpAddress, checkOwn: otherFqdn });
        if (found) {
            throw { status: 500, message: `Domain ${getDomain(fqdn).replace('www.', '')} is already in use!` }
        }
        if (domainsList.find(d => getDomain(d.value) === getDomain(fqdn))) {
            throw { status: 500, message: `Domain ${getDomain(fqdn).replace('www.', '')} is already in use!` }
        }
        if (exposePort) await checkExposedPort({ id, configuredPort, exposePort, engine, remoteEngine, remoteIpAddress })
        // TODO: Disabled this because it is having problems with remote docker engines.
        // if (isDNSCheckEnabled && !isDev && !forceSave) {
        //     let hostname = request.hostname.split(':')[0];
        //     if (remoteEngine) hostname = remoteIpAddress;
        //     return await checkDomainsIsValidInDNS({ hostname, fqdn, dualCerts });
        // }
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function saveService(request: FastifyRequest<SaveService>, reply: FastifyReply) {
    try {
        const { id } = request.params;
        let { name, fqdn, exposePort, type, serviceSetting, version } = request.body;
        if (fqdn) fqdn = fqdn.toLowerCase();
        if (exposePort) exposePort = Number(exposePort);
        type = fixType(type)

        const data = {
            fqdn,
            name,
            exposePort,
            version,
        }
        const templates = await getTemplates()
        const service = await prisma.service.findUnique({ where: { id } })
        const foundTemplate = templates.find(t => fixType(t.type) === fixType(service.type))
        for (const setting of serviceSetting) {
            let { id: settingId, name, value, changed = false, isNew = false, variableName } = setting
            if (value) {
                if (changed) {
                    await prisma.serviceSetting.update({ where: { id: settingId }, data: { value } })
                }
                if (isNew) {
                    if (!variableName) {
                        variableName = foundTemplate?.variables.find(v => v.name === name).id
                    }
                    await prisma.serviceSetting.create({ data: { name, value, variableName, service: { connect: { id } } } })
                }
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
        const teamId = request.user.teamId;
        const service = await getServiceFromDB({ id, teamId });
        let secrets = await prisma.serviceSecret.findMany({
            where: { serviceId: id },
            orderBy: { createdAt: 'desc' }
        });
        const templates = await getTemplates()
        const foundTemplate = templates.find(t => fixType(t.type) === service.type)
        secrets = secrets.map((secret) => {
            const foundVariable = foundTemplate?.variables.find(v => v.name === secret.name) || null
            if (foundVariable) {
                secret.readOnly = foundVariable.readOnly
            }
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
        const { path, isNewStorage, storageId, containerId } = request.body

        if (isNewStorage) {
            const volumeName = `${id}-custom${path.replace(/\//gi, '-')}`
            const found = await prisma.servicePersistentStorage.findFirst({ where: { path, containerId } });
            if (found) {
                throw { status: 500, message: 'Persistent storage already exists for this container and path.' }
            }
            await prisma.servicePersistentStorage.create({
                data: { path, volumeName, containerId, service: { connect: { id } } }
            });
        } else {
            await prisma.servicePersistentStorage.update({
                where: { id: storageId },
                data: { path, containerId }
            });
        }
        return reply.code(201).send()
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function deleteServiceStorage(request: FastifyRequest<DeleteServiceStorage>) {
    try {
        const { storageId } = request.body
        await prisma.servicePersistentStorage.deleteMany({ where: { id: storageId } });
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
            serviceSecret
        } = await getServiceFromDB({ id, teamId });
        if (destinationDockerId) {
            const databaseUrl = serviceSecret.find((secret) => secret.name === 'DATABASE_URL');
            if (databaseUrl) {
                await executeCommand({
                    dockerId: destinationDocker.id,
                    command: `docker exec ${id}-postgresql psql -H ${databaseUrl.value} -c "UPDATE users SET email_verified = true;"`
                })
                return await reply.code(201).send()
            }
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
            await executeCommand({
                dockerId: destinationDocker.id,
                command: `docker exec ${id}-clickhouse /usr/bin/clickhouse-client -q \\"SELECT name FROM system.tables WHERE name LIKE '%log%';\\"| xargs -I{} /usr/bin/clickhouse-client -q \"TRUNCATE TABLE system.{};\"`,
                shell: true
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

            // TODO: rewrite these to usable without shell
            const { stdout: password } = await executeCommand({
                command:
                    `echo ${ftpPassword} | openssl passwd -1 -stdin`,
                shell: true
            }
            );
            if (destinationDockerId) {
                try {
                    await fs.stat(hostkeyDir);
                } catch (error) {
                    await executeCommand({ command: `mkdir -p ${hostkeyDir}` });
                }
                if (!ftpHostKey) {
                    await executeCommand({
                        command:
                            `ssh-keygen -t ed25519 -f ssh_host_ed25519_key -N "" -q -f ${hostkeyDir}/${id}.ed25519`
                    }
                    );
                    const { stdout: ftpHostKey } = await executeCommand({ command: `cat ${hostkeyDir}/${id}.ed25519` });
                    await prisma.wordpress.update({
                        where: { serviceId: id },
                        data: { ftpHostKey: encrypt(ftpHostKey) }
                    });
                } else {
                    await executeCommand({ command: `echo "${decrypt(ftpHostKey)}" > ${hostkeyDir}/${id}.ed25519`, shell: true });
                }
                if (!ftpHostKeyPrivate) {
                    await executeCommand({ command: `ssh-keygen -t rsa -b 4096 -N "" -f ${hostkeyDir}/${id}.rsa` });
                    const { stdout: ftpHostKeyPrivate } = await executeCommand({ command: `cat ${hostkeyDir}/${id}.rsa` });
                    await prisma.wordpress.update({
                        where: { serviceId: id },
                        data: { ftpHostKeyPrivate: encrypt(ftpHostKeyPrivate) }
                    });
                } else {
                    await executeCommand({ command: `echo "${decrypt(ftpHostKeyPrivate)}" > ${hostkeyDir}/${id}.rsa`, shell: true });
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
                        await executeCommand({
                            dockerId: destinationDocker.id,
                            command: `docker stop -t 0 ${id}-ftp && docker rm ${id}-ftp`,
                            shell: true
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
                await executeCommand({ command: `chmod +x ${hostkeyDir}/${id}.sh` });
                await fs.writeFile(`${hostkeyDir}/${id}-docker-compose.yml`, yaml.dump(compose));
                await executeCommand({
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
                await executeCommand({
                    dockerId: destinationDocker.id,
                    command: `docker stop -t 0 ${id}-ftp && docker rm ${id}-ftp`,
                    shell: true
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
            await executeCommand({
                command:
                    `rm -fr ${hostkeyDir}/${id}-docker-compose.yml ${hostkeyDir}/${id}.ed25519 ${hostkeyDir}/${id}.ed25519.pub ${hostkeyDir}/${id}.rsa ${hostkeyDir}/${id}.rsa.pub ${hostkeyDir}/${id}.sh`
            }
            );
        } catch (error) { }

    }

}
