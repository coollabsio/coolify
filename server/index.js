import express from 'express';
import { createServer } from 'http';
import { Server } from 'socket.io';

import { handler } from '../build/handler.js';

const app = express();
const server = createServer(app);

const io = new Server(server);

io.on('connection', (socket) => {
	socket.emit('eventFromServer', 'Hello, World 👋');
});

app.use(handler);

server.listen(port, () => {
	console.log(`Listening on port ${port}`);
});
