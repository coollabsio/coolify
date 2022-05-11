import ioClient from 'socket.io-client';
const socket = ioClient('http://localhost:3000');
export const io = socket;
