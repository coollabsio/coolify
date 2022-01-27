import { decrypt } from '$lib/crypto';
import { prisma } from './common';

export async function listSettings() {
	let settings = await prisma.setting.findFirst({});
	settings.proxyPassword = decrypt(settings.proxyPassword)
	return settings
}
