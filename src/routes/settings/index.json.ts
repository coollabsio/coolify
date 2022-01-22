import { getDomain, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { listSettings, PrismaErrorHandler } from '$lib/database';
import { configureCoolifyProxyOff, configureCoolifyProxyOn } from '$lib/haproxy';
import type { RequestHandler } from '@sveltejs/kit';

export const get: RequestHandler = async (request) => {
    const { status, body } = await getUserDetails(request);
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


export const del: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (teamId !== '0') return { status: 401, body: { message: 'You do not have permission to do this. \nAsk an admin to modify your permissions.' } }
    if (status === 401) return { status, body }

    const { id } = request.params
    const name = request.body.get('name') || undefined
    const value = request.body.get('value') || undefined
    try {
        if (name === 'domain') {
            const data = await db.prisma.setting.findUnique({ where: { name: 'domain' } })
            await db.prisma.setting.delete({ where: { name: 'domain' } })
            await configureCoolifyProxyOff({ domain: data.value })
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
export const post: RequestHandler<Locals, FormData> = async (request) => {
    const { teamId, status, body } = await getUserDetails(request);
    if (teamId !== '0') return { status: 401, body: { message: 'You do not have permission to do this. \nAsk an admin to modify your permissions.' } }
    if (status === 401) return { status, body }

    const name = request.body.get('name') || undefined
    const value = request.body.get('value') || undefined

    try {
        let oldFqdn;
        if (name === 'fqdn') {
            oldFqdn = await db.prisma.setting.findUnique({ where: { name }, rejectOnNotFound: false })
        }
        await db.prisma.setting.upsert({ where: { name }, update: { value }, create: { name, value } })
        
        if (name === 'fqdn') {
            const domain = getDomain(value)
            const oldDomain = getDomain(oldFqdn.value)
            if (oldFqdn) await configureCoolifyProxyOff({ domain: oldDomain })
            if (value) await configureCoolifyProxyOn({ domain })
        }
        return {
            status: 200,
        }
    } catch (err) {
        return PrismaErrorHandler(err)
    }
}