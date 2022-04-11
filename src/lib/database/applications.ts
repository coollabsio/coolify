import { decrypt, encrypt } from '$lib/crypto';
import { asyncExecShell, getEngine } from '$lib/common';

import { removeDestinationDocker } from '$lib/common';
import { prisma } from './common';

import type {
	DestinationDocker,
	GitSource,
	Secret,
	ApplicationSettings,
	Application,
	ApplicationPersistentStorage
} from '@prisma/client';

export async function listApplications(teamId: string): Promise<Application[]> {
	if (teamId === '0') {
		return await prisma.application.findMany({ include: { teams: true } });
	}
	return await prisma.application.findMany({
		where: { teams: { some: { id: teamId } } },
		include: { teams: true }
	});
}

export async function newApplication({
	name,
	teamId
}: {
	name: string;
	teamId: string;
}): Promise<Application> {
	return await prisma.application.create({
		data: {
			name,
			teams: { connect: { id: teamId } },
			settings: { create: { debug: false, previews: false } }
		}
	});
}

export async function removeApplication({
	id,
	teamId
}: {
	id: string;
	teamId: string;
}): Promise<void> {
	const { destinationDockerId, destinationDocker } = await prisma.application.findUnique({
		where: { id },
		include: { destinationDocker: true }
	});
	if (destinationDockerId) {
		const host = getEngine(destinationDocker.engine);
		const { stdout: containers } = await asyncExecShell(
			`DOCKER_HOST=${host} docker ps -a --filter network=${destinationDocker.network} --filter name=${id} --format '{{json .}}'`
		);
		if (containers) {
			const containersArray = containers.trim().split('\n');
			for (const container of containersArray) {
				const containerObj = JSON.parse(container);
				const id = containerObj.ID;
				await removeDestinationDocker({ id, engine: destinationDocker.engine });
			}
		}
	}

	await prisma.applicationSettings.deleteMany({ where: { application: { id } } });
	await prisma.buildLog.deleteMany({ where: { applicationId: id } });
	await prisma.build.deleteMany({ where: { applicationId: id } });
	await prisma.secret.deleteMany({ where: { applicationId: id } });
	await prisma.applicationPersistentStorage.deleteMany({ where: { applicationId: id } });
	if (teamId === '0') {
		await prisma.application.deleteMany({ where: { id } });
	} else {
		await prisma.application.deleteMany({ where: { id, teams: { some: { id: teamId } } } });
	}
}

export async function getApplicationWebhook({
	projectId,
	branch
}: {
	projectId: number;
	branch: string;
}): Promise<
	Application & {
		destinationDocker: DestinationDocker;
		settings: ApplicationSettings;
		gitSource: GitSource;
		secrets: Secret[];
		persistentStorage: ApplicationPersistentStorage[];
	}
> {
	try {
		const application = await prisma.application.findFirst({
			where: { projectId, branch, settings: { autodeploy: true } },
			include: {
				destinationDocker: true,
				settings: true,
				gitSource: { include: { githubApp: true, gitlabApp: true } },
				secrets: true,
				persistentStorage: true
			}
		});
		if (!application) {
			return null;
		}
		if (application?.gitSource?.githubApp?.clientSecret) {
			application.gitSource.githubApp.clientSecret = decrypt(
				application.gitSource.githubApp.clientSecret
			);
		}
		if (application?.gitSource?.githubApp?.webhookSecret) {
			application.gitSource.githubApp.webhookSecret = decrypt(
				application.gitSource.githubApp.webhookSecret
			);
		}
		if (application?.gitSource?.githubApp?.privateKey) {
			application.gitSource.githubApp.privateKey = decrypt(
				application.gitSource.githubApp.privateKey
			);
		}
		if (application?.gitSource?.gitlabApp?.appSecret) {
			application.gitSource.gitlabApp.appSecret = decrypt(
				application.gitSource.gitlabApp.appSecret
			);
		}
		if (application?.gitSource?.gitlabApp?.webhookToken) {
			application.gitSource.gitlabApp.webhookToken = decrypt(
				application.gitSource.gitlabApp.webhookToken
			);
		}
		if (application?.secrets.length > 0) {
			application.secrets = application.secrets.map((s) => {
				s.value = decrypt(s.value);
				return s;
			});
		}
		return { ...application };
	} catch (e) {
		throw { status: 404, body: { message: e.message } };
	}
}

export async function getApplication({ id, teamId }: { id: string; teamId: string }): Promise<
	Application & {
		destinationDocker: DestinationDocker;
		settings: ApplicationSettings;
		gitSource: GitSource;
		secrets: Secret[];
		persistentStorage: ApplicationPersistentStorage[];
	}
