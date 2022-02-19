import { encrypt, decrypt } from '$lib/crypto';
import { prisma } from './common';

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

export async function createSecret({ id, name, value, isBuildSecret, isPRMRSecret }) {
	value = encrypt(value);
	return await prisma.secret.create({
		data: { name, value, isBuildSecret, isPRMRSecret, application: { connect: { id } } }
	});
}

export async function updateSecret({ id, name, value, isBuildSecret, isPRMRSecret }) {
	value = encrypt(value);
	const found = await prisma.secret.findFirst({ where: { applicationId: id, name, isPRMRSecret } });
	console.log(found);

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

export async function removeSecret({ id, name }) {
	return await prisma.secret.deleteMany({ where: { applicationId: id, name } });
}
