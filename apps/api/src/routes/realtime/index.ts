export function realtime(fastify, connection, message) {
    const { socket } = connection
    const data = JSON.parse(message);
    if (data.type === 'subscribe') {
        socket.send(JSON.stringify({ type: 'subscribe', message: 'pong' }))
    }
}