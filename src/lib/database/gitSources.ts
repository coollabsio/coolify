import { decrypt, encrypt } from '$lib/crypto';
import { prisma, PrismaErrorHandler } from './common';

export async function listSources(teamId) {
	return await prisma.gitSource.findMany({
		where: { teams: { some: { id: teamId } } },
		include: { githubApp: true, gitlabApp: true }
	});
}

export async function newSource({ name, teamId, type, htmlUrl, apiUrl, organization }) {
	return await prisma.gitSource.create({
		data: {
			teams: { connect: { id: teamId } },
			name,
			type,
			htmlUrl,
			apiUrl,
			organization
		}
	});
}
export async function removeSource({ id }) {
	// TODO: Disconnect application with this sourceId! Maybe not needed?
	const source = await prisma.gitSource.delete({
		where: { id },
		include: { githubApp: true, gitlabApp: true }
	});
	if (source.githubAppId) await prisma.githubApp.delete({ where: { id: source.githubAppId } });
	if (source.gitlabAppId) await prisma.gitlabApp.delete({ where: { id: source.gitlabAppId } });
}

export async function getSource({ id, teamId }) {
	let body = await prisma.gitSource.findFirst({
		where: { id, teams: { some: { id: teamId } } },
		include: { githubApp: true, gitlabApp: true }
	});
	if (body?.githubApp?.clientSecret)
		body.githubApp.clientSecret = decrypt(body.githubApp.clientSecret);
	if (body?.githubApp?.webhookSecret)
		body.githubApp.webhookSecret = decrypt(body.githubApp.webhookSecret);
	if (body?.githubApp?.privateKey) body.githubApp.privateKey = decrypt(body.githubApp.privateKey);
	if (body?.gitlabApp?.appSecret) body.gitlabApp.appSecret = decrypt(body.gitlabApp.appSecret);
	return body;
}
export async function addSource({ id, appId, teamId, oauthId, groupName, appSecret }) {
	const encrptedAppSecret = encrypt(appSecret);
	return await prisma.gitlabApp.create({
		data: {
			teams: { connect: { id: teamId } },
			appId,
			oauthId,
			groupName,
			appSecret: encrptedAppSecret,
			gitSource: { connect: { id } }
		}
	});
}

export async function configureGitsource({ id, gitSourceId }) {
	return await prisma.application.update({
		where: { id },
		data: { gitSource: { connect: { id: gitSourceId } } }
	});
}
export async function updateGitsource({ id, name }) {
	return await prisma.gitSource.update({
		where: { id },
		data: { name }
	});
}
