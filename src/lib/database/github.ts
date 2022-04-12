import { decrypt, encrypt } from '$lib/crypto';
import { prisma } from './common';
import type { GithubApp } from '@prisma/client';

// TODO: We should change installation_id to be camelCase
export async function addInstallation({
	gitSourceId,
	installation_id
}: {
	gitSourceId: string;
	installation_id: string;
}): Promise<GithubApp> {
	const source = await prisma.gitSource.findUnique({
		where: { id: gitSourceId },
		include: { githubApp: true }
	});
	return await prisma.githubApp.update({
		where: { id: source.githubAppId },
		data: { installationId: Number(installation_id) }
	});
}

export async function getUniqueGithubApp({
	githubAppId
}: {
	githubAppId: string;
}): Promise<GithubApp> {
	const body = await prisma.githubApp.findUnique({ where: { id: githubAppId } });
	if (body.privateKey) body.privateKey = decrypt(body.privateKey);
	return body;
}

export async function createGithubApp({
	id,
	client_id,
	slug,
	client_secret,
	pem,
	webhook_secret,
	state
}: {
	id: number;
	client_id: string;
	slug: string;
	client_secret: string;
	pem: string;
	webhook_secret: string;
	state: string;
}): Promise<GithubApp> {
	const encryptedClientSecret = encrypt(client_secret);
	const encryptedWebhookSecret = encrypt(webhook_secret);
	const encryptedPem = encrypt(pem);
	return await prisma.githubApp.create({
		data: {
			appId: id,
			name: slug,
			clientId: client_id,
			clientSecret: encryptedClientSecret,
			webhookSecret: encryptedWebhookSecret,
			privateKey: encryptedPem,
			gitSource: { connect: { id: state } }
		}
	});
}
