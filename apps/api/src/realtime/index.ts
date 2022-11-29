
export default async (fastify) => {
    fastify.io.use((socket, next) => {
        const { token } = socket.handshake.auth;
        if (token && fastify.jwt.verify(token)) {
            next();
        } else {
            return next(new Error("unauthorized event"));
        }
    });
    fastify.io.on('connection', (socket: any) => {
        const { token } = socket.handshake.auth;
        const { teamId } = fastify.jwt.decode(token);
        socket.join(teamId);
        // console.info('Socket connected!', socket.id)
        // console.info('Socket joined team!', teamId)
        // socket.on('message', (message) => {
        //     console.log(message)
        // })
        // socket.on('error', (err) => {
        //     console.log(err)
        // })
    })
    // fastify.io.on("error", (err) => {
    //     if (err && err.message === "unauthorized event") {
    //         fastify.io.disconnect();
    //     }
    // });
}
