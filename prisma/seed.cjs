const dotEnvExtended = require('dotenv-extended')
dotEnvExtended.load();
const { PrismaClient } = require('@prisma/client');
const prisma = new PrismaClient();
const cuid = require('cuid')
const crypto = require('crypto')
const generator = require('generate-password')

function generatePassword(length = 24) {
	return generator.generate({
		length,
		numbers: true,
		strict: true
	});
}
const algorithm = 'aes-256-ctr';

async function main() {
	// Disable registration
	// Set Proxy password
	const settingsFound = await db.listSettings();
	if (!settingsFound) {
		await prisma.setting.create({ data: { isRegistrationEnabled: true, proxyPassword: encrypt('mypassword') } });
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
