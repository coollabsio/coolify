import Prisma from '@prisma/client';
const { PrismaClient } = Prisma;

const prisma = new PrismaClient();

async function main() {
	// Create default settings
	const found = await prisma.setting.findUnique({ where: { name: 'isRegistrationEnabled' } })
	if (!found) await prisma.setting.create({ data: { name: 'isRegistrationEnabled', value: 'true' } });
}
main();