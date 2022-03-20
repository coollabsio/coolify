import { buildCacheImageWithNode, buildImage } from '$lib/docker';
import { promises as fs } from 'fs';

const createDockerfile = async (data, image): Promise<void> => {
	const { applicationId, tag, workdir, publishDirectory } = data;
	const Dockerfile: Array<string> = [];

	Dockerfile.push(`FROM ${image}`);
	Dockerfile.push('WORKDIR /usr/share/nginx/html');
	Dockerfile.push(`LABEL coolify.image=true`);
	Dockerfile.push(`COPY --from=${applicationId}:${tag}-cache /usr/src/app/${publishDirectory} ./`);
	Dockerfile.push(`COPY /nginx.conf /etc/nginx/nginx.conf`);
	Dockerfile.push(`EXPOSE 80`);
	Dockerfile.push('CMD ["nginx", "-g", "daemon off;"]');
	await fs.writeFile(`${workdir}/Dockerfile`, Dockerfile.join('\n'));
};

export default async function (data) {
	try {
		const image = 'nginx:stable-alpine';
		const imageForBuild = 'node:lts';
		await buildCacheImageWithNode(data, imageForBuild);
		// await fs.writeFile(`${data.workdir}/default.conf`, `server {
		// 	listen       80;
		// 	server_name  localhost;

		// 	location / {
		// 		root   /usr/share/nginx/html;
		// 		try_files $uri $uri/ /index.html;
		// 	}

		// 	error_page   500 502 503 504  /50x.html;
		// 	location = /50x.html {
		// 		root   /usr/share/nginx/html;
		// 	}
		// }
		// `);
		await createDockerfile(data, image);
		await buildImage(data);
	} catch (error) {
		throw error;
	}
}
