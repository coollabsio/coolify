import { prisma } from './common';

export async function listSettings() {
	return await prisma.setting.findFirst({});
}
