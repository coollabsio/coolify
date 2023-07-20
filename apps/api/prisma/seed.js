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
				id: '0',
				arch: process.arch
			}
		});
	} else {
		await prisma.setting.update({
			where: {
				id: settingsFound.id
			},
			data: {
				id: '0'
			}
		});
	}
	// Create local docker engine
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
	await prisma.setting.update({
		where: {
			id: '0'
		},
		data: {
			isAutoUpdateEnabled
		}
	});
	// Create public github source
	const github = await prisma.gitSource.findFirst({
		where: { htmlUrl: 'https://github.com', forPublic: true }
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
	// Create public gitlab source
	const gitlab = await prisma.gitSource.findFirst({
		where: { htmlUrl: 'https://gitlab.com', forPublic: true }
	});
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
	const secrets = await prisma.secret.findMany({ where: { isPRMRSecret: false } });
	if (secrets.length > 0) {
		for (const secret of secrets) {
			const previewSecrets = await prisma.secret.findMany({
				where: { applicationId: secret.applicationId, name: secret.name, isPRMRSecret: true }
			});
			if (previewSecrets.length === 0) {
				await prisma.secret.create({ data: { ...secret, id: undefined, isPRMRSecret: true } });
			}
		}
	}
}
async function reEncryptSecrets() {
	const { execaCommand } = await import('execa');
	const image = await execaCommand("docker inspect coolify --format '{{ .Config.Image }}'", {
		shell: true
	});
	const version = image.stdout.split(':')[1] ?? null;
	const date = new Date().getTime();

	let backupfile = `/app/db/prod.db_${date}`;
	if (version) {
		backupfile = `/app/db/prod.db_${version}_${date}`;
	}
	console.log(`Backup database to ${backupfile}.`);
	await execaCommand(`cp /app/db/prod.db ${backupfile}`, { shell: true });
	await execaCommand('env | grep COOLIFY > .env', { shell: true });
	const secretOld = process.env['COOLIFY_SECRET_KEY'];
	let secretNew = process.env['COOLIFY_SECRET_KEY_BETTER'];

	if (!secretNew) {
		console.log('No COOLIFY_SECRET_KEY_BETTER found... Generating new one...');
		const { stdout: newKey } = await execaCommand(
			'openssl rand -base64 1024 | sha256sum | base64 | head -c 32',
			{ shell: true }
		);
		secretNew = newKey;
	}
	if (secretOld !== secretNew) {
		console.log(
			'Secrets (COOLIFY_SECRET_KEY & COOLIFY_SECRET_KEY_BETTER) are different, so re-encrypting everything...'
		);
		await execaCommand(`sed -i '/COOLIFY_SECRET_KEY=/d' .env`, { shell: true });
		await execaCommand(`sed -i '/COOLIFY_SECRET_KEY_BETTER=/d' .env`, { shell: true });
		await execaCommand(`echo "COOLIFY_SECRET_KEY=${secretNew}" >> .env`, { shell: true });
		await execaCommand('echo "COOLIFY_SECRET_KEY_BETTER=' + secretNew + '" >> .env ', {
			shell: true
		});
		await execaCommand(`echo "COOLIFY_SECRET_KEY_OLD_${date}=${secretOld}" >> .env`, {
			shell: true
		});

		const transactions = [];
		const secrets = await prisma.secret.findMany();
		if (secrets.length > 0) {
			for (const secret of secrets) {
				const value = decrypt(secret.value, secretOld);
				const newValue = encrypt(value, secretNew);
				transactions.push(
					prisma.secret.update({
						where: { id: secret.id },
						data: { value: newValue }
					})
				);
			}
		}
		const serviceSecrets = await prisma.serviceSecret.findMany();
		if (serviceSecrets.length > 0) {
			for (const secret of serviceSecrets) {
				const value = decrypt(secret.value, secretOld);
				const newValue = encrypt(value, secretNew);
				transactions.push(
					prisma.serviceSecret.update({
						where: { id: secret.id },
						data: { value: newValue }
					})
				);
			}
		}
		const gitlabApps = await prisma.gitlabApp.findMany();
		if (gitlabApps.length > 0) {
			for (const gitlabApp of gitlabApps) {
				const value = decrypt(gitlabApp.privateSshKey, secretOld);
				const newValue = encrypt(value, secretNew);
				const appSecret = decrypt(gitlabApp.appSecret, secretOld);
				const newAppSecret = encrypt(appSecret, secretNew);
				transactions.push(
					prisma.gitlabApp.update({
						where: { id: gitlabApp.id },
						data: { privateSshKey: newValue, appSecret: newAppSecret }
					})
				);
			}
		}
		const githubApps = await prisma.githubApp.findMany();
		if (githubApps.length > 0) {
			for (const githubApp of githubApps) {
				const clientSecret = decrypt(githubApp.clientSecret, secretOld);
				const newClientSecret = encrypt(clientSecret, secretNew);
				const webhookSecret = decrypt(githubApp.webhookSecret, secretOld);
				const newWebhookSecret = encrypt(webhookSecret, secretNew);
				const privateKey = decrypt(githubApp.privateKey, secretOld);
				const newPrivateKey = encrypt(privateKey, secretNew);

				transactions.push(
					prisma.githubApp.update({
						where: { id: githubApp.id },
						data: {
							clientSecret: newClientSecret,
							webhookSecret: newWebhookSecret,
							privateKey: newPrivateKey
						}
					})
				);
			}
		}
		const databases = await prisma.database.findMany();
		if (databases.length > 0) {
			for (const database of databases) {
				const dbUserPassword = decrypt(database.dbUserPassword, secretOld);
				const newDbUserPassword = encrypt(dbUserPassword, secretNew);
				const rootUserPassword = decrypt(database.rootUserPassword, secretOld);
				const newRootUserPassword = encrypt(rootUserPassword, secretNew);
				transactions.push(
					prisma.database.update({
						where: { id: database.id },
						data: {
							dbUserPassword: newDbUserPassword,
							rootUserPassword: newRootUserPassword
						}
					})
				);
			}
		}
		const databaseSecrets = await prisma.databaseSecret.findMany();
		if (databaseSecrets.length > 0) {
			for (const databaseSecret of databaseSecrets) {
				const value = decrypt(databaseSecret.value, secretOld);
				const newValue = encrypt(value, secretNew);
				transactions.push(
					prisma.databaseSecret.update({
						where: { id: databaseSecret.id },
						data: { value: newValue }
					})
				);
			}
		}
		const wordpresses = await prisma.wordpress.findMany();
		if (wordpresses.length > 0) {
			for (const wordpress of wordpresses) {
				const value = decrypt(wordpress.ftpHostKey, secretOld);
				const newValue = encrypt(value, secretNew);
				const ftpHostKeyPrivate = decrypt(wordpress.ftpHostKeyPrivate, secretOld);
				const newFtpHostKeyPrivate = encrypt(ftpHostKeyPrivate, secretNew);
				let newFtpPassword = undefined;
				if (wordpress.ftpPassword != null) {
					const ftpPassword = decrypt(wordpress.ftpPassword, secretOld);
					newFtpPassword = encrypt(ftpPassword, secretNew);
				}

				transactions.push(
					prisma.wordpress.update({
						where: { id: wordpress.id },
						data: {
							ftpHostKey: newValue,
							ftpHostKeyPrivate: newFtpHostKeyPrivate,
							ftpPassword: newFtpPassword
						}
					})
				);
			}
		}
		const sshKeys = await prisma.sshKey.findMany();
		if (sshKeys.length > 0) {
			for (const key of sshKeys) {
				const value = decrypt(key.privateKey, secretOld);
				const newValue = encrypt(value, secretNew);
				transactions.push(
					prisma.sshKey.update({
						where: { id: key.id },
						data: {
							privateKey: newValue
						}
					})
				);
			}
		}
		const dockerRegistries = await prisma.dockerRegistry.findMany();
		if (dockerRegistries.length > 0) {
			for (const registry of dockerRegistries) {
				const value = decrypt(registry.password, secretOld);
				const newValue = encrypt(value, secretNew);
				transactions.push(
					prisma.dockerRegistry.update({
						where: { id: registry.id },
						data: {
							password: newValue
						}
					})
				);
			}
		}
		const certificates = await prisma.certificate.findMany();
		if (certificates.length > 0) {
			for (const certificate of certificates) {
				const value = decrypt(certificate.key, secretOld);
				const newValue = encrypt(value, secretNew);
				transactions.push(
					prisma.certificate.update({
						where: { id: certificate.id },
						data: {
							key: newValue
						}
					})
				);
			}
		}
		await prisma.$transaction(transactions);
	} else {
		console.log('secrets are the same, so no need to re-encrypt');
	}
}

const encrypt = (text, secret) => {
	if (text && secret) {
		const iv = crypto.randomBytes(16);
		const cipher = crypto.createCipheriv(algorithm, secret, iv);
		const encrypted = Buffer.concat([cipher.update(text.trim()), cipher.final()]);
		return JSON.stringify({
			iv: iv.toString('hex'),
			content: encrypted.toString('hex')
		});
	}
};
const decrypt = (hashString, secret) => {
	if (hashString && secret) {
		try {
			const hash = JSON.parse(hashString);
			const decipher = crypto.createDecipheriv(algorithm, secret, Buffer.from(hash.iv, 'hex'));
			const decrpyted = Buffer.concat([
				decipher.update(Buffer.from(hash.content, 'hex')),
				decipher.final()
			]);
			return decrpyted.toString();
		} catch (error) {
			console.log({ decryptionError: error.message });
			return hashString;
		}
	}
};

// main()
// 	.catch((e) => {
// 		console.error(e);
// 		process.exit(1);
// 	})
// 	.finally(async () => {
// 		await prisma.$disconnect();
// 	});
reEncryptSecrets()
	.catch((e) => {
		console.error(e);
		process.exit(1);
	})
	.finally(async () => {
		await prisma.$disconnect();
	});
