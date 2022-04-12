import type { BuildLog } from '@prisma/client';
import { prisma, ErrorHandler } from './common';

export async function listLogs({
	buildId,
	last = 0
}: {
	buildId: string;
	last: number;
}): Promise<BuildLog[] | { status: number; body: { message: string; error: string } }> {
	try {
		const body = await prisma.buildLog.findMany({
			where: { buildId, time: { gt: last } },
			orderBy: { time: 'asc' }
		});
		return [...body];
	} catch (error) {
		return ErrorHandler(error);
	}
}
