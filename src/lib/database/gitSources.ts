import { decrypt, encrypt } from '$lib/crypto';
import { prisma } from './common';
import type { GithubApp, GitlabApp, GitSource, Prisma, Application } from '@prisma/client';

export async function listSources(
	teamId: string | Prisma.StringFilter
): Promise<(GitSource & { githubApp?: GithubApp; gitlabApp?: GitlabApp })[]> {
	if (teamId === '0') {
		return await prisma.gitSource.findMany({
			include: { githubApp: true, gitlabApp: true, teams: true }
		});
	}
	return await prisma.gitSource.findMany({
		where: { teams: { some: { id: teamId } } },
		include: { githubApp: true, gitlabApp: true, teams: true }
	});
}

export async function newSource({
	name,
	teamId,
	type,
	htmlUrl,
	apiUrl,
	organization
}: {
	name: string;
	teamId: string;
	type: string;
	htmlUrl: string;
	apiUrl: string;
	organization: string;
}): Promise<GitSource> {
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
export async function removeSource({ id }: { id: string }): Promise<void> {
	// TODO: Disconnect application with this sourceId! Maybe not needed?
	const source = await prisma.gitSource.delete({
		where: { id },
		include: { githubApp: true, gitlabApp: true }
	});
	if (source.githubAppId) await prisma.githubApp.delete({ where: { id: source.githubAppId } });
	if (source.gitlabAppId) await prisma.gitlabApp.delete({ where: { id: source.gitlabAppId } });
}

export async function getSource({
	id,
	teamId
}: {
	id: string;
	teamId: string;
}): Promise<GitSource & { githubApp: GithubApp; gitlabApp: GitlabApp }> {
	let body;
	if (teamId === '0') {
		body = await prisma.gitSource.findFirst({
			where: { id },
			include: { githubApp: true, gitlabApp: true }
		});
	} else {
		body = await prisma.gitSource.findFirst({
			where: { id, teams: { some: { id: teamId } } },
			include: { githubApp: true, gitlabApp: true }
		});
	}
	if (body?.githubApp?.clientSecret)
		body.githubApp.clientSecret = decrypt(body.githubApp.clientSecret);
	if (body?.githubApp?.webhookSecret)
		body.githubApp.webhookSecret = decrypt(body.githubApp.webhookSecret);
	if (body?.githubApp?.privateKey) body.githubApp.privateKey = decrypt(body.githubApp.privateKey);
	if (body?.gitlabApp?.appSecret) body.gitlabApp.appSecret = decrypt(body.gitlabApp.appSecret);
	return body;
}
export async function addSource({
	id,
	appId,
	teamId,
	oauthId,
	groupName,
	appSecret
}: {
	id: string;
	appId: string;
	teamId: string;
	oauthId: number;
	groupName: string;
	appSecret: string;
}): Promise<GitlabApp> {
	const encryptedAppSecret = encrypt(appSecret);
	return await prisma.gitlabApp.create({
		data: {
			teams: { connect: { id: teamId } },
			appId,
			oauthId,
			groupName,
			appSecret: encryptedAppSecret,
			gitSource: { connect: { id } }
		}
	});
}

export async function configureGitsource({
	id,
	gitSourceId
}: {
	id: string;
	gitSourceId: string;
}): Promise<Application> {
	return await prisma.application.update({
		where: { id },
		data: { gitSource: { connect: { id: gitSourceId } } }
	});
}
export async function updateGitsource({
	id,
	name
}: {
	id: string;
	name: string;
}): Promise<GitSource> {
	return await prisma.gitSource.update({
		where: { id },
		data: { name }
	});
}
