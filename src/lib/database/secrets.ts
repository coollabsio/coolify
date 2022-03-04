import { encrypt, decrypt } from '$lib/crypto';
import { prisma } from './common';

export async function listServiceSecrets(serviceId: string) {
	let secrets = await prisma.serviceSecret.findMany({
		where: { serviceId },
		orderBy: { createdAt: 'desc' }
	});
	secrets = secrets.map((secret) => {
		secret.value = decrypt(secret.value);
		return secret;
	});

	return secrets;
}

export async function listSecrets(applicationId: string) {
	let secrets = await prisma.secret.findMany({
		where: { applicationId },
		orderBy: { createdAt: 'desc' }
	});
	secrets = secrets.map((secret) => {
		secret.value = decrypt(secret.value);
		return secret;
	});

	return secrets;
}

export async function createServiceSecret({ id, name, value }) {
	value = encrypt(value);
	return await prisma.serviceSecret.create({
		data: { name, value, service: { connect: { id } } }
	});
}
export async function createSecret({ id, name, value, isBuildSecret, isPRMRSecret }) {
	value = encrypt(value);
	return await prisma.secret.create({
		data: { name, value, isBuildSecret, isPRMRSecret, application: { connect: { id } } }
	});
}

export async function updateServiceSecret({ id, name, value }) {
	value = encrypt(value);
	const found = await prisma.serviceSecret.findFirst({ where: { serviceId: id, name } });

	if (found) {
		return await prisma.serviceSecret.updateMany({
			where: { serviceId: id, name },
			data: { value }
		});
	} else {
		return await prisma.serviceSecret.create({
			data: { name, value, service: { connect: { id } } }
		});
	}
}
export async function updateSecret({ id, name, value, isBuildSecret, isPRMRSecret }) {
	value = encrypt(value);
	const found = await prisma.secret.findFirst({ where: { applicationId: id, name, isPRMRSecret } });

	if (found) {
		return await prisma.secret.updateMany({
			where: { applicationId: id, name, isPRMRSecret },
			data: { value, isBuildSecret, isPRMRSecret }
		});
	} else {
		return await prisma.secret.create({
			data: { name, value, isBuildSecret, isPRMRSecret, application: { connect: { id } } }
		});
	}
}

export async function removeServiceSecret({ id, name }) {
	return await prisma.serviceSecret.deleteMany({ where: { serviceId: id, name } });
}

export async function removeSecret({ id, name }) {
	return await prisma.secret.deleteMany({ where: { applicationId: id, name } });
}
