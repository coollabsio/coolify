import { prisma } from '../prisma';
import { encrypt, generateTimestamp, isDev } from './common';
import { day } from './dayjs';

export type Line = string | { shortMessage: string; stderr: string };
export type BuildLog = {
	line: Line;
	buildId?: string;
	applicationId?: string;
};
export const saveBuildLog = async ({ line, buildId, applicationId }: BuildLog): Promise<any> => {
	if (buildId === 'undefined' || buildId === 'null' || !buildId) return;
	if (applicationId === 'undefined' || applicationId === 'null' || !applicationId) return;
	const { default: got } = await import('got');
	if (typeof line === 'object' && line) {
		if (line.shortMessage) {
			line = line.shortMessage + '\n' + line.stderr;
		} else {
			line = JSON.stringify(line);
		}
	}
	if (line && typeof line === 'string' && line.includes('ghs_')) {
		const regex = /ghs_.*@/g;
		line = line.replace(regex, '<SENSITIVE_DATA_DELETED>@');
	}
	const addTimestamp = `[${generateTimestamp()}] ${line}`;
	const fluentBitUrl = isDev ? 'http://localhost:24224' : 'http://coolify-fluentbit:24224';

	if (isDev) {
		console.debug(`[${applicationId}] ${addTimestamp}`);
	}
	try {
		return await got.post(`${fluentBitUrl}/${applicationId}_buildlog_${buildId}.csv`, {
			json: {
				line: encrypt(line)
			}
		});
	} catch (error) {
		return await prisma.buildLog.create({
			data: {
				line: addTimestamp,
				buildId,
				time: Number(day().valueOf()),
				applicationId
			}
		});
	}
};
