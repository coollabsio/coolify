import { WebSocketServer } from 'ws';
import http from 'http';
import pty from 'node-pty';
import axios from 'axios';
import cookie from 'cookie';
import 'dotenv/config';
import {
    extractHereDocContent,
    extractSshArgs,
    extractTargetHost,
    extractTimeout,
    isAuthorizedTargetHost,
} from './terminal-utils.js';

const userSessions = new Map();
const terminalDebugEnabled = ['local', 'development'].includes(
    String(process.env.APP_ENV || process.env.NODE_ENV || '').toLowerCase()
);

function logTerminal(level, message, context = {}) {
    if (!terminalDebugEnabled) {
        return;
    }

    const formattedMessage = `[TerminalServer] ${message}`;

    if (Object.keys(context).length > 0) {
        console[level](formattedMessage, context);
        return;
    }

    console[level](formattedMessage);
}

const server = http.createServer((req, res) => {
    if (req.url === '/ready') {
        res.writeHead(200, { 'Content-Type': 'text/plain' });
        res.end('OK');
    } else {
        res.writeHead(404, { 'Content-Type': 'text/plain' });
        res.end('Not Found');
    }
});

const getSessionCookie = (req) => {
    const cookies = cookie.parse(req.headers.cookie || '');
    const xsrfToken = cookies['XSRF-TOKEN'];
    const appName = process.env.APP_NAME || 'laravel';
    const sessionCookieName = `${appName.replace(/[^a-zA-Z0-9]/g, '_').toLowerCase()}_session`;
    return {
        sessionCookieName,
        xsrfToken: xsrfToken,
        laravelSession: cookies[sessionCookieName]
    }
}

const verifyClient = async (info, callback) => {
    const { xsrfToken, laravelSession, sessionCookieName } = getSessionCookie(info.req);
    const requestContext = {
        remoteAddress: info.req.socket?.remoteAddress,
        origin: info.origin,
        sessionCookieName,
        hasXsrfToken: Boolean(xsrfToken),
        hasLaravelSession: Boolean(laravelSession),
    };

    logTerminal('log', 'Verifying websocket client.', requestContext);

    // Verify presence of required tokens
    if (!laravelSession || !xsrfToken) {
        logTerminal('warn', 'Rejecting websocket client because required auth tokens are missing.', requestContext);
        return callback(false, 401, 'Unauthorized: Missing required tokens');
    }

    try {
        // Authenticate with Laravel backend
        const response = await axios.post(`http://coolify:8080/terminal/auth`, null, {
            headers: {
                'Cookie': `${sessionCookieName}=${laravelSession}`,
                'X-XSRF-TOKEN': xsrfToken
            },
        });

        if (response.status === 200) {
            logTerminal('log', 'Websocket client authentication succeeded.', requestContext);
            callback(true);
        } else {
            logTerminal('warn', 'Websocket client authentication returned a non-success status.', {
                ...requestContext,
                status: response.status,
            });
            callback(false, 401, 'Unauthorized: Invalid credentials');
        }
    } catch (error) {
        logTerminal('error', 'Websocket client authentication failed.', {
            ...requestContext,
            error: error.message,
            responseStatus: error.response?.status,
            responseData: error.response?.data,
        });
        callback(false, 500, 'Internal Server Error');
    }
};


const wss = new WebSocketServer({ server, path: '/terminal/ws', verifyClient: verifyClient });

