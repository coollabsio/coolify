import { promises as dns } from 'dns';
import { X509Certificate } from 'node:crypto';
import * as Sentry from '@sentry/node';
import type { FastifyReply, FastifyRequest } from 'fastify';
import { checkDomainsIsValidInDNS, decrypt, encrypt, errorHandler, executeCommand, getDomain, isDev, isDNSValid, isDomainConfigured, listSettings, prisma, sentryDSN, version } from '../../../../lib/common';
import { AddDefaultRegistry, CheckDNS, CheckDomain, DeleteDomain, OnlyIdInBody, SaveSettings, SaveSSHKey, SetDefaultRegistry } from './types';


export async function listAllSettings(request: FastifyRequest) {
    try {
        const teamId = request.user.teamId;
        const settings = await listSettings();
        const sshKeys = await prisma.sshKey.findMany({ where: { team: { id: teamId } } })
        let registries = await prisma.dockerRegistry.findMany({ where: { team: { id: teamId } } })
        registries = registries.map((registry) => {
            if (registry.password) {
                registry.password = decrypt(registry.password)
            }
            return registry
        })
        const unencryptedKeys = []
        if (sshKeys.length > 0) {
            for (const key of sshKeys) {
                unencryptedKeys.push({ id: key.id, name: key.name, privateKey: decrypt(key.privateKey), createdAt: key.createdAt })
            }
        }
        const certificates = await prisma.certificate.findMany({ where: { team: { id: teamId } } })
        let cns = [];
        for (const certificate of certificates) {
            const x509 = new X509Certificate(certificate.cert);
            cns.push({ commonName: x509.subject.split('\n').find((s) => s.startsWith('CN=')).replace('CN=', ''), id: certificate.id, createdAt: certificate.createdAt })
        }

        return {
            settings,
            certificates: cns,
            sshKeys: unencryptedKeys,
            registries
        }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function saveSettings(request: FastifyRequest<SaveSettings>, reply: FastifyReply) {
    try {
        let {
            previewSeparator,
            numberOfDockerImagesKeptLocally,
            doNotTrack,
            fqdn,
            isAPIDebuggingEnabled,
            isRegistrationEnabled,
            dualCerts,
            minPort,
            maxPort,
            isAutoUpdateEnabled,
            isDNSCheckEnabled,
            DNSServers,
            proxyDefaultRedirect
        } = request.body
        const { id, previewSeparator: SetPreviewSeparator } = await listSettings();
        if (numberOfDockerImagesKeptLocally) {
            numberOfDockerImagesKeptLocally = Number(numberOfDockerImagesKeptLocally)
        }
        if (previewSeparator == '') {
            previewSeparator = '.'
        }
        if (SetPreviewSeparator != previewSeparator) {
            const applications = await prisma.application.findMany({ where: { previewApplication: { some: { id: { not: undefined } } } }, include: { previewApplication: true } })
            for (const application of applications) {
                for (const preview of application.previewApplication) {
                    const { protocol } = new URL(preview.customDomain)
                    const { pullmergeRequestId } = preview
                    const { fqdn } = application
                    const newPreviewDomain = `${protocol}//${pullmergeRequestId}${previewSeparator}${getDomain(fqdn)}`
                    await prisma.previewApplication.update({ where: { id: preview.id }, data: { customDomain: newPreviewDomain } })
                }
            }
        }

        await prisma.setting.update({
            where: { id },
            data: { previewSeparator, numberOfDockerImagesKeptLocally, doNotTrack, isRegistrationEnabled, dualCerts, isAutoUpdateEnabled, isDNSCheckEnabled, DNSServers, isAPIDebuggingEnabled }
        });
        if (fqdn) {
            await prisma.setting.update({ where: { id }, data: { fqdn } });
        }
        await prisma.setting.update({ where: { id }, data: { proxyDefaultRedirect } });
        if (minPort && maxPort) {
            await prisma.setting.update({ where: { id }, data: { minPort, maxPort } });
        }
        if (doNotTrack === false) {
            // Sentry.init({
            //     dsn: sentryDSN,
            //     environment: isDev ? 'development' : 'production',
            //     release: version
            // });
            // console.log('Sentry initialized')
        }
        return reply.code(201).send()
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function deleteDomain(request: FastifyRequest<DeleteDomain>, reply: FastifyReply) {
    try {
        const { fqdn } = request.body
        const { DNSServers } = await listSettings();
        if (DNSServers) {
            dns.setServers([...DNSServers.split(',')]);
        }
        let ip;
        try {
            ip = await dns.resolve(fqdn);
        } catch (error) {
            // Do not care.
        }
        await prisma.setting.update({ where: { fqdn }, data: { fqdn: null } });
        return reply.redirect(302, ip ? `http://${ip[0]}:3000/settings` : undefined)
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function checkDomain(request: FastifyRequest<CheckDomain>) {
    try {
        const { id } = request.params;
        let { fqdn, forceSave, dualCerts, isDNSCheckEnabled } = request.body
        if (fqdn) fqdn = fqdn.toLowerCase();
        const found = await isDomainConfigured({ id, fqdn });
        if (found) {
            throw { message: "Domain already configured" };
        }
        if (isDNSCheckEnabled && !forceSave && !isDev) {
            const hostname = request.hostname.split(':')[0]
            return await checkDomainsIsValidInDNS({ hostname, fqdn, dualCerts });
        }
        return {};
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function checkDNS(request: FastifyRequest<CheckDNS>) {
    try {
        const { domain } = request.params;
        await isDNSValid(request.hostname, domain);
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function saveSSHKey(request: FastifyRequest<SaveSSHKey>, reply: FastifyReply) {
    try {
        const teamId = request.user.teamId;
        const { privateKey, name } = request.body;
        const found = await prisma.sshKey.findMany({ where: { name } })
        if (found.length > 0) {
            throw {
                message: "Name already used. Choose another one please."
            }
        }
        const encryptedSSHKey = encrypt(privateKey)
        await prisma.sshKey.create({ data: { name, privateKey: encryptedSSHKey, team: { connect: { id: teamId } } } })
        return reply.code(201).send()
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function deleteSSHKey(request: FastifyRequest<OnlyIdInBody>, reply: FastifyReply) {
    try {
        const teamId = request.user.teamId;
        const { id } = request.body;
        await prisma.sshKey.deleteMany({ where: { id, teamId } })
        return reply.code(201).send()
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function deleteCertificates(request: FastifyRequest<OnlyIdInBody>, reply: FastifyReply) {
    try {
        const teamId = request.user.teamId;
        const { id } = request.body;
        await executeCommand({ command: `docker exec coolify-proxy sh -c 'rm -f /etc/traefik/acme/custom/${id}-key.pem /etc/traefik/acme/custom/${id}-cert.pem'`, shell: true })
        await prisma.certificate.deleteMany({ where: { id, teamId } })
        return reply.code(201).send()
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}

export async function setDockerRegistry(request: FastifyRequest<SetDefaultRegistry>, reply: FastifyReply) {
    try {
        const teamId = request.user.teamId;
        const { id, username, password } = request.body;

        let encryptedPassword = ''
        if (password) encryptedPassword = encrypt(password)

        if (teamId === '0') {
            await prisma.dockerRegistry.update({ where: { id }, data: { username, password: encryptedPassword } })
        } else {
            await prisma.dockerRegistry.updateMany({ where: { id, teamId }, data: { username, password: encryptedPassword } })
        }
        return reply.code(201).send()
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function addDockerRegistry(request: FastifyRequest<AddDefaultRegistry>, reply: FastifyReply) {
    try {
        const teamId = request.user.teamId;
        const { name, url, username, password } = request.body;

        let encryptedPassword = ''
        if (password) encryptedPassword = encrypt(password)
        await prisma.dockerRegistry.create({ data: { name, url, username, password: encryptedPassword, team: { connect: { id: teamId } } } })

        return reply.code(201).send()
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function deleteDockerRegistry(request: FastifyRequest<OnlyIdInBody>, reply: FastifyReply) {
    try {
        const teamId = request.user.teamId;
        const { id } = request.body;
        await prisma.application.updateMany({ where: { dockerRegistryId: id }, data: { dockerRegistryId: null } })
        await prisma.dockerRegistry.deleteMany({ where: { id, teamId } })
        return reply.code(201).send()
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}