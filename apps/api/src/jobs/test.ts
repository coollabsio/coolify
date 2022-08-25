import { parentPort } from 'node:worker_threads';;

(async () => {
	if (parentPort) {
		parentPort.on('message', async (message) => {
			if (message === 'error') throw new Error('oops');
			if (message === 'cancel') {
				parentPort.postMessage('cancelled');
				process.exit(0);
			}
		});
		console.log('test job started');
		process.exit(0)
	} else process.exit(0);
})();