wss.on('connection', async (ws, req) => {
    const userId = generateUserId();
    const userSession = { ws, userId, ptyProcess: null, isActive: false, authorizedIPs: [] };
    const { xsrfToken, laravelSession, sessionCookieName } = getSessionCookie(req);
    const connectionContext = {
        userId,
        remoteAddress: req.socket?.remoteAddress,
        sessionCookieName,
        hasXsrfToken: Boolean(xsrfToken),
        hasLaravelSession: Boolean(laravelSession),
    };

    // Verify presence of required tokens
    if (!laravelSession || !xsrfToken) {
        logTerminal('warn', 'Closing websocket connection because required auth tokens are missing.', connectionContext);
        ws.close(401, 'Unauthorized: Missing required tokens');
        return;
    }

    try {
        const response = await axios.post(`http://coolify:8080/terminal/auth/ips`, null, {
            headers: {
                'Cookie': `${sessionCookieName}=${laravelSession}`,
                'X-XSRF-TOKEN': xsrfToken
            },
        });
        userSession.authorizedIPs = response.data.ipAddresses || [];
        logTerminal('log', 'Fetched authorized terminal hosts for websocket session.', {
            ...connectionContext,
            authorizedIPs: userSession.authorizedIPs,
        });
    } catch (error) {
        logTerminal('error', 'Failed to fetch authorized terminal hosts.', {
            ...connectionContext,
            error: error.message,
            responseStatus: error.response?.status,
            responseData: error.response?.data,
        });
        ws.close(1011, 'Failed to fetch terminal authorization data');
        return;
    }

    userSessions.set(userId, userSession);
    logTerminal('log', 'Terminal websocket connection established.', {
        ...connectionContext,
        authorizedHostCount: userSession.authorizedIPs.length,
    });

    ws.on('message', (message) => {
        handleMessage(userSession, message);
    });
    ws.on('error', (err) => handleError(err, userId));
    ws.on('close', (code, reason) => {
        logTerminal('log', 'Terminal websocket connection closed.', {
            userId,
            code,
            reason: reason?.toString(),
        });
        handleClose(userId);
    });
});

const messageHandlers = {
    message: (session, data) => session.ptyProcess.write(data),
    resize: (session, { cols, rows }) => {
        cols = cols > 0 ? cols : 80;
        rows = rows > 0 ? rows : 30;
        session.ptyProcess.resize(cols, rows)
    },
    pause: (session) => session.ptyProcess.pause(),
    resume: (session) => session.ptyProcess.resume(),
    ping: (session) => session.ws.send('pong'),
    checkActive: (session, data) => {
        if (data === 'force' && session.isActive) {
            killPtyProcess(session.userId);
        } else {
            session.ws.send(session.isActive);
        }
    },
    command: (session, data) => handleCommand(session.ws, data, session.userId)
};

function handleMessage(userSession, message) {
    const parsed = parseMessage(message);
    if (!parsed) {
        logTerminal('warn', 'Ignoring websocket message because JSON parsing failed.', {
            userId: userSession.userId,
            rawMessage: String(message).slice(0, 500),
        });
        return;
    }

    logTerminal('log', 'Received websocket message.', {
        userId: userSession.userId,
        keys: Object.keys(parsed),
        isActive: userSession.isActive,
    });

    Object.entries(parsed).forEach(([key, value]) => {
        const handler = messageHandlers[key];
        if (handler && (userSession.isActive || key === 'checkActive' || key === 'command' || key === 'ping')) {
            handler(userSession, value);
        } else if (!handler) {
            logTerminal('warn', 'Ignoring websocket message with unknown handler key.', {
                userId: userSession.userId,
                key,
            });
        } else {
            logTerminal('warn', 'Ignoring websocket message because no PTY session is active yet.', {
                userId: userSession.userId,
                key,
            });
        }
    });
}

function parseMessage(message) {
    try {
        return JSON.parse(message);
    } catch (e) {
        logTerminal('error', 'Failed to parse websocket message.', {
            error: e?.message ?? e,
        });
        return null;
    }
}

