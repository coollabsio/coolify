import { getDomain } from '$lib/common';
import { prisma } from './common';

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

export async function isServiceSecretExists({ id, name }) {
	return await prisma.serviceSecret.findFirst({ where: { name, serviceId: id } });
}
export async function isSecretExists({ id, name, isPRMRSecret }) {
	return await prisma.secret.findFirst({ where: { name, applicationId: id, isPRMRSecret } });
}

export async function isDomainConfigured({ id, fqdn }) {
	const domain = getDomain(fqdn);
	const nakedDomain = domain.replace('www.', '');
	const foundApp = await prisma.application.findFirst({
		where: {
			OR: [
				{ fqdn: { endsWith: `//${nakedDomain}` } },
				{ fqdn: { endsWith: `//www.${nakedDomain}` } }
			],
			id: { not: id }
		},
		select: { fqdn: true }
	});
	const foundService = await prisma.service.findFirst({
		where: {
			OR: [
				{ fqdn: { endsWith: `//${nakedDomain}` } },
				{ fqdn: { endsWith: `//www.${nakedDomain}` } }
			],
			id: { not: id }
		},
		select: { fqdn: true }
	});
	const coolifyFqdn = await prisma.setting.findFirst({
		where: {
			OR: [
				{ fqdn: { endsWith: `//${nakedDomain}` } },
				{ fqdn: { endsWith: `//www.${nakedDomain}` } }
			],
			id: { not: id }
		},
		select: { fqdn: true }
	});
	if (foundApp || foundService || coolifyFqdn) return true;
	return false;
}
