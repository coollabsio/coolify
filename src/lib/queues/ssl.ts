import { generateSSLCerts } from '$lib/letsencrypt';
import { prisma } from '$lib/database';

export default async function (): Promise<void> {
	try {
		const settings = await prisma.setting.findFirst();
		if (!settings.isTraefikUsed) {
			return await generateSSLCerts();
		}
	} catch (error) {
		console.log(error);
		throw error;
	}
}
