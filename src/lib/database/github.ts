import { decrypt, encrypt } from '$lib/crypto';
import { prisma, PrismaErrorHandler } from './common';

export async function addInstallation({ gitSourceId, installation_id }) {
	const source = await prisma.gitSource.findUnique({
		where: { id: gitSourceId },
		include: { githubApp: true }
	});
	return await prisma.githubApp.update({
		where: { id: source.githubAppId },
		data: { installationId: Number(installation_id) }
	});
}

export async function getUniqueGithubApp({ githubAppId }) {
	let body = await prisma.githubApp.findUnique({ where: { id: githubAppId } });
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
}) {
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
