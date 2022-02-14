import { prisma, ErrorHandler } from './common';

export async function listLogs({ buildId, last = 0 }) {
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
