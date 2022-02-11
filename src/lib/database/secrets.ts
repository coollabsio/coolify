import { encrypt } from '$lib/crypto';
import { prisma } from './common';

export async function listSecrets({ applicationId }) {
	return await prisma.secret.findMany({
		where: { applicationId },
		orderBy: { createdAt: 'desc' },
		select: { id: true, createdAt: true, name: true, isBuildSecret: true }
	});
}

export async function createSecret({ id, name, value, isBuildSecret }) {
	value = encrypt(value);
	return await prisma.secret.create({
		data: { name, value, isBuildSecret, application: { connect: { id } } }
	});
}

export async function removeSecret({ id, name }) {
	return await prisma.secret.deleteMany({ where: { applicationId: id, name } });
}
