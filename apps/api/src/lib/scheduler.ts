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
			if (message.pending === 0) {
				if (!scheduler.workers.has('autoUpdater')) {
					scheduler.stop('deployApplication');
					await scheduler.run('autoUpdater')
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
			interval: '10m'
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