> {
	let body;
	if (teamId === '0') {
		body = await prisma.application.findFirst({
			where: { id },
			include: {
				destinationDocker: true,
				settings: true,
				gitSource: { include: { githubApp: true, gitlabApp: true } },
				secrets: true,
				persistentStorage: true
			}
		});
	} else {
		body = await prisma.application.findFirst({
			where: { id, teams: { some: { id: teamId } } },
			include: {
				destinationDocker: true,
				settings: true,
				gitSource: { include: { githubApp: true, gitlabApp: true } },
				secrets: true,
				persistentStorage: true
			}
		});
	}

	if (body?.gitSource?.githubApp?.clientSecret) {
		body.gitSource.githubApp.clientSecret = decrypt(body.gitSource.githubApp.clientSecret);
	}
	if (body?.gitSource?.githubApp?.webhookSecret) {
		body.gitSource.githubApp.webhookSecret = decrypt(body.gitSource.githubApp.webhookSecret);
	}
	if (body?.gitSource?.githubApp?.privateKey) {
		body.gitSource.githubApp.privateKey = decrypt(body.gitSource.githubApp.privateKey);
	}
	if (body?.gitSource?.gitlabApp?.appSecret) {
		body.gitSource.gitlabApp.appSecret = decrypt(body.gitSource.gitlabApp.appSecret);
	}
	if (body?.secrets.length > 0) {
		body.secrets = body.secrets.map((s) => {
			s.value = decrypt(s.value);
			return s;
		});
	}

	return { ...body };
}

export async function configureGitRepository({
	id,
	repository,
	branch,
	projectId,
	webhookToken,
	autodeploy
}: {
	id: string;
	repository: string;
	branch: string;
	projectId: number;
	webhookToken: string;
	autodeploy: boolean;
}): Promise<void> {
	if (webhookToken) {
		const encryptedWebhookToken = encrypt(webhookToken);
		await prisma.application.update({
			where: { id },
			data: {
				repository,
				branch,
				projectId,
				gitSource: { update: { gitlabApp: { update: { webhookToken: encryptedWebhookToken } } } },
				settings: { update: { autodeploy } }
			}
		});
	} else {
		await prisma.application.update({
			where: { id },
			data: { repository, branch, projectId, settings: { update: { autodeploy } } }
		});
	}
	if (!autodeploy) {
		const applications = await prisma.application.findMany({ where: { branch, projectId } });
		for (const application of applications) {
			await prisma.applicationSettings.updateMany({
				where: { applicationId: application.id },
				data: { autodeploy: false }
			});
		}
	}
}

export async function configureBuildPack({
	id,
	buildPack
}: Pick<Application, 'id' | 'buildPack'>): Promise<Application> {
	return await prisma.application.update({ where: { id }, data: { buildPack } });
}

export async function configureApplication({
	id,
	buildPack,
	name,
	fqdn,
	port,
	installCommand,
	buildCommand,
	startCommand,
	baseDirectory,
	publishDirectory,
	pythonWSGI,
	pythonModule,
	pythonVariable
}: {
	id: string;
	buildPack: string;
	name: string;
	fqdn: string;
	port: number;
	installCommand: string;
	buildCommand: string;
	startCommand: string;
	baseDirectory: string;
	publishDirectory: string;
	pythonWSGI: string;
	pythonModule: string;
	pythonVariable: string;
}): Promise<Application> {
	return await prisma.application.update({
		where: { id },
		data: {
			name,
			buildPack,
			fqdn,
			port,
			installCommand,
			buildCommand,
			startCommand,
			baseDirectory,
			publishDirectory,
			pythonWSGI,
			pythonModule,
			pythonVariable
		}
	});
}

export async function checkDoubleBranch(branch: string, projectId: number): Promise<boolean> {
	const applications = await prisma.application.findMany({ where: { branch, projectId } });
	return applications.length > 1;
}

export async function setApplicationSettings({
	id,
	debug,
	previews,
	dualCerts,
	autodeploy
}: {
	id: string;
	debug: boolean;
	previews: boolean;
	dualCerts: boolean;
	autodeploy: boolean;
}): Promise<Application & { destinationDocker: DestinationDocker }> {
	return await prisma.application.update({
		where: { id },
		data: { settings: { update: { debug, previews, dualCerts, autodeploy } } },
		include: { destinationDocker: true }
	});
}

export async function getPersistentStorage(id: string): Promise<ApplicationPersistentStorage[]> {
	return await prisma.applicationPersistentStorage.findMany({ where: { applicationId: id } });
}
