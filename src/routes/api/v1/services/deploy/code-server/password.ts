import { execShellAsync } from '$lib/api/common';
import type { Request } from '@sveltejs/kit';
import yaml from "js-yaml"

export async function get(request: Request) {
	// const { POSTGRESQL_USERNAME, POSTGRESQL_PASSWORD, POSTGRESQL_DATABASE } = JSON.parse(
	// 	JSON.parse(
	// 		await execShellAsync(
	// 			"docker service inspect code-server_code-server --format='{{json .Spec.Labels.configuration}}'"
	// 		)
	// 	)
	// ).generateEnvsPostgres;
	const containers = (await execShellAsync("docker ps -a --format='{{json .Names}}'"))
		.replace(/"/g, '')
		.trim()
		.split('\n');
	const codeServer = containers.find((container) => container.startsWith('code-server'));
	const configYaml = yaml.load(await execShellAsync(
		`docker exec ${codeServer} cat /home/coder/.config/code-server/config.yaml`
	))
 	return {
		status: 200,
		body: { message: 'OK', password: configYaml.password }
	};
}
