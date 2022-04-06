import { prisma } from '$lib/database';
import { dev } from '$app/env';
import type { Job } from 'bullmq';

export default async function (job: Job): Promise<void> {
	const { line, applicationId, buildId } = job.data;
	if (dev) console.debug(`[${applicationId}] ${line}`);
	await prisma.buildLog.create({ data: { line, buildId, time: Number(job.id), applicationId } });
}
