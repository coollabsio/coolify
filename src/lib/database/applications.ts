import { decrypt, encrypt } from '$lib/crypto';
import { removeProxyConfiguration } from '$lib/haproxy';
import { asyncExecShell, getEngine } from '$lib/common';

import { getDomain, removeDestinationDocker } from '$lib/common';
import { prisma } from './common';

export async function listApplications(teamId) {
	return await prisma.application.findMany({ where: { teams: { some: { id: teamId } } } });
}

export async function newApplication({ name, teamId }) {
	return await prisma.application.create({
		data: {
			name,
			teams: { connect: { id: teamId } },
			settings: { create: { debug: false, previews: false } }
		}
	});
}

export async function importApplication({
	name,
	teamId,
	fqdn,
	port,
	buildCommand,
	startCommand,
	installCommand
}) {
	return await prisma.application.create({
		data: {
			name,
			fqdn,
			port,
			buildCommand,
			startCommand,
			installCommand,
			teams: { connect: { id: teamId } }
		}
	});
}

export async function removeApplication({ id, teamId }) {
	const { fqdn, destinationDockerId, destinationDocker } = await prisma.application.findUnique({
		where: { id },
		include: { destinationDocker: true }
	});
	const domain = getDomain(fqdn);
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
				const preview = containerObj.Image.split('-')[1];
				await removeDestinationDocker({ id, engine: destinationDocker.engine });
				try {
					if (preview) {
						await removeProxyConfiguration({ domain: `${preview}.${domain}` });
					} else {
						await removeProxyConfiguration({ domain });
					}
				} catch (error) {
					console.log(error);
				}
			}
		}
	}

	await prisma.applicationSettings.deleteMany({ where: { application: { id } } });
	await prisma.buildLog.deleteMany({ where: { applicationId: id } });
	await prisma.secret.deleteMany({ where: { applicationId: id } });
	await prisma.application.deleteMany({ where: { id, teams: { some: { id: teamId } } } });
}

export async function getApplicationWebhook({ projectId, branch }) {
	try {
		let body = await prisma.application.findFirst({
			where: { projectId, branch },
			include: {
				destinationDocker: true,
				settings: true,
				gitSource: { include: { githubApp: true, gitlabApp: true } },
				secrets: true
			}
		});

		if (body.gitSource?.githubApp?.clientSecret) {
			body.gitSource.githubApp.clientSecret = decrypt(body.gitSource.githubApp.clientSecret);
		}
		if (body.gitSource?.githubApp?.webhookSecret) {
			body.gitSource.githubApp.webhookSecret = decrypt(body.gitSource.githubApp.webhookSecret);
		}
		if (body.gitSource?.githubApp?.privateKey) {
			body.gitSource.githubApp.privateKey = decrypt(body.gitSource.githubApp.privateKey);
		}
		if (body?.gitSource?.gitlabApp?.appSecret) {
			body.gitSource.gitlabApp.appSecret = decrypt(body.gitSource.gitlabApp.appSecret);
		}
		if (body?.gitSource?.gitlabApp?.webhookToken) {
			body.gitSource.gitlabApp.webhookToken = decrypt(body.gitSource.gitlabApp.webhookToken);
		}
		if (body?.secrets.length > 0) {
			body.secrets = body.secrets.map((s) => {
				s.value = decrypt(s.value);
				return s;
			});
		}

		return { ...body };
	} catch (e) {
		throw { status: 404, body: { message: e.message } };
	}
}
export async function getApplicationById({ id }) {
	const body = await prisma.application.findFirst({
		where: { id }
	});

	return { ...body };
}
export async function getApplication({ id, teamId }) {
	let body = await prisma.application.findFirst({
		where: { id, teams: { some: { id: teamId } } },
		include: {
			destinationDocker: true,
			settings: true,
			gitSource: { include: { githubApp: true, gitlabApp: true } },
			secrets: true
		}
	});

	if (body.gitSource?.githubApp?.clientSecret) {
		body.gitSource.githubApp.clientSecret = decrypt(body.gitSource.githubApp.clientSecret);
	}
	if (body.gitSource?.githubApp?.webhookSecret) {
		body.gitSource.githubApp.webhookSecret = decrypt(body.gitSource.githubApp.webhookSecret);
	}
	if (body.gitSource?.githubApp?.privateKey) {
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

export async function configureGitRepository({ id, repository, branch, projectId, webhookToken }) {
	if (webhookToken) {
		const encryptedWebhookToken = encrypt(webhookToken);
		return await prisma.application.update({
			where: { id },
			data: {
				repository,
				branch,
				projectId,
				gitSource: { update: { gitlabApp: { update: { webhookToken: encryptedWebhookToken } } } }
			}
		});
	} else {
		return await prisma.application.update({
			where: { id },
			data: { repository, branch, projectId }
		});
	}
}

export async function configureBuildPack({ id, buildPack }) {
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
	publishDirectory
}) {
	return await prisma.application.update({
		where: { id },
		data: {
			buildPack,
			fqdn,
			port,
			installCommand,
			buildCommand,
			startCommand,
			baseDirectory,
			publishDirectory,
			name
		}
	});
}

export async function setApplicationSettings({ id, debug, previews, dualCerts }) {
	return await prisma.application.update({
		where: { id },
		data: { settings: { update: { debug, previews, dualCerts } } },
		include: { destinationDocker: true }
	});
}

export async function createBuild({
	id,
	applicationId,
	destinationDockerId,
	gitSourceId,
	githubAppId,
	gitlabAppId,
	type
}) {
	return await prisma.build.create({
		data: {
			id,
			applicationId,
			destinationDockerId,
			gitSourceId,
			githubAppId,
			gitlabAppId,
			status: 'running',
			type
		}
	});
}
