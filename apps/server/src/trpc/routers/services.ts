import { z } from 'zod';
import { privateProcedure, router } from '../trpc';
import { decrypt, getTemplates, removeService } from '../../lib/common';
import { prisma } from '../../prisma';
import { executeCommand } from '../../lib/executeCommand';

export const servicesRouter = router({
	status: privateProcedure.input(z.object({ id: z.string() })).query(async ({ ctx, input }) => {
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
					let template = templates.find((t: { type: string }) => t.type === service.type);
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
	}),
	cleanup: privateProcedure.query(async ({ ctx }) => {
		const teamId = ctx.user?.teamId;
		let services = await prisma.service.findMany({
			where: { teams: { some: { id: teamId === '0' ? undefined : teamId } } },
			include: { destinationDocker: true, teams: true }
		});
		for (const service of services) {
			if (!service.fqdn) {
				if (service.destinationDockerId) {
					const { stdout: containers } = await executeCommand({
						dockerId: service.destinationDockerId,
						command: `docker ps -a --filter 'label=com.docker.compose.project=${service.id}' --format {{.ID}}`
					});
					if (containers) {
						const containerArray = containers.split('\n');
						if (containerArray.length > 0) {
							for (const container of containerArray) {
								await executeCommand({
									dockerId: service.destinationDockerId,
									command: `docker stop -t 0 ${container}`
								});
								await executeCommand({
									dockerId: service.destinationDockerId,
									command: `docker rm --force ${container}`
								});
							}
						}
					}
				}
				await removeService({ id: service.id });
			}
		}
	}),
	delete: privateProcedure
		.input(z.object({ force: z.boolean(), id: z.string() }))
		.mutation(async ({ input }) => {
			// todo: check if user is allowed to delete service
			const { id } = input;
			await prisma.serviceSecret.deleteMany({ where: { serviceId: id } });
			await prisma.serviceSetting.deleteMany({ where: { serviceId: id } });
			await prisma.servicePersistentStorage.deleteMany({ where: { serviceId: id } });
			await prisma.meiliSearch.deleteMany({ where: { serviceId: id } });
			await prisma.fider.deleteMany({ where: { serviceId: id } });
			await prisma.ghost.deleteMany({ where: { serviceId: id } });
			await prisma.umami.deleteMany({ where: { serviceId: id } });
			await prisma.hasura.deleteMany({ where: { serviceId: id } });
			await prisma.plausibleAnalytics.deleteMany({ where: { serviceId: id } });
			await prisma.minio.deleteMany({ where: { serviceId: id } });
			await prisma.vscodeserver.deleteMany({ where: { serviceId: id } });
			await prisma.wordpress.deleteMany({ where: { serviceId: id } });
			await prisma.glitchTip.deleteMany({ where: { serviceId: id } });
			await prisma.moodle.deleteMany({ where: { serviceId: id } });
			await prisma.appwrite.deleteMany({ where: { serviceId: id } });
			await prisma.searxng.deleteMany({ where: { serviceId: id } });
			await prisma.weblate.deleteMany({ where: { serviceId: id } });
			await prisma.taiga.deleteMany({ where: { serviceId: id } });

			await prisma.service.delete({ where: { id } });
			return {};
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
