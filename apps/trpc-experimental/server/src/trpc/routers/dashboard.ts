import { privateProcedure, router } from '../trpc';
import { listSettings } from '../../lib/common';
import { prisma } from '../../prisma';

export const dashboardRouter = router({
	resources: privateProcedure.query(async ({ ctx }) => {
		const id = ctx.user?.teamId === '0' ? undefined : ctx.user?.teamId;
		let applications = await prisma.application.findMany({
			where: { teams: { some: { id } } },
			include: { settings: true, destinationDocker: true, teams: true }
		});
		const databases = await prisma.database.findMany({
			where: { teams: { some: { id } } },
			include: { settings: true, destinationDocker: true, teams: true }
		});
		const services = await prisma.service.findMany({
			where: { teams: { some: { id } } },
			include: { destinationDocker: true, teams: true }
		});
		const gitSources = await prisma.gitSource.findMany({
			where: {
				OR: [{ teams: { some: { id } } }, { isSystemWide: true }]
			},
			include: { teams: true }
		});
		const destinations = await prisma.destinationDocker.findMany({
			where: { teams: { some: { id } } },
			include: { teams: true }
		});
		const settings = await listSettings();
		let foundUnconfiguredApplication = false;
		for (const application of applications) {
			if (
				((!application.buildPack || !application.branch) && !application.simpleDockerfile) ||
				!application.destinationDockerId ||
				(!application.settings?.isBot && !application?.fqdn && application.buildPack !== 'compose')
			) {
				foundUnconfiguredApplication = true;
			}
		}
		let foundUnconfiguredService = false;
		for (const service of services) {
			if (!service.fqdn) {
				foundUnconfiguredService = true;
			}
		}
		let foundUnconfiguredDatabase = false;
		for (const database of databases) {
			if (!database.version) {
				foundUnconfiguredDatabase = true;
			}
		}
		return {
			foundUnconfiguredApplication,
			foundUnconfiguredDatabase,
			foundUnconfiguredService,
			applications,
			databases,
			services,
			gitSources,
			destinations,
			settings
		};
	})
});
