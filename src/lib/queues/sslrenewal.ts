import { renewSSLCerts } from '$lib/letsencrypt';
import { prisma } from '$lib/database';

export default async function (): Promise<void> {
	try {
		const settings = await prisma.setting.findFirst();
		if (!settings.isTraefikUsed) {
			return await renewSSLCerts();
		}
	} catch (error) {
		console.log(error);
		throw error;
	}
}
