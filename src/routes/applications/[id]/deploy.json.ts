import * as db from '$lib/database';
import type { RequestHandler } from '@sveltejs/kit';
import cuid from 'cuid'
import crypto from 'crypto';
import { buildQueue } from '$lib/queues';
import { selectTeam } from '$lib/common';
import type { Locals } from 'src/global';

export const post: RequestHandler<Locals, FormData> = async (request) => {
    const teamId = selectTeam(request)
    const { id } = request.params
    try {
        const buildId = cuid()
        const applicationFound = await db.getApplication({ id, teamId })
        if (!applicationFound.configHash) {
            const configHash = crypto
                .createHash('sha256')
                .update(
                    JSON.stringify({
                        buildPack: applicationFound.buildPack,
                        port: applicationFound.port,
                        installCommand: applicationFound.installCommand,
                        buildCommand: applicationFound.buildCommand,
                        startCommand: applicationFound.startCommand,
                    })
                )
                .digest('hex')
            await db.prisma.application.update({ where: { id }, data: { configHash } })
        }
        await buildQueue.add(buildId, { build_id: buildId, ...applicationFound })
        return {
            status: 200,
            body: {
                buildId
            }
        }
    } catch (error) {
        return {
            status: 302,
            headers: {
                Location: `/applications/${id}`
            }
        }
    }
}