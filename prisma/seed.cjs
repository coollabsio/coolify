const { PrismaClient } = require('@prisma/client')
const prisma = new PrismaClient();

async function main() {
	const found = await prisma.setting.findFirst({ where: { isRegistrationEnabled: { not: null } } })
	if (!found) await prisma.setting.create({ data: { isRegistrationEnabled: false } });
}
main()
	.catch((e) => {
		console.error(e)
		process.exit(1)
	})
	.finally(async () => {
		await prisma.$disconnect()
	})