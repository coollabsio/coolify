import { z } from 'zod';
import { privateProcedure, router } from '../trpc';
import { decrypt, getTemplates, listSettings } from '../../lib/common';
import { prisma } from '../../prisma';
import { executeCommand } from '../../lib/executeCommand';
import { checkContainer } from '../../lib/docker';

export const servicesRouter = router({
	status: privateProcedure
		.input(
			z.object({
				id: z.string()
			})
		)
		.query(async ({ ctx, input }) => {
			const id = input.id;
			const teamId = ctx.user?.teamId;
			if (!teamId) {
				throw { status: 400, message: 'Team not found.' };
			}
			const service = await getServiceFromDB({ id, teamId });
			const { destinationDockerId } = service;
			let payload = {};
			if (destinationDockerId) {
				const { stdout: containers } = await executeCommand({
					dockerId: service.destinationDocker.id,
					command: `docker ps -a --filter "label=com.docker.compose.project=${id}" --format '{{json .}}'`
				});
				if (containers) {
					const containersArray = containers.trim().split('\n');
					if (containersArray.length > 0 && containersArray[0] !== '') {
						const templates = await getTemplates();
						let template = templates.find((t) => t.type === service.type);
						const templateStr = JSON.stringify(template);
						if (templateStr) {
							template = JSON.parse(templateStr.replaceAll('$$id', service.id));
						}
						for (const container of containersArray) {
							let isRunning = false;
							let isExited = false;
							let isRestarting = false;
							let isExcluded = false;
							const containerObj = JSON.parse(container);
							const exclude = template?.services[containerObj.Names]?.exclude;
							if (exclude) {
								payload[containerObj.Names] = {
									status: {
										isExcluded: true,
										isRunning: false,
										isExited: false,
										isRestarting: false
									}
								};
								continue;
							}

							const status = containerObj.State;
							if (status === 'running') {
								isRunning = true;
							}
							if (status === 'exited') {
								isExited = true;
							}
							if (status === 'restarting') {
								isRestarting = true;
							}
							payload[containerObj.Names] = {
								status: {
									isExcluded,
									isRunning,
									isExited,
									isRestarting
								}
							};
						}
					}
				}
			}
			return payload;
		})
});

export async function getServiceFromDB({
	id,
	teamId
}: {
	id: string;
	teamId: string;
}): Promise<any> {
	const settings = await prisma.setting.findFirst();
	const body = await prisma.service.findFirst({
		where: { id, teams: { some: { id: teamId === '0' ? undefined : teamId } } },
		include: {
			destinationDocker: true,
			persistentStorage: true,
			serviceSecret: true,
			serviceSetting: true,
			wordpress: true,
			plausibleAnalytics: true
		}
	});
	if (!body) {
		return null;
	}
	// body.type = fixType(body.type);

	if (body?.serviceSecret.length > 0) {
		body.serviceSecret = body.serviceSecret.map((s) => {
			s.value = decrypt(s.value);
			return s;
		});
	}
	if (body.wordpress) {
		body.wordpress.ftpPassword = decrypt(body.wordpress.ftpPassword);
	}

	return { ...body, settings };
}
