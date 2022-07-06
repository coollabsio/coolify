import { promises as dns } from 'dns';

import type { FastifyReply, FastifyRequest } from 'fastify';
import { checkDomainsIsValidInDNS, errorHandler, getDomain, isDNSValid, isDomainConfigured, listSettings, prisma } from '../../../../lib/common';


export async function listAllSettings(request: FastifyRequest) {
    try {
        const settings = await listSettings();
        return {
            settings
        }
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function saveSettings(request: FastifyRequest, reply: FastifyReply) {
    try {
        const {
            fqdn,
            isRegistrationEnabled,
            dualCerts,
            minPort,
            maxPort,
            isAutoUpdateEnabled,
            isDNSCheckEnabled
        } = request.body
        const { id } = await listSettings();
        await prisma.setting.update({
            where: { id },
            data: { isRegistrationEnabled, dualCerts, isAutoUpdateEnabled, isDNSCheckEnabled }
        });
        if (fqdn) {
            await prisma.setting.update({ where: { id }, data: { fqdn } });
        }
        if (minPort && maxPort) {
            await prisma.setting.update({ where: { id }, data: { minPort, maxPort } });
        }
        return reply.code(201).send()
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function deleteDomain(request: FastifyRequest, reply: FastifyReply) {
    try {
        const { fqdn } = request.body
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

export async function checkDomain(request: FastifyRequest, reply: FastifyReply) {
    try {
        const { id } = request.params;
        let { fqdn, forceSave, dualCerts, isDNSCheckEnabled } = request.body
        if (fqdn) fqdn = fqdn.toLowerCase();
        const found = await isDomainConfigured({ id, fqdn });
        if (found) {
            throw "Domain already configured";
        }
        if (isDNSCheckEnabled && !forceSave) {
            return await checkDomainsIsValidInDNS({ hostname: request.hostname.split(':')[0], fqdn, dualCerts });
        }
        return {};
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}
export async function checkDNS(request: FastifyRequest, reply: FastifyReply) {
    try {
        const { id, domain } = request.params;
        await isDNSValid(request.hostname, domain);
        return {}
    } catch ({ status, message }) {
        return errorHandler({ status, message })
    }
}