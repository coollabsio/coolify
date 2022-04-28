import { encrypt } from '$lib/crypto';
import { generateSshKeyPair, prisma } from './common';
import type { GitlabApp } from '@prisma/client';

export async function updateDeployKey({
	id,
	deployKeyId
}: {
	id: string;
	deployKeyId: number;
}): Promise<GitlabApp> {
	const application = await prisma.application.findUnique({
		where: { id },
		include: { gitSource: { include: { gitlabApp: true } } }
	});
	return await prisma.gitlabApp.update({
		where: { id: application.gitSource.gitlabApp.id },
		data: { deployKeyId }
	});
}
export async function getSshKey({
	id
}: {
	id: string;
}): Promise<{ status: number; body: { publicKey: string } }> {
	const application = await prisma.application.findUnique({
		where: { id },
		include: { gitSource: { include: { gitlabApp: true } } }
	});
	return { status: 200, body: { publicKey: application.gitSource.gitlabApp.publicSshKey } };
}
export async function generateSshKey({
	id
}: {
	id: string;
}): Promise<
	{ status: number; body: { publicKey: string } } | { status: number; body?: undefined }
> {
	const application = await prisma.application.findUnique({
		where: { id },
		include: { gitSource: { include: { gitlabApp: true } } }
	});
	if (!application.gitSource?.gitlabApp?.privateSshKey) {
		const keys = await generateSshKeyPair();
		const encryptedPrivateKey = encrypt(keys.privateKey);
		await prisma.gitlabApp.update({
			where: { id: application.gitSource.gitlabApp.id },
			data: { privateSshKey: encryptedPrivateKey, publicSshKey: keys.publicKey }
		});
		return { status: 201, body: { publicKey: keys.publicKey } };
	} else {
		return { status: 200 };
	}
}
