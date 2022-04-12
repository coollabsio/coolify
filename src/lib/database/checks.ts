import { getDomain } from '$lib/common';
import { prisma } from './common';
import type { Application, ServiceSecret, DestinationDocker, Secret } from '@prisma/client';

export async function isBranchAlreadyUsed({
	repository,
	branch,
	id
}: {
	id: string;
	repository: string;
	branch: string;
}): Promise<Application> {
	const application = await prisma.application.findUnique({
		where: { id },
		include: { gitSource: true }
	});
	return await prisma.application.findFirst({
		where: { branch, repository, gitSource: { type: application.gitSource.type }, id: { not: id } }
	});
}

export async function isDockerNetworkExists({
	network
}: {
	network: string;
}): Promise<DestinationDocker> {
	return await prisma.destinationDocker.findFirst({ where: { network } });
}

export async function isServiceSecretExists({
	id,
	name
}: {
	id: string;
	name: string;
}): Promise<ServiceSecret> {
	return await prisma.serviceSecret.findFirst({ where: { name, serviceId: id } });
}
export async function isSecretExists({
	id,
	name,
	isPRMRSecret
}: {
	id: string;
	name: string;
	isPRMRSecret: boolean;
}): Promise<Secret> {
	return await prisma.secret.findFirst({ where: { name, applicationId: id, isPRMRSecret } });
}

export async function isDomainConfigured({
	id,
	fqdn
}: {
	id: string;
	fqdn: string;
}): Promise<boolean> {
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
	return !!(foundApp || foundService || coolifyFqdn);
}
