import { prisma } from "$lib/database"
import { dev } from '$app/env'

export default async function (job) {
    const { line, applicationId, buildId } = job.data
    if (dev) console.debug(`[${applicationId}] ${line}`)
    await prisma.buildLog.create({ data: { line, buildId, time: Number(job.id), applicationId } })
}