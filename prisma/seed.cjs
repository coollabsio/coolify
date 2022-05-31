const dotEnvExtended = require('dotenv-extended');
dotEnvExtended.load();
const { PrismaClient } = require('@prisma/client');
const prisma = new PrismaClient();
const crypto = require('crypto');
const generator = require('generate-password');
const cuid = require('cuid');
const compare = require('compare-versions');
const { version } = require('../package.json');
const child = require('child_process');
const util = require('util');

const algorithm = 'aes-256-ctr';

function generatePassword(length = 24) {
	return generator.generate({
		length,
		numbers: true,
		strict: true
	});
}
const asyncExecShell = util.promisify(child.exec);

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
	const versions = ['2.9.2', '2.9.3'];
	if (versions.includes(version)) {
		// Force stop Coolify Proxy, as it had a bug in < 2.9.2. TrustProxy + api.insecure
		try {
			await asyncExecShell(`docker stop -t 0 coolify-proxy && docker rm coolify-proxy`);
			const { stdout: Config } = await asyncExecShell(
				`docker network inspect bridge --format '{{json .IPAM.Config }}'`
			);
			const ip = JSON.parse(Config)[0].Gateway;
			await asyncExecShell(
				`docker run --restart always \
				--add-host 'host.docker.internal:host-gateway' \
				--add-host 'host.docker.internal:${ip}' \
				-v coolify-traefik-letsencrypt:/etc/traefik/acme \
				-v /var/run/docker.sock:/var/run/docker.sock \
				--network coolify-infra \
				-p "80:80" \
				-p "443:443" \
				--name coolify-proxy \
				-d traefik:v2.6 \
				--entrypoints.web.address=:80 \
				--entrypoints.web.forwardedHeaders.insecure=true \
				--entrypoints.websecure.address=:443 \
				--entrypoints.websecure.forwardedHeaders.insecure=true \
				--providers.docker=true \
				--providers.docker.exposedbydefault=false \
				--providers.http.endpoint=http://coolify:3000/webhooks/traefik/main.json \
				--providers.http.pollTimeout=5s \
				--certificatesresolvers.letsencrypt.acme.httpchallenge=true \
				--certificatesresolvers.letsencrypt.acme.storage=/etc/traefik/acme/acme.json \
				--certificatesresolvers.letsencrypt.acme.httpchallenge.entrypoint=web \
				--log.level=error`
			);
		} catch (error) {
			console.log(error);
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