async function handleCommand(ws, command, userId) {
    const userSession = userSessions.get(userId);
    if (userSession && userSession.isActive) {
        const result = await killPtyProcess(userId);
        if (!result) {
            logTerminal('warn', 'Rejecting new terminal command because the previous PTY could not be terminated.', {
                userId,
            });
            // if terminal is still active, even after we tried to kill it, dont continue and show error
            ws.send('unprocessable');
            return;
        }
    }

    const commandString = command[0].split('\n').join(' ');
    const timeout = extractTimeout(commandString);
    const sshArgs = extractSshArgs(commandString);
    const hereDocContent = extractHereDocContent(commandString);

    // Extract target host from SSH command
    const targetHost = extractTargetHost(sshArgs);
    logTerminal('log', 'Parsed terminal command metadata.', {
        userId,
        targetHost,
        timeout,
        sshArgs,
        authorizedIPs: userSession?.authorizedIPs ?? [],
    });

    if (!targetHost) {
        logTerminal('warn', 'Rejecting terminal command because no target host could be extracted.', {
            userId,
            sshArgs,
        });
        ws.send('Invalid SSH command: No target host found');
        return;
    }

    // Validate target host against authorized IPs
    if (!isAuthorizedTargetHost(targetHost, userSession.authorizedIPs)) {
        logTerminal('warn', 'Rejecting terminal command because target host is not authorized.', {
            userId,
            targetHost,
            authorizedIPs: userSession.authorizedIPs,
        });
        ws.send(`Unauthorized: Target host ${targetHost} not in authorized list`);
        return;
    }

    const options = {
        name: 'xterm-color',
        cols: 80,
        rows: 30,
        cwd: process.env.HOME,
        env: {},
    };

    // NOTE: - Initiates a process within the Terminal container
    //         Establishes an SSH connection to root@coolify with RequestTTY enabled
    //         Executes the 'docker exec' command to connect to a specific container
    logTerminal('log', 'Spawning PTY process for terminal session.', {
        userId,
        targetHost,
        timeout,
    });
    const ptyProcess = pty.spawn('ssh', sshArgs.concat([hereDocContent]), options);

    userSession.ptyProcess = ptyProcess;
    userSession.isActive = true;

    ws.send('pty-ready');

    ptyProcess.onData((data) => {
        ws.send(data);
    });

    // when parent closes
    ptyProcess.onExit(({ exitCode, signal }) => {
        logTerminal(exitCode === 0 ? 'log' : 'error', 'PTY process exited.', {
            userId,
            exitCode,
            signal,
        });
        ws.send('pty-exited');
        userSession.isActive = false;
    });

    if (timeout) {
        setTimeout(async () => {
            await killPtyProcess(userId);
        }, timeout * 1000);
    }
}

async function handleError(err, userId) {
    logTerminal('error', 'WebSocket error.', {
        userId,
        error: err?.message ?? err,
    });
    await killPtyProcess(userId);
}

async function handleClose(userId) {
    logTerminal('log', 'Cleaning up terminal websocket session.', {
        userId,
    });
    await killPtyProcess(userId);
    userSessions.delete(userId);
}

async function killPtyProcess(userId) {
    const session = userSessions.get(userId);
    if (!session?.ptyProcess) return false;

    return new Promise((resolve) => {
        // Loop to ensure terminal is killed before continuing
        let killAttempts = 0;
        const maxAttempts = 5;

        const attemptKill = () => {
            killAttempts++;
            logTerminal('log', 'Attempting to terminate PTY process.', {
                userId,
                killAttempts,
                maxAttempts,
            });

            // session.ptyProcess.kill() wont work here because of https://github.com/moby/moby/issues/9098
            // patch with https://github.com/moby/moby/issues/9098#issuecomment-189743947
            session.ptyProcess.write('set +o history\nkill -TERM -$$ && exit\nset -o history\n');

            setTimeout(() => {
                if (!session.isActive || !session.ptyProcess) {
                    logTerminal('log', 'PTY process terminated successfully.', {
                        userId,
                        killAttempts,
                    });
                    resolve(true);
                    return;
                }

                if (killAttempts < maxAttempts) {
                    attemptKill();
                } else {
                    logTerminal('warn', 'PTY process still active after maximum termination attempts.', {
                        userId,
                        killAttempts,
                    });
                    resolve(false);
                }
            }, 500);
        };

        attemptKill();
    });
}

function generateUserId() {
    return Math.random().toString(36).substring(2, 11);
}

server.listen(6002, () => {
    logTerminal('log', 'Terminal debug logging is enabled.', {
        terminalDebugEnabled,
    });
});
