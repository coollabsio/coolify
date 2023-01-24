import { serverConfig } from './config';
import { createServer } from './server';

const server = createServer(serverConfig);

server.start();

