import { getDomain, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { listSettings, PrismaErrorHandler } from '$lib/database';
import { configureCoolifyProxyOff, configureCoolifyProxyOn } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (event) => {
    const { status, body } = await getUserDetails(event);
    if (status === 401) return { status, body }

    try {
        const settings = await listSettings()
        return {
            status: 200,
            body: {
                settings
            }
        };
    } catch (error) {
        return PrismaErrorHandler(error)
    }
}


export const del: RequestHandler<Locals> = async (event) => {
    const { teamId, status, body } = await getUserDetails(event);
    if (teamId !== '0') return { status: 401, body: { message: 'You do not have permission to do this. \nAsk an admin to modify your permissions.' } }
    if (status === 401) return { status, body }

    const { fqdn } = await event.request.json()

    try {
        await db.prisma.setting.update({ where: { fqdn }, data: { fqdn: null } })
        const domain = getDomain(fqdn)
        await configureCoolifyProxyOff({ domain })
        return {
            status: 201
        }
    } catch (error) {
        return PrismaErrorHandler(error)
    }

}
export const post: RequestHandler<Locals> = async (event) => {
    const { teamId, status, body } = await getUserDetails(event);
    if (teamId !== '0') return { status: 401, body: { message: 'You do not have permission to do this. \nAsk an admin to modify your permissions.' } }
    if (status === 401) return { status, body }

    const { fqdn, isRegistrationEnabled } = await event.request.json()
    try {
        const { id, fqdn: oldFqdn, isRegistrationEnabled: oldIsRegistrationEnabled } = await db.prisma.setting.findFirst({})
        if (oldIsRegistrationEnabled !== isRegistrationEnabled) {
            await db.prisma.setting.update({ where: { id }, data: { isRegistrationEnabled } })
        }
        if (oldFqdn !== fqdn) {
            const oldDomain = getDomain(oldFqdn)
            if (oldFqdn) await configureCoolifyProxyOff({ domain: oldDomain })
        }
        if (fqdn) {
            await db.prisma.setting.update({ where: { id }, data: { fqdn } })
            const domain = getDomain(fqdn)
            if (domain) await configureCoolifyProxyOn({ domain })
        }

        return {
            status: 201,
        }
    } catch (error) {
        return PrismaErrorHandler(error)
    }
}