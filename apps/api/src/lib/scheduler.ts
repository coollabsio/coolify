import Bree from 'bree';
import path from 'path';
import Cabin from 'cabin';
import TSBree from '@breejs/ts-worker';
import { isDev } from './common';

Bree.extend(TSBree);

const options: any = {
	defaultExtension: 'js',
	logger: false,
	workerMessageHandler: async ({ name, message }) => {
		if (name === 'deployApplication') {
			if (message.pending === 0 && message.size === 0) {
				if (message.caller === 'autoUpdater') {
					if (!scheduler.workers.has('autoUpdater')) {
						await scheduler.stop('deployApplication');
						await scheduler.run('autoUpdater')
					}
				}
				if (message.caller === 'cleanupStorage') {
					if (!scheduler.workers.has('cleanupStorage')) {
						await scheduler.run('cleanupStorage')
					}
				}

			}
		}
	},
	jobs: [
		{
			name: 'deployApplication'
		},
		{
			name: 'cleanupStorage',
		},
		{
			name: 'cleanupPrismaEngines',
			interval: '1m'
		},
		{
			name: 'checkProxies',
			interval: '10s'
		},
		{
			name: 'autoUpdater',
		}
	],
};
if (isDev) options.root = path.join(__dirname, '../jobs');


export const scheduler = new Bree(options);


