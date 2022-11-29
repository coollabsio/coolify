
import { decrypt, prisma } from '../common';

export async function removeService({ id }: { id: string }): Promise<void> {
	await prisma.serviceSecret.deleteMany({ where: { serviceId: id } });
	await prisma.serviceSetting.deleteMany({ where: { serviceId: id } });
	await prisma.servicePersistentStorage.deleteMany({ where: { serviceId: id } });
	await prisma.meiliSearch.deleteMany({ where: { serviceId: id } });
	await prisma.fider.deleteMany({ where: { serviceId: id } });
	await prisma.ghost.deleteMany({ where: { serviceId: id } });
	await prisma.umami.deleteMany({ where: { serviceId: id } });
	await prisma.hasura.deleteMany({ where: { serviceId: id } });
	await prisma.plausibleAnalytics.deleteMany({ where: { serviceId: id } });
	await prisma.minio.deleteMany({ where: { serviceId: id } });
	await prisma.vscodeserver.deleteMany({ where: { serviceId: id } });
	await prisma.wordpress.deleteMany({ where: { serviceId: id } });
	await prisma.glitchTip.deleteMany({ where: { serviceId: id } });
	await prisma.moodle.deleteMany({ where: { serviceId: id } });
	await prisma.appwrite.deleteMany({ where: { serviceId: id } });
	await prisma.searxng.deleteMany({ where: { serviceId: id } });
	await prisma.weblate.deleteMany({ where: { serviceId: id } });
	await prisma.taiga.deleteMany({ where: { serviceId: id } });

	await prisma.service.delete({ where: { id } });
}
export async function verifyAndDecryptServiceSecrets(id: string) {
	const secrets = await prisma.serviceSecret.findMany({ where: { serviceId: id } })
	let decryptedSecrets = secrets.map(secret => {
		const { name, value } = secret
		if (value) {
			let rawValue = decrypt(value)
			rawValue = rawValue.replaceAll(/\$/gi, '$$$')
			return { name, value: rawValue }
		}
		return { name, value }

	})
	return decryptedSecrets
}