import { getDomain, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { listSettings, PrismaErrorHandler } from '$lib/database';
import { configureCoolifyProxyOff, configureCoolifyProxyOn } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (event) => {
    const { status, body } = await getUserDetails(event);
    if (status === 401) return { status, body }

    try {
        return {
            body: {
                settings: await listSettings()
            }
        };
    } catch (err) {
        return err
    }
}


export const del: RequestHandler<Locals> = async (event) => {
    const { teamId, status, body } = await getUserDetails(event);
    if (teamId !== '0') return { status: 401, body: { message: 'You do not have permission to do this. \nAsk an admin to modify your permissions.' } }
    if (status === 401) return { status, body }

    const { name } = await event.request.json()

    try {
        if (name === 'fqdn') {
            const data = await db.prisma.setting.findUnique({ where: { name: 'fqdn' } })
            await db.prisma.setting.delete({ where: { name: 'fqdn' } })
            const domain = getDomain(data.value)
            await configureCoolifyProxyOff({ domain })
        }
        return {
            status: 200
        }
    } catch (error) {
        console.error(error)
        return {
            status: 500
        }
    }

}
export const post: RequestHandler<Locals> = async (event) => {
    const { teamId, status, body } = await getUserDetails(event);
    if (teamId !== '0') return { status: 401, body: { message: 'You do not have permission to do this. \nAsk an admin to modify your permissions.' } }
    if (status === 401) return { status, body }

    const { name, value } = await event.request.json()
    try {
        let oldFqdn;
        if (name === 'fqdn') {
            oldFqdn = await db.prisma.setting.findUnique({ where: { name }, rejectOnNotFound: false })
        }
        await db.prisma.setting.upsert({ where: { name }, update: { value }, create: { name, value } })

        if (name === 'fqdn') {
            const domain = getDomain(value)
            const oldDomain = getDomain(oldFqdn?.value)
            if (oldFqdn) await configureCoolifyProxyOff({ domain: oldDomain })
            if (domain) await configureCoolifyProxyOn({ domain })
        }
        return {
            status: 200,
        }
    } catch (err) {
        return PrismaErrorHandler(err)
    }
}