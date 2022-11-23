import Bree from 'bree';
import path from 'path';
import Cabin from 'cabin';
import TSBree from '@breejs/ts-worker';

export const isDev = process.env.NODE_ENV === 'development';

Bree.extend(TSBree);

const options: any = {
	defaultExtension: 'js',
	logger: false,
	// logger: false,
	// workerMessageHandler: async ({ name, message }) => {
	// 	if (name === 'deployApplication' && message?.deploying) {
	// 		if (scheduler.workers.has('autoUpdater') || scheduler.workers.has('cleanupStorage')) {
	// 			scheduler.workers.get('deployApplication').postMessage('cancel')
	// 		}
	// 	}
	// },
	jobs: [
		{ name: 'deployApplication' },
	],
};
if (isDev) options.root = path.join(__dirname, '../jobs');

export const scheduler = new Bree(options);


