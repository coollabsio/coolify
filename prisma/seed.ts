import Prisma from '@prisma/client';
const { PrismaClient } = Prisma;

const prisma = new PrismaClient();

async function main() {
	// Create default settings
	await prisma.setting.create({ data: { name: 'isRegistrationEnabled', value: 'true' } });
}
main();