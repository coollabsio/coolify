import { encrypt, decrypt } from '$lib/crypto';
import { prisma } from './common';
import type { ServiceSecret, Secret, Prisma } from '@prisma/client';

export async function listServiceSecrets(serviceId: string): Promise<ServiceSecret[]> {
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

export async function listSecrets(applicationId: string): Promise<Secret[]> {
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

export async function createServiceSecret({
	id,
	name,
	value
}: {
	id: string;
	name: string;
	value: string;
}): Promise<ServiceSecret> {
	value = encrypt(value);
	return await prisma.serviceSecret.create({
		data: { name, value, service: { connect: { id } } }
	});
}
export async function createSecret({
	id,
	name,
	value,
	isBuildSecret,
	isPRMRSecret
}: {
	id: string;
	name: string;
	value: string;
	isBuildSecret: boolean;
	isPRMRSecret: boolean;
}): Promise<Secret> {
	value = encrypt(value);
	return await prisma.secret.create({
		data: { name, value, isBuildSecret, isPRMRSecret, application: { connect: { id } } }
	});
}

export async function updateServiceSecret({
	id,
	name,
	value
}: {
	id: string;
	name: string;
	value: string;
}): Promise<Prisma.BatchPayload | ServiceSecret> {
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
export async function updateSecret({
	id,
	name,
	value,
	isBuildSecret,
	isPRMRSecret
}: {
	id: string;
	name: string;
	value: string;
	isBuildSecret: boolean;
	isPRMRSecret: boolean;
}): Promise<Prisma.BatchPayload | Secret> {
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

export async function removeServiceSecret({
	id,
	name
}: {
	id: string;
	name: string;
}): Promise<Prisma.BatchPayload> {
	return await prisma.serviceSecret.deleteMany({ where: { serviceId: id, name } });
}

export async function removeSecret({
	id,
	name
}: {
	id: string;
	name: string;
}): Promise<Prisma.BatchPayload> {
	return await prisma.secret.deleteMany({ where: { applicationId: id, name } });
}
