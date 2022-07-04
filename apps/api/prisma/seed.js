const dotEnvExtended = require('dotenv-extended');
dotEnvExtended.load();
const crypto = require('crypto');
const generator = require('generate-password');
const cuid = require('cuid');
const { PrismaClient } = require('@prisma/client');
const prisma = new PrismaClient();

function generatePassword(length = 24) {
	return generator.generate({
		length,
		numbers: true,
		strict: true
	});
}
const algorithm = 'aes-256-ctr';

async function main() {
	// Enable registration for the first user
	// Set initial HAProxy password
	const settingsFound = await prisma.setting.findFirst({});
	if (!settingsFound) {
		await prisma.setting.create({
			data: {
				isRegistrationEnabled: true,
				proxyPassword: encrypt(generatePassword()),
				proxyUser: cuid()
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

const decrypt = (hashString) => {
	if (hashString) {
		const hash = JSON.parse(hashString);
		const decipher = crypto.createDecipheriv(
			algorithm,
			process.env['COOLIFY_SECRET_KEY'],
			Buffer.from(hash.iv, 'hex')
		);
		const decrpyted = Buffer.concat([
			decipher.update(Buffer.from(hash.content, 'hex')),
			decipher.final()
		]);
		return decrpyted.toString();
	}
};
