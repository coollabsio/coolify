import { decrypt } from '$lib/crypto';
import { prisma } from './common';
import type { Setting } from '@prisma/client';

export async function listSettings(): Promise<Setting> {
	const settings = await prisma.setting.findFirst({});
	if (settings.proxyPassword) settings.proxyPassword = decrypt(settings.proxyPassword);
	return settings;
}
