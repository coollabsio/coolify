import { prisma } from "$lib/database"

export default async function (job) {
    const { line, applicationId, buildId } = job.data
    await prisma.buildLog.create({ data: { line, buildId, time: Number(job.id), applicationId } })
}