import { WebSocketServer } from 'ws';
import http from 'http';
import pty from 'node-pty';
import 'dotenv/config';
import {
    TERMINAL_CLOSE_CODES,
    TERMINAL_IDLE_TIMEOUT_SECONDS,
    TERMINAL_SESSION_CHECK_INTERVAL_MS,
    TERMINAL_SESSION_CHECK_MAX_FAILURES,
    authenticateTerminalUpgrade,
    createTerminalUpgradeHandler,
    extractHereDocContent,
    extractSshArgs,
    extractTargetHost,
    extractTimeout,
    detachPtyProcess,
    getTerminalAuthHeaders,
    getTerminalProcessEnv,
    getTerminalSessionTimeout,
    isAttachCommand,
    isAuthorizedTargetHost,
    rejectTerminalSocket,
    sanitizeSshArgs,
    terminalSessionCheckResult,
    validateSshArgs,
} from './terminal-utils.js';

async function postToCoolify(path, headers, body = null) {
    return new Promise((resolve, reject) => {
        const requestBody = body === null ? '' : JSON.stringify(body);
        const request = http.request({
            hostname: process.env.TERMINAL_AUTH_HOST || '127.0.0.1',
            port: 8080,
            path,
            method: 'POST',
            headers: {
                ...headers,
                ...(body === null ? {} : {
                    'Content-Type': 'application/json',
                    'Content-Length': Buffer.byteLength(requestBody),
                }),
            },
        }, (response) => {
            let responseText = '';

            response.setEncoding('utf8');
            response.on('data', (chunk) => {
                responseText += chunk;
            });
            response.on('end', () => {
                try {
                    resolve({
                        status: response.statusCode ?? 0,
                        data: parseResponseData(response.headers['content-type'], responseText),
                    });
                } catch (error) {
                    reject(error);
                }
            });
        });

        request.setTimeout(10_000, () => {
            request.destroy(new Error('Request to Coolify timed out'));
        });
        request.on('error', reject);
        request.end(requestBody);
    });
}

function parseResponseData(contentType = '', responseText = '') {
    if (responseText === '') {
        return null;
    }

    if (contentType.includes('application/json')) {
        return JSON.parse(responseText);
    }

    return responseText;
}

function createHttpError(response) {
    const error = new Error(`Request failed with status code ${response.status}`);
    error.response = response;

    return error;
}

const userSessions = new Map();
const envName = String(process.env.APP_ENV || process.env.NODE_ENV || '').toLowerCase();
const debugOverride = String(process.env.TERMINAL_DEBUG || '').toLowerCase();
const terminalDebugEnabled =
    ['local', 'development'].includes(envName)
    || ['1', 'true', 'yes', 'on'].includes(debugOverride);

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

const verifyClient = async (req) => {
    const authHeaderCandidates = getTerminalAuthHeaders(req.headers.cookie);
    const requestContext = {
        remoteAddress: req.socket?.remoteAddress,
        origin: req.headers.origin,
        xsrfTokenCount: authHeaderCandidates.length,
    };

    logTerminal('log', 'Verifying websocket client.', requestContext);

    // Authenticate with Laravel backend
    const result = await authenticateTerminalUpgrade(authHeaderCandidates, postToCoolify);

    if (result.authenticated) {
        // The connection handler sends its requests with the XSRF token that Coolify accepted.
        req.terminalAuthHeaders = result.authHeaders;
        logTerminal('log', 'Websocket client authentication succeeded.', requestContext);
    } else if (result.error) {
        logTerminal('error', 'Websocket client authentication failed.', {
            ...requestContext,
            error: result.error.message,
        });
    } else {
        logTerminal('warn', 'Rejecting websocket client.', {
            ...requestContext,
            reason: result.reason,
            status: result.status,
            closeCode: result.closeCode,
        });
    }

    return result;
};

// Upgrades are authenticated manually (instead of ws `verifyClient`) so a
// rejected browser receives a readable close code rather than an opaque 1006.
const wss = new WebSocketServer({ noServer: true, path: '/terminal/ws' });
server.on('upgrade', createTerminalUpgradeHandler({ wss, authenticate: verifyClient }));

const HEARTBEAT_INTERVAL_MS = 30000;

wss.on('connection', async (ws, req) => {
    ws.isAlive = true;
    ws.on('pong', () => { ws.isAlive = true; });

    const userId = generateUserId();
    ws.userId = userId;
    const authHeaders = req.terminalAuthHeaders;
    const userSession = {
        ws,
        userId,
        ptyProcess: null,
        isActive: false,
        authorizedIPs: [],
        authReady: false,
        pendingMessages: [],
        terminalSessionTimer: null,
        authHeaders,
    };
    const connectionContext = {
        userId,
        remoteAddress: req.socket?.remoteAddress,
        hasAuthTokens: Boolean(authHeaders),
    };

    // Register socket handlers up front so messages sent immediately by the client
    // (e.g. a command replay on reconnect) are not dropped while the auth/IP fetch
    // below is still pending.
    ws.on('message', (message) => {
        if (userSession.authReady) {
            handleMessage(userSession, message);
        } else {
            userSession.pendingMessages.push(message);
        }
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

    // Verify presence of required tokens
    if (!authHeaders) {
        logTerminal('warn', 'Closing websocket connection because required auth tokens are missing.', connectionContext);
        rejectTerminalSocket(ws, TERMINAL_CLOSE_CODES.AUTH_REJECTED, 'Unauthorized: Missing required tokens');
        return;
    }

    try {
        const response = await postToCoolify('/terminal/auth/ips', authHeaders);

        if (response.status !== 200) {
            throw createHttpError(response);
        }

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
    userSession.authReady = true;
    logTerminal('log', 'Terminal websocket connection established.', {
        ...connectionContext,
        authorizedHostCount: userSession.authorizedIPs.length,
        bufferedMessages: userSession.pendingMessages.length,
    });

    // Drain any messages that arrived while we were waiting on the IP auth call.
    while (userSession.pendingMessages.length > 0) {
        handleMessage(userSession, userSession.pendingMessages.shift());
    }
});

const heartbeat = setInterval(() => {
    wss.clients.forEach((ws) => {
        if (ws.isAlive === false) {
            logTerminal('warn', 'Terminating WS due to missed protocol pong.');
            return ws.terminate();
        }
        ws.isAlive = false;
        try {
            ws.ping();
        } catch (_) {
            // ignore — close handler will follow
        }
    });
}, HEARTBEAT_INTERVAL_MS);

wss.on('close', () => clearInterval(heartbeat));

const messageHandlers = {
    message: (session, data) => {
        if (typeof data !== 'string') {
            return;
        }
        session.lastActivityAt = Date.now();
        session.ptyProcess.write(data);
    },
    resize: (session, size) => {
        const cols = terminalDimension(size?.cols, 80);
        const rows = terminalDimension(size?.rows, 30);
        session.ptyProcess.resize(cols, rows);
    },
    pause: (session) => session.ptyProcess.pause(),
    resume: (session) => session.ptyProcess.resume(),
    ping: (session) => session.ws.send('pong'),
    checkActive: (session, data) => {
        if (data === 'force' && session.isActive) {
            session.endReason = 'replaced';
            killPtyProcess(session.userId);
        } else {
            session.ws.send(String(session.isActive));
        }
    },
    terminalToken: (session, token) => handleTerminalToken(session, token)
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

    if (typeof parsed !== 'object' || Array.isArray(parsed)) {
        logTerminal('warn', 'Ignoring websocket message because it is not a JSON object.', {
            userId: userSession.userId,
        });
        return;
    }

    Object.entries(parsed).forEach(([key, value]) => {
        const handler = Object.hasOwn(messageHandlers, key) ? messageHandlers[key] : null;
        if (handler && (userSession.isActive || key === 'checkActive' || key === 'terminalToken' || key === 'ping')) {
            runMessageHandler(handler, userSession, key, value);
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

/**
 * Run one handler. A handler that throws must not stop the process, because one
 * process serves the terminals of all users.
 */
function runMessageHandler(handler, userSession, key, value) {
    const logFailure = (error) => logTerminal('error', 'Websocket message handler failed.', {
        userId: userSession.userId,
        key,
        error: error?.message ?? error,
    });

    try {
        Promise.resolve(handler(userSession, value)).catch(logFailure);
    } catch (error) {
        logFailure(error);
    }
}

function terminalDimension(value, fallback) {
    return Number.isInteger(value) && value > 0 && value <= 1000 ? value : fallback;
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

/**
 * Starts the PTY for a redeemed terminal session.
 *
 * @returns {Promise<boolean>} false when the command was rejected and no PTY started
 */
async function handleCommand(ws, command, userId, terminalSessionId, reportEnd) {
    const userSession = userSessions.get(userId);
    if (userSession && userSession.isActive) {
        userSession.endReason = 'replaced';
        const result = await killPtyProcess(userId);
        if (!result) {
            logTerminal('warn', 'Rejecting new terminal command because the previous PTY could not be terminated.', {
                userId,
            });
            // if terminal is still active, even after we tried to kill it, dont continue and show error
            ws.send('unprocessable');
            return false;
        }
    }

    if (userSession.terminalSessionTimer) {
        clearTimeout(userSession.terminalSessionTimer);
        userSession.terminalSessionTimer = null;
    }

    const commandString = command[0].split('\n').join(' ');
    const commandTimeout = extractTimeout(commandString);
    const terminalSessionTimeout = getTerminalSessionTimeout();
    const sshArgs = extractSshArgs(commandString);
    const hereDocContent = extractHereDocContent(commandString);

    // Extract target host from SSH command
    const targetHost = extractTargetHost(sshArgs);
    logTerminal('log', 'Parsed terminal command metadata.', {
        userId,
        targetHost,
        commandTimeout,
        terminalSessionTimeout,
        sshArgs,
        authorizedIPs: userSession?.authorizedIPs ?? [],
    });

    if (!targetHost) {
        logTerminal('warn', 'Rejecting terminal command because no target host could be extracted.', {
            userId,
            sshArgs,
        });
        ws.send('Invalid SSH command: No target host found');
        return false;
    }

    // Validate target host against authorized IPs
    if (!isAuthorizedTargetHost(targetHost, userSession.authorizedIPs)) {
        logTerminal('warn', 'Rejecting terminal command because target host is not authorized.', {
            userId,
            targetHost,
            authorizedIPs: userSession.authorizedIPs,
        });
        ws.send(`Unauthorized: Target host ${targetHost} not in authorized list`);
        return false;
    }

    if (!validateSshArgs(sshArgs, userSession.authorizedIPs)) {
        logTerminal('warn', 'Rejecting terminal command because its SSH arguments are not allowed.', {
            userId,
            targetHost,
        });
        ws.send('Invalid SSH command: Unsupported SSH arguments');
        return false;
    }
    const sanitizedSshArgs = sanitizeSshArgs(sshArgs);

    const options = {
        name: 'xterm-color',
        cols: 80,
        rows: 30,
        cwd: process.env.HOME,
        env: getTerminalProcessEnv(),
    };

    // NOTE: - Initiates a process within the Terminal container
    //         Establishes an SSH connection to root@coolify with RequestTTY enabled
    //         Executes the 'docker exec' command to connect to a specific container
    logTerminal('log', 'Spawning PTY process for terminal session.', {
        userId,
        targetHost,
        commandTimeout,
        terminalSessionTimeout,
    });
    const ptyProcess = pty.spawn('ssh', sanitizedSshArgs.concat([hereDocContent]), options);

    userSession.ptyProcess = ptyProcess;
    userSession.isActive = true;
    userSession.endReason = null;
    userSession.lastActivityAt = Date.now();
    const sessionWatch = watchTerminalSession(userSession, ptyProcess, terminalSessionId);
    // Attach sessions share the container's main process, so they must be detached, not killed.
    userSession.isAttach = isAttachCommand(hereDocContent);

    ws.send('pty-ready');

    // PTY output goes in binary frames. Text frames are only control messages of this server,
    // so output of the remote shell cannot look like one.
    ptyProcess.onData((data) => {
        if (userSession.ptyProcess !== ptyProcess) return;
        // Output counts as activity, so commands like `docker logs -f` are not ended as idle.
        userSession.lastActivityAt = Date.now();
        ws.send(Buffer.from(data, 'utf8'));
    });

    // when parent closes
    ptyProcess.onExit(({ exitCode, signal }) => {
        logTerminal(exitCode === 0 ? 'log' : 'error', 'PTY process exited.', {
            userId,
            exitCode,
            signal,
        });

        clearInterval(sessionWatch);

        // A previous PTY can exit late (e.g. an attach session detaching during a mode
        // switch) after its replacement is already spawned. Only mutate the shared session
        // state when this exit belongs to the session's current PTY.
        if (userSession.ptyProcess !== ptyProcess) {
            reportEnd('replaced');
            return;
        }

        reportEnd(userSession.endReason ?? 'exited');

        ws.send('pty-exited');
        userSession.isActive = false;

        if (userSession.terminalSessionTimer) {
            clearTimeout(userSession.terminalSessionTimer);
            userSession.terminalSessionTimer = null;
        }
    });

    userSession.terminalSessionTimer = setTimeout(async () => {
        await endTerminalSession(userSession, ptyProcess, 'max_duration', `it reached the maximum duration of ${terminalSessionTimeout / 3600} hours.`);
    }, terminalSessionTimeout * 1000);

    return true;
}

/**
 * Ends the PTY when it has no input and no output for too long, or when Coolify no longer allows it:
 * after a logout, a lost admin role, a removal from the team, or when terminal access
 * was turned off on the server.
 */
function watchTerminalSession(userSession, ptyProcess, terminalSessionId) {
    let failedChecks = 0;
    let checking = false;

    return setInterval(async () => {
        if (userSession.ptyProcess !== ptyProcess) {
            return;
        }

        if (TERMINAL_IDLE_TIMEOUT_SECONDS > 0 && Date.now() - userSession.lastActivityAt >= TERMINAL_IDLE_TIMEOUT_SECONDS * 1000) {
            await endTerminalSession(userSession, ptyProcess, 'idle', `no input or output for ${TERMINAL_IDLE_TIMEOUT_SECONDS / 60} minutes.`);
            return;
        }

        if (checking) {
            return;
        }

        checking = true;
        let response = null;
        try {
            // Accept JSON so a logged-out session gets a 401 instead of a redirect to the login page.
            response = await postToCoolify('/terminal/session/check', { ...userSession.authHeaders, Accept: 'application/json' }, { session_id: terminalSessionId });
        } catch (error) {
            logTerminal('error', 'Failed to check terminal session.', {
                userId: userSession.userId,
                error: error.message,
            });
        }
        checking = false;

        const result = terminalSessionCheckResult(response);
        if (result === 'active') {
            failedChecks = 0;
            return;
        }

        if (result === 'revoked') {
            await endTerminalSession(userSession, ptyProcess, 'revoked', 'access to this terminal was revoked.');
            return;
        }

        // Coolify is unavailable (for example during an upgrade) or gave an unexpected answer. Fail closed after a few minutes.
        logTerminal('warn', 'Terminal session check failed.', {
            userId: userSession.userId,
            status: response?.status,
        });
        failedChecks++;
        if (failedChecks >= TERMINAL_SESSION_CHECK_MAX_FAILURES) {
            await endTerminalSession(userSession, ptyProcess, 'revoked', 'Coolify could not verify access to this terminal.');
        }
    }, TERMINAL_SESSION_CHECK_INTERVAL_MS);
}

async function endTerminalSession(userSession, ptyProcess, reason, notice) {
    if (!userSession.isActive || userSession.ptyProcess !== ptyProcess) {
        return;
    }

    logTerminal('log', 'Ending terminal session.', {
        userId: userSession.userId,
        reason,
    });
    userSession.endReason = reason;
    if (userSession.ws.readyState === userSession.ws.OPEN) {
        userSession.ws.send(`Terminal session ended: ${notice}`);
    }
    await killPtyProcess(userSession.userId);
}

// Sent without the browser cookie: the session id authenticates it, and the user may have logged out.
function reportTerminalSessionEnd(terminalSessionId, reason) {
    postToCoolify('/terminal/session/end', {}, { session_id: terminalSessionId, reason })
        .then((response) => {
            if (response.status !== 204) {
                throw createHttpError(response);
            }
        })
        .catch((error) => {
            logTerminal('error', 'Failed to report the end of a terminal session.', {
                reason,
                error: error.message,
            });
        });
}

function rejectTerminalToken(userSession, message) {
    logTerminal('warn', 'Closing websocket connection because the terminal token was rejected.', {
        userId: userSession.userId,
        reason: message,
    });
    rejectTerminalSocket(userSession.ws, TERMINAL_CLOSE_CODES.TOKEN_REJECTED, message);
}

async function handleTerminalToken(userSession, token) {
    if (typeof token !== 'string' || !/^[a-zA-Z0-9]{64}$/.test(token)) {
        rejectTerminalToken(userSession, 'Unauthorized: Invalid terminal token');
        return;
    }

    let terminalSessionId;
    let endReported = false;
    const reportEnd = (reason) => {
        if (typeof terminalSessionId !== 'string' || endReported) return;
        endReported = true;
        reportTerminalSessionEnd(terminalSessionId, reason);
    };

    try {
        const response = await postToCoolify('/terminal/session', userSession.authHeaders, { token });
        terminalSessionId = response.data?.session_id;
        if (response.status !== 200 || typeof response.data?.command !== 'string' || typeof terminalSessionId !== 'string') {
            rejectTerminalToken(userSession, 'Unauthorized: Terminal token was rejected');
            return;
        }

        const started = await handleCommand(userSession.ws, [response.data.command], userSession.userId, terminalSessionId, reportEnd);
        if (!started) {
            reportEnd('rejected');
        }
    } catch (error) {
        reportEnd('rejected');
        logTerminal('error', 'Failed to redeem terminal token.', {
            userId: userSession.userId,
            error: error.message,
        });
        rejectTerminalToken(userSession, 'Unauthorized: Terminal token was rejected');
    }
}

async function handleError(err, userId) {
    logTerminal('error', 'WebSocket error.', {
        userId,
        error: err?.message ?? err,
    });
    markDisconnected(userId);
    await killPtyProcess(userId);
}

async function handleClose(userId) {
    logTerminal('log', 'Cleaning up terminal websocket session.', {
        userId,
    });
    markDisconnected(userId);
    await killPtyProcess(userId);
    userSessions.delete(userId);
}

function markDisconnected(userId) {
    const session = userSessions.get(userId);
    if (session?.isActive) {
        session.endReason = 'disconnected';
    }
}

async function killPtyProcess(userId) {
    const session = userSessions.get(userId);
    if (!session?.ptyProcess) return false;

    if (session.isAttach) {
        return detachPtyProcess(session);
    }

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
            // Ctrl-C first so interactive programs (htop, top, less) quit instead of reading the kill command as keys.
            session.ptyProcess.write('\x03');
            session.ptyProcess.write('set +o history\nkill -TERM -$$ && exit\nset -o history\n');

            setTimeout(() => {
                if (!session.isActive || !session.ptyProcess) {
                    if (session.terminalSessionTimer) {
                        clearTimeout(session.terminalSessionTimer);
                        session.terminalSessionTimer = null;
                    }

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
                    logTerminal('warn', 'PTY process still active after maximum termination attempts. Killing the local SSH client.', {
                        userId,
                        killAttempts,
                    });
                    // Ending the local ssh client makes sshd hang up the remote session, so nothing keeps running there.
                    try {
                        session.ptyProcess.kill();
                    } catch (error) {
                        logTerminal('error', 'Failed to kill PTY process.', {
                            userId,
                            error: error.message,
                        });
                    }
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
