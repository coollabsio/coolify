import { parentPort } from 'node:worker_threads';
import process from 'node:process';

console.log('Hello TypeScript!');

// signal to parent that the job is done
if (parentPort) parentPort.postMessage('done');
// eslint-disable-next-line unicorn/no-process-exit
else process.exit(0);
