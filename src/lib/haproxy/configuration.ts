import { dev } from '$app/env';
import got from 'got';
import mustache from 'mustache';
import crypto from 'crypto';

import * as db from '$lib/database';
import { checkContainer, checkHAProxy } from '.';
import { getDomain } from '$lib/common';

const url = dev ? 'http://localhost:5555' : 'http://coolify-haproxy:5555';

let template = `#coolhash={{hash}}
program api 
  command /usr/bin/dataplaneapi -f /usr/local/etc/haproxy/dataplaneapi.hcl --userlist haproxy-dataplaneapi
  no option start-on-reload
	
global
  stats socket /var/run/api.sock user haproxy group haproxy mode 660 level admin expose-fd listeners
  log stdout format raw local0 debug
		 
defaults 
  mode http
  log global
  timeout http-request 60s
  timeout connect 10s
  timeout client 60s
  timeout server 60s

userlist haproxy-dataplaneapi 
  user admin insecure-password "\${HAPROXY_PASSWORD}"

frontend http
  mode http
  bind :80
  bind :443 ssl crt /usr/local/etc/haproxy/ssl/ alpn h2,http/1.1
  acl is_certbot path_beg /.well-known/acme-challenge/
  {{#applications}}
  {{#isHttps}}
  http-request redirect scheme https code ${
		dev ? 302 : 301
	} if { hdr(host) -i {{domain}} } !{ ssl_fc }
  {{/isHttps}}
  http-request redirect location {{{redirectValue}}} code ${
		dev ? 302 : 301
	} if { req.hdr(host) -i {{redirectTo}} }
  {{/applications}}
  use_backend backend-certbot if is_certbot
  use_backend %[req.hdr(host),lower]

frontend stats 
  bind *:8404
  stats enable
  stats uri /
  stats refresh 5s
  stats admin if TRUE
  stats auth "\${HAPROXY_USERNAME}:\${HAPROXY_PASSWORD}"

backend backend-certbot 
  mode http
  server certbot host.docker.internal:9080

{{#applications}}

backend {{domain}}
  option forwardfor
  server {{id}} {{id}}:{{port}}
{{/applications}}
{{#services}}

backend {{domain}}
  option forwardfor
  server {{id}} {{id}}:{{port}}
{{/services}}
`;
export async function haproxyInstance() {
	const { proxyPassword } = await db.listSettings();
	return got.extend({
		prefixUrl: url,
		username: 'admin',
		password: proxyPassword
	});
}

export async function configureHAProxy() {
	const haproxy = await haproxyInstance();
	await checkHAProxy(haproxy);
	const data = {
		applications: [],
		services: []
	};
	const applications = await db.prisma.application.findMany({
		include: { destinationDocker: true }
	});
	for (const application of applications) {
		const {
			fqdn,
			id,
			port,
			destinationDocker: { engine }
		} = application;
		const isRunning = await checkContainer(engine, id);
		if (isRunning) {
			const domain = getDomain(fqdn);
			const isHttps = fqdn.startsWith('https://');
			const isWWW = fqdn.includes('www.');
			const redirectValue = `${isHttps ? 'https://' : 'http://'}${domain}%[capture.req.uri]`;
			data.applications.push({
				id,
				port,
				domain,
				isHttps,
				redirectValue,
				redirectTo: isWWW ? domain : 'www.' + domain
			});
		}
	}
	// const services = await db.prisma.service.findMany({
	//     include: {
	//         destinationDocker: true,
	//         minio: true,
	//         plausibleAnalytics: true,
	//         vscodeserver: true,
	//         wordpress: true
	//     }
	// });

	// for (const service of services) {
	//     const {
	//         fqdn,
	//         id,
	//         type,
	//         destinationDocker: { engine }
	//     } = service;
	//     const found = db.supportedServiceTypesAndVersions.find((a) => a.name === type);
	//     if (found) {
	//         const port = found.ports.main;
	//         const publicPort = service[type]?.publicPort;
	//         const isRunning = await checkContainer(engine, id);
	//     }
	// }
	const output = mustache.render(template, data);
	const newHash = crypto.createHash('md5').update(JSON.stringify(template)).digest('hex');
	const { proxyHash, id } = await db.listSettings();
	if (proxyHash !== newHash) {
		await db.prisma.setting.update({ where: { id }, data: { proxyHash: newHash } });
		console.log('HAProxy configuration changed, updating...');
		await haproxy.post(`v2/services/haproxy/configuration/raw`, {
			searchParams: {
				skip_version: true
			},
			body: output,
			headers: {
				'Content-Type': 'text/plain'
			}
		});
	} else {
		console.log('HAProxy configuration is up to date');
	}
}
