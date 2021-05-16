import type { Request } from '@sveltejs/kit';
import { saveServerLog } from '$lib/api/applications/logging';
import { execShellAsync } from '$lib/api/common';
import { docker } from '$lib/api/docker';
import fs from 'fs';

export async function post(request: Request) {
	const tmpdir = '/tmp/backups';
	const { deployId } = request.params;
	try {
		const now = new Date();
		const configuration = JSON.parse(
			JSON.parse(await execShellAsync(`docker inspect ${deployId}_${deployId}`))[0].Spec.Labels
				.configuration
		);
		const type = configuration.general.type;
		const serviceId = configuration.general.deployId;
		const databaseService = (await docker.engine.listContainers()).find(
			(r) => r.Labels['com.docker.stack.namespace'] === serviceId && r.State === 'running'
		);
		const containerID = databaseService.Labels['com.docker.swarm.task.name'];
		await execShellAsync(`mkdir -p ${tmpdir}`);
		if (type === 'mongodb') {
			if (databaseService) {
				const username = configuration.database.usernames[0];
				const password = configuration.database.passwords[1];
				const databaseName = configuration.database.defaultDatabaseName;
				const filename = `${databaseName}_${now.getTime()}.gz`;
				const fullfilename = `${tmpdir}/${filename}`;
				await execShellAsync(
					`docker exec -i ${containerID} /bin/bash -c "mkdir -p ${tmpdir};mongodump --uri='mongodb://${username}:${password}@${deployId}:27017' -d ${databaseName} --gzip --archive=${fullfilename}"`
				);
				await execShellAsync(`docker cp ${containerID}:${fullfilename} ${fullfilename}`);
				await execShellAsync(`docker exec -i ${containerID} /bin/bash -c "rm -f ${fullfilename}"`);
				return {
					status: 200,
					headers: {
						'Content-Type': 'application/octet-stream',
						'Content-Transfer-Encoding': 'binary',
						'Content-Disposition': `attachment; filename=${filename}`
					},
					body: fs.readFileSync(`${fullfilename}`)
				};
			}
		} else if (type === 'postgresql') {
			if (databaseService) {
				const username = configuration.database.usernames[0];
				const password = configuration.database.passwords[0];
				const databaseName = configuration.database.defaultDatabaseName;
				const filename = `${databaseName}_${now.getTime()}.sql.gz`;
				const fullfilename = `${tmpdir}/${filename}`;
				await execShellAsync(
					`docker exec -i ${containerID} /bin/bash -c "PGPASSWORD=${password} pg_dump --username ${username} -Z 9 ${databaseName}" > ${fullfilename}`
				);
				return {
					status: 200,
					headers: {
						'Content-Type': 'application/octet-stream',
						'Content-Transfer-Encoding': 'binary',
						'Content-Disposition': `attachment; filename=${filename}`
					},
					body: fs.readFileSync(`${fullfilename}`)
				};
			}
		} else if (type === 'couchdb') {
			if (databaseService) {
				const databaseName = configuration.database.defaultDatabaseName;
				const filename = `${databaseName}_${now.getTime()}.tar.gz`;
				const fullfilename = `${tmpdir}/${filename}`;
				await execShellAsync(
					`docker exec -i ${containerID} /bin/bash -c "cd /bitnami/couchdb/data/ && tar -czvf - ." > ${fullfilename}`
				);
				return {
					status: 200,
					headers: {
						'Content-Type': 'application/octet-stream',
						'Content-Transfer-Encoding': 'binary',
						'Content-Disposition': `attachment; filename=${filename}`
					},
					body: fs.readFileSync(`${fullfilename}`)
				};
			}
		} else if (type === 'mysql') {
			if (databaseService) {
				const username = configuration.database.usernames[0];
				const password = configuration.database.passwords[0];
				const databaseName = configuration.database.defaultDatabaseName;
				const filename = `${databaseName}_${now.getTime()}.sql.gz`;
				const fullfilename = `${tmpdir}/${filename}`;
				await execShellAsync(
					`docker exec -i ${containerID} /bin/bash -c "mysqldump -u ${username} -p${password} ${databaseName} | gzip -9 -" > ${fullfilename}`
				);
				return {
					status: 200,
					headers: {
						'Content-Type': 'application/octet-stream',
						'Content-Transfer-Encoding': 'binary',
						'Content-Disposition': `attachment; filename=${filename}`
					},
					body: fs.readFileSync(`${fullfilename}`)
				};
			}
		} else if (type === 'redis') {
			if (databaseService) {
				const password = configuration.database.passwords[0];
				const databaseName = configuration.database.defaultDatabaseName;
				const filename = `${databaseName}_${now.getTime()}.rdb`;
				const fullfilename = `${tmpdir}/${filename}`;
				await execShellAsync(
					`docker exec -i ${containerID} /bin/bash -c "redis-cli --pass ${password} save"`
				);
				await execShellAsync(
					`docker cp ${containerID}:/bitnami/redis/data/dump.rdb ${fullfilename}`
				);
				await execShellAsync(
					`docker exec -i ${containerID} /bin/bash -c "rm -f /bitnami/redis/data/dump.rdb"`
				);
				return {
					status: 200,
					headers: {
						'Content-Type': 'application/octet-stream',
						'Content-Transfer-Encoding': 'binary',
						'Content-Disposition': `attachment; filename=${filename}`
					},
					body: fs.readFileSync(`${fullfilename}`)
				};
			}
		}
		return {
			status: 501,
			body: {
				error: `Backup method not implemented yet for ${type}.`
			}
		};
	} catch (error) {
		await saveServerLog(error);
		return {
			status: 500,
			body: {
				error: error.message || error
			}
		};
	} finally {
		await execShellAsync(`rm -fr ${tmpdir}`);
	}
}
