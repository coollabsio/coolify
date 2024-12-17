import { WebSocketServer } from 'ws';
import http from 'http';
import pty from 'node-pty';
import axios from 'axios';
import cookie from 'cookie';
import 'dotenv/config'

const server = http.createServer((req, res) => {
    if (req.url === '/ready') {
        res.writeHead(200, { 'Content-Type': 'text/plain' });
        res.end('OK');
    } else {
        res.writeHead(404, { 'Content-Type': 'text/plain' });
        res.end('Not Found');
    }
});

const verifyClient = async (info, callback) => {
    const cookies = cookie.parse(info.req.headers.cookie || '');
    // const origin = new URL(info.origin);
    // const protocol = origin.protocol;
    const xsrfToken = cookies['XSRF-TOKEN'];

    // Generate session cookie name based on APP_NAME
    const appName = process.env.APP_NAME || 'laravel';
    const sessionCookieName = `${appName.replace(/[^a-zA-Z0-9]/g, '_').toLowerCase()}_session`;
    const laravelSession = cookies[sessionCookieName];

    // Verify presence of required tokens
    if (!laravelSession || !xsrfToken) {
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
            // Authentication successful
            callback(true);
        } else {
            callback(false, 401, 'Unauthorized: Invalid credentials');
        }
    } catch (error) {
        console.error('Authentication error:', error.message);
        callback(false, 500, 'Internal Server Error');
    }
};


const wss = new WebSocketServer({ server, path: '/terminal/ws', verifyClient: verifyClient });
const userSessions = new Map();

wss.on('connection', (ws) => {
    const userId = generateUserId();
    const userSession = { ws, userId, ptyProcess: null, isActive: false };
    userSessions.set(userId, userSession);

    ws.on('message', (message) => {
        handleMessage(userSession, message);

    });
    ws.on('error', (err) => handleError(err, userId));
    ws.on('close', () => handleClose(userId));

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
    if (!parsed) return;

    Object.entries(parsed).forEach(([key, value]) => {
        const handler = messageHandlers[key];
        if (handler && (userSession.isActive || key === 'checkActive' || key === 'command')) {
            handler(userSession, value);
        }
    });
}

function parseMessage(message) {
    try {
        return JSON.parse(message);
    } catch (e) {
        console.error('Failed to parse message:', e);
        return null;
    }
}

async function handleCommand(ws, command, userId) {
    const userSession = userSessions.get(userId);
    if (userSession && userSession.isActive) {
        const result = await killPtyProcess(userId);
        if (!result) {
            // if terminal is still active, even after we tried to kill it, dont continue and show error
            ws.send('unprocessable');
            return;
        }
    }

    const commandString = command[0].split('\n').join(' ');
    const timeout = extractTimeout(commandString);
    const sshArgs = extractSshArgs(commandString);
    const hereDocContent = extractHereDocContent(commandString);
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
    const ptyProcess = pty.spawn('ssh', sshArgs.concat([hereDocContent]), options);

    userSession.ptyProcess = ptyProcess;
    userSession.isActive = true;

    ws.send('pty-ready');

    ptyProcess.onData((data) => {
        ws.send(data);
    });

    // when parent closes
    ptyProcess.onExit(({ exitCode, signal }) => {
        console.error(`Process exited with code ${exitCode} and signal ${signal}`);
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
    console.error('WebSocket error:', err);
    await killPtyProcess(userId);
}

async function handleClose(userId) {
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

            // session.ptyProcess.kill() wont work here because of https://github.com/moby/moby/issues/9098
            // patch with https://github.com/moby/moby/issues/9098#issuecomment-189743947
            session.ptyProcess.write('set +o history\nkill -TERM -$$ && exit\nset -o history\n');

            setTimeout(() => {
                if (!session.isActive || !session.ptyProcess) {
                    resolve(true);
                    return;
                }

                if (killAttempts < maxAttempts) {
                    attemptKill();
                } else {
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

function extractTimeout(commandString) {
    const timeoutMatch = commandString.match(/timeout (\d+)/);
    return timeoutMatch ? parseInt(timeoutMatch[1], 10) : null;
}

function extractSshArgs(commandString) {
    const sshCommandMatch = commandString.match(/ssh (.+?) 'bash -se'/);
    let sshArgs = sshCommandMatch ? sshCommandMatch[1].split(' ') : [];
    sshArgs = sshArgs.map(arg => arg === 'RequestTTY=no' ? 'RequestTTY=yes' : arg);
    if (!sshArgs.includes('RequestTTY=yes')) {
        sshArgs.push('-o', 'RequestTTY=yes');
    }
    return sshArgs;
}

function extractHereDocContent(commandString) {
    const delimiterMatch = commandString.match(/<< (\S+)/);
    const delimiter = delimiterMatch ? delimiterMatch[1] : null;
    const escapedDelimiter = delimiter.slice(1).trim().replace(/[/\-\\^$*+?.()|[\]{}]/g, '\\$&');
    const hereDocRegex = new RegExp(`<< \\\\${escapedDelimiter}([\\s\\S\\.]*?)${escapedDelimiter}`);
    const hereDocMatch = commandString.match(hereDocRegex);
    return hereDocMatch ? hereDocMatch[1] : '';
}

server.listen(6002, () => {
    console.log('Coolify realtime terminal server listening on port 6002. Let the hacking begin!');
});
