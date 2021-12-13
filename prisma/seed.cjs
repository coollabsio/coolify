const { PrismaClient } = require('@prisma/client')
const prisma = new PrismaClient();

async function main() {
	// Create default settings
	const found = await prisma.setting.findUnique({ where: { name: 'isRegistrationEnabled' } })
	if (!found) await prisma.setting.create({ data: { name: 'isRegistrationEnabled', value: 'true' } });
}
main()
	.catch((e) => {
		console.error(e)
		process.exit(1)
	})
	.finally(async () => {
		await prisma.$disconnect()
	})