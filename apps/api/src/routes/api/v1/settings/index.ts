import { FastifyPluginAsync } from 'fastify';
import { X509Certificate } from 'node:crypto';

import { encrypt, errorHandler, prisma } from '../../../../lib/common';
import { addDockerRegistry, checkDNS, checkDomain, deleteCertificates, deleteDockerRegistry, deleteDomain, deleteSSHKey, listAllSettings, saveSettings, saveSSHKey, setDockerRegistry } from './handlers';
import { AddDefaultRegistry, CheckDNS, CheckDomain, DeleteDomain, OnlyIdInBody, SaveSettings, SaveSSHKey, SetDefaultRegistry } from './types';


const root: FastifyPluginAsync = async (fastify): Promise<void> => {
	fastify.addHook('onRequest', async (request) => {
		return await request.jwtVerify()
	})
	fastify.get('/', async (request) => await listAllSettings(request));
	fastify.post<SaveSettings>('/', async (request, reply) => await saveSettings(request, reply));
	fastify.delete<DeleteDomain>('/', async (request, reply) => await deleteDomain(request, reply));

	fastify.get<CheckDNS>('/check', async (request) => await checkDNS(request));
	fastify.post<CheckDomain>('/check', async (request) => await checkDomain(request));

	fastify.post<SaveSSHKey>('/sshKey', async (request, reply) => await saveSSHKey(request, reply));
	fastify.delete<OnlyIdInBody>('/sshKey', async (request, reply) => await deleteSSHKey(request, reply));

	fastify.post<SetDefaultRegistry>('/registry', async (request, reply) => await setDockerRegistry(request, reply));
	fastify.post<AddDefaultRegistry>('/registry/new', async (request, reply) => await addDockerRegistry(request, reply));
	fastify.delete<OnlyIdInBody>('/registry', async (request, reply) => await deleteDockerRegistry(request, reply));

	fastify.post('/upload', async (request) => {
		try {
			const teamId = request.user.teamId;
			const certificates = await prisma.certificate.findMany({})
			let cns = [];
			for (const certificate of certificates) {
				const x509 = new X509Certificate(certificate.cert);
				cns.push(x509.subject.split('\n').find((s) => s.startsWith('CN=')).replace('CN=', ''))
			}
			const parts = await request.files()
			let key = null
			let cert = null
			for await (const part of parts) {
				const name = part.fieldname
				if (name === 'key') key = (await part.toBuffer()).toString()
				if (name === 'cert') cert = (await part.toBuffer()).toString()
			}
			const x509 = new X509Certificate(cert);
			const cn = x509.subject.split('\n').find((s) => s.startsWith('CN=')).replace('CN=', '')
			if (cns.includes(cn)) {
				throw {
					message: `A certificate with ${cn} common name already exists.`
				}
			}
			await prisma.certificate.create({ data: { cert, key: encrypt(key), team: { connect: { id: teamId } } } })
			await prisma.applicationSettings.updateMany({ where: { application: { AND: [{ fqdn: { endsWith: cn } }, { fqdn: { startsWith: 'https' } }] } }, data: { isCustomSSL: true } })
			return { message: 'Certificated uploaded' }
		} catch ({ status, message }) {
			return errorHandler({ status, message });
		}

	});
	fastify.delete<OnlyIdInBody>('/certificate', async (request, reply) => await deleteCertificates(request, reply))
};

export default root;
