import { getDomain } from '$lib/common';
import { prisma, PrismaErrorHandler } from './common';

export async function isBranchAlreadyUsed({ repository, branch, id }) {
	const application = await prisma.application.findUnique({
		where: { id },
		include: { gitSource: true }
	});
	return await prisma.application.findFirst({
		where: { branch, repository, gitSource: { type: application.gitSource.type }, id: { not: id } }
	});
}

export async function isDockerNetworkExists({ network }) {
	return await prisma.destinationDocker.findFirst({ where: { network } });
}

export async function isSecretExists({ id, name }) {
	return await prisma.secret.findFirst({ where: { name, applicationId: id } });
}

export async function isDomainConfigured({ id, fqdn }) {
	const domain = getDomain(fqdn);
	const foundApp = await prisma.application.findFirst({
		where: { fqdn: { endsWith: domain }, id: { not: id } },
		select: { fqdn: true }
	});
	const foundService = await prisma.service.findFirst({
		where: { fqdn: { endsWith: domain }, id: { not: id } },
		select: { fqdn: true }
	});
	const coolifyFqdn = await prisma.setting.findFirst({
		where: { fqdn: { endsWith: domain }, id: { not: id } },
		select: { fqdn: true }
	});
	if (foundApp || foundService || coolifyFqdn) return true;
	return false;
}
