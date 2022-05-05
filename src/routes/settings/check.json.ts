import { dev } from '$app/env';
import { asyncExecShell, getDomain, getEngine, getUserDetails } from '$lib/common';
import * as db from '$lib/database';
import { ErrorHandler } from '$lib/database';
import { t } from '$lib/translations';
import { promises as dns } from 'dns';
import type { RequestHandler } from '@sveltejs/kit';
import { isIP } from 'is-ip';

export const post: RequestHandler = async (event) => {
	const { status, body } = await getUserDetails(event);
	if (status === 401) return { status, body };
	const { id } = event.params;

	let { fqdn, forceSave, dualCerts, isDNSCheckEnabled } = await event.request.json();
	if (fqdn) fqdn = fqdn.toLowerCase();

	try {
		const domain = getDomain(fqdn);
		const domainDualCert = domain.includes('www.') ? domain.replace('www.', '') : `www.${domain}`;
		const found = await db.isDomainConfigured({ id, fqdn });
		if (found) {
			throw {
				message: t.get('application.domain_already_in_use', {
					domain: getDomain(fqdn).replace('www.', '')
				})
			};
		}
		if (isDNSCheckEnabled) {
			if (!forceSave) {
				dns.setServers(['1.1.1.1', '8.8.8.8']);
				if (dualCerts) {
					try {
						const ipDomain = await dns.resolve4(domain);
						const ipDomainDualCert = await dns.resolve4(domainDualCert);
						console.log({ ipDomain, ipDomainDualCert });
						if (
							ipDomain.length === ipDomainDualCert.length &&
							ipDomain.every((v) => ipDomainDualCert.indexOf(v) >= 0)
						) {
							let resolves = [];
							if (isIP(event.url.hostname)) {
								resolves = [event.url.hostname];
							} else {
								resolves = await dns.resolve4(event.url.hostname);
							}
							console.log({ resolves });
							if (resolves.includes(ipDomain) || resolves.includes(ipDomainDualCert)) {
								console.log('OK');
							} else {
								throw false;
							}
						} else {
							throw false;
						}
					} catch (error) {
						console.log(error);
						throw {
							message: t.get('application.dns_not_set_error', { domain })
						};
					}
				} else {
					let resolves = [];
					try {
						const ipDomain = await dns.resolve4(domain);
						console.log({ ipDomain });
						if (isIP(event.url.hostname)) {
							resolves = [event.url.hostname];
						} else {
							resolves = await dns.resolve4(event.url.hostname);
						}
						console.log({ resolves });
						if (resolves.includes(ipDomain)) {
							console.log('OK');
						} else {
							throw false;
						}
					} catch (error) {
						console.log(error);
						throw {
							message: t.get('application.dns_not_set_error', { domain })
						};
					}
				}
				// let localReverseDomains = [];
				// let newIps = [];
				// let newIpsWWW = [];
				// let localIps = [];
				// try {
				// 	localReverseDomains = await dns.reverse(event.url.hostname)
				// } catch (error) { }
				// try {
				// 	localIps = await dns.resolve4(event.url.hostname);
				// } catch (error) { }
				// try {
				// 	newIps = await dns.resolve4(domain);
				// 	if (dualCerts) {
				// 		newIpsWWW = await dns.resolve4(`${isWWW ? nonWWW : domain}`)
				// 	}
				// 	console.log(newIps)
				// } catch (error) { }
				// console.log({ localIps, newIps, localReverseDomains, dualCerts, isWWW, nonWWW })
				// if (localReverseDomains?.length > 0) {
				// 	if ((newIps?.length === 0 || !newIps.includes(event.url.hostname)) || (dualCerts && newIpsWWW?.length === 0 && !newIpsWWW.includes(`${isWWW ? nonWWW : domain}`))) {
				// 		throw {
				// 			message: t.get('application.dns_not_set_error', { domain })
				// 		};
				// 	}
				// }
				// if (localIps?.length > 0) {
				// 	if (newIps?.length === 0 || !localIps.includes(newIps[0])) {
				// 		throw {
				// 			message: t.get('application.dns_not_set_error', { domain })
				// 		};
				// 	}
				// }
			}
		}

		return {
			status: 200
		};
	} catch (error) {
		return ErrorHandler(error);
	}
};
