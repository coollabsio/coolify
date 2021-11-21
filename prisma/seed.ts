import Prisma from '@prisma/client';
const { PrismaClient } = Prisma;

const prisma = new PrismaClient();

async function main() {
	// await prisma.application.create({
	// 	data: {
	// 		name: 'Banana',
    //         domain: 'test.co'
	// 	}
	// });
}
main();