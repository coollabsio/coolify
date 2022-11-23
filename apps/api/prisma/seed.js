const dotEnvExtended = require('dotenv-extended');
dotEnvExtended.load();
const crypto = require('crypto');
const { PrismaClient } = require('@prisma/client');
const prisma = new PrismaClient();
const algorithm = 'aes-256-ctr';

async function main() {
	// Enable registration for the first user
	const settingsFound = await prisma.setting.findFirst({});
	if (!settingsFound) {
		await prisma.setting.create({
			data: {
				isRegistrationEnabled: true,
				arch: process.arch,
				DNSServers: '1.1.1.1,8.8.8.8'
			}
		});
	} else {
		await prisma.setting.update({
			where: {
				id: settingsFound.id
			},
			data: {
				isTraefikUsed: true,
				proxyHash: null
			}
		});
	}
	const localDocker = await prisma.destinationDocker.findFirst({
		where: { engine: '/var/run/docker.sock' }
	});
	if (!localDocker) {
		await prisma.destinationDocker.create({
			data: {
				engine: '/var/run/docker.sock',
				name: 'Local Docker',
				isCoolifyProxyUsed: true,
				network: 'coolify'
			}
		});
	}

	// Set auto-update based on env variable
	const isAutoUpdateEnabled = process.env['COOLIFY_AUTO_UPDATE'] === 'true';
	const settings = await prisma.setting.findFirst({});
	if (settings) {
		await prisma.setting.update({
			where: {
				id: settings.id
			},
			data: {
				isAutoUpdateEnabled
			}
		});
	}
	const github = await prisma.gitSource.findFirst({
		where: { htmlUrl: 'https://github.com', forPublic: true }
	});
	const gitlab = await prisma.gitSource.findFirst({
		where: { htmlUrl: 'https://gitlab.com', forPublic: true }
	});
	if (!github) {
		await prisma.gitSource.create({
			data: {
				apiUrl: 'https://api.github.com',
				htmlUrl: 'https://github.com',
				forPublic: true,
				name: 'Github Public',
				type: 'github'
			}
		});
	}
	if (!gitlab) {
		await prisma.gitSource.create({
			data: {
				apiUrl: 'https://gitlab.com/api/v4',
				htmlUrl: 'https://gitlab.com',
				forPublic: true,
				name: 'Gitlab Public',
				type: 'gitlab'
			}
		});
	}
	// Set new preview secrets
	const secrets = await prisma.secret.findMany({ where: { isPRMRSecret: false } })
	if (secrets.length > 0) {
		for (const secret of secrets) {
			const previewSecrets = await prisma.secret.findMany({ where: { applicationId: secret.applicationId, name: secret.name, isPRMRSecret: true } })
			if (previewSecrets.length === 0) {
				await prisma.secret.create({ data: { ...secret, id: undefined, isPRMRSecret: true } })
			}
		}
	}
}
main()
	.catch((e) => {
		console.error(e);
		process.exit(1);
	})
	.finally(async () => {
		await prisma.$disconnect();
	});

const encrypt = (text) => {
	if (text) {
		const iv = crypto.randomBytes(16);
		const cipher = crypto.createCipheriv(algorithm, process.env['COOLIFY_SECRET_KEY'], iv);
		const encrypted = Buffer.concat([cipher.update(text), cipher.final()]);
		return JSON.stringify({
			iv: iv.toString('hex'),
			content: encrypted.toString('hex')
		});
	}
};