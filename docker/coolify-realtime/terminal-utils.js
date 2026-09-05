export const MAX_TERMINAL_SESSION_TIMEOUT_SECONDS = 8 * 60 * 60;

export function getTerminalSessionTimeout() {
    return MAX_TERMINAL_SESSION_TIMEOUT_SECONDS;
}

export function extractTimeout(commandString) {
    const timeoutMatch = commandString.match(/timeout (\d+)/);
    return timeoutMatch ? parseInt(timeoutMatch[1], 10) : null;
}

function normalizeShellArgument(argument) {
    if (!argument) {
        return argument;
    }

    return argument
        .replace(/'([^']*)'/g, '$1')
        .replace(/"([^"]*)"/g, '$1');
}

export function extractSshArgs(commandString) {
    const sshCommandMatch = commandString.match(/ssh (.+?) 'bash -se'/);
    if (!sshCommandMatch) return [];

    const argsString = sshCommandMatch[1];
    let sshArgs = [];

    let current = '';
    let inQuotes = false;
    let quoteChar = '';
    let i = 0;

    while (i < argsString.length) {
        const char = argsString[i];

        if (!inQuotes && (char === '"' || char === "'")) {
            inQuotes = true;
            quoteChar = char;
            current += char;
        } else if (inQuotes && char === quoteChar) {
            inQuotes = false;
            current += char;
            quoteChar = '';
        } else if (!inQuotes && char === ' ') {
            if (current.trim()) {
                sshArgs.push(current.trim());
                current = '';
            }
        } else {
            current += char;
        }
        i++;
    }

    if (current.trim()) {
        sshArgs.push(current.trim());
    }

    sshArgs = sshArgs.map((arg) => normalizeShellArgument(arg));
    sshArgs = sshArgs.map(arg => arg === 'RequestTTY=no' ? 'RequestTTY=yes' : arg);

    if (!sshArgs.includes('RequestTTY=yes') && !sshArgs.some(arg => arg.includes('RequestTTY='))) {
        sshArgs.push('-o', 'RequestTTY=yes');
    }

    return sshArgs;
}

export function extractHereDocContent(commandString) {
    const delimiterMatch = commandString.match(/<< (\S+)/);
    const delimiter = delimiterMatch ? delimiterMatch[1] : null;
    const escapedDelimiter = delimiter?.slice(1).trim().replace(/[/\-\\^$*+?.()|[\]{}]/g, '\\$&');

    if (!escapedDelimiter) {
        return '';
    }

    const hereDocRegex = new RegExp(`<< \\\\${escapedDelimiter}([\\s\\S\\.]*?)${escapedDelimiter}`);
    const hereDocMatch = commandString.match(hereDocRegex);
    return hereDocMatch ? hereDocMatch[1] : '';
}

export function normalizeHostForAuthorization(host) {
    if (!host) {
        return null;
    }

    let normalizedHost = host.trim();

    while (
        normalizedHost.length >= 2 &&
        ((normalizedHost.startsWith("'") && normalizedHost.endsWith("'")) ||
            (normalizedHost.startsWith('"') && normalizedHost.endsWith('"')))
    ) {
        normalizedHost = normalizedHost.slice(1, -1).trim();
    }

    if (normalizedHost.startsWith('[') && normalizedHost.endsWith(']')) {
        normalizedHost = normalizedHost.slice(1, -1);
    }

    return normalizedHost.toLowerCase();
}

export function extractTargetHost(sshArgs) {
    const userAtHost = sshArgs.find(arg => {
        if (arg.includes('storage/app/ssh/keys/')) {
            return false;
        }

        return /^[^@]+@[^@]+$/.test(arg);
    });

    if (!userAtHost) {
        return null;
    }

    const atIndex = userAtHost.indexOf('@');
    return normalizeHostForAuthorization(userAtHost.slice(atIndex + 1));
}

export function isAuthorizedTargetHost(targetHost, authorizedHosts = []) {
    const normalizedTargetHost = normalizeHostForAuthorization(targetHost);

    if (!normalizedTargetHost) {
        return false;
    }

    return authorizedHosts
        .map(host => normalizeHostForAuthorization(host))
        .includes(normalizedTargetHost);
}

const REQUIRED_SSH_OPTIONS = new Set([
    'StrictHostKeyChecking',
    'UserKnownHostsFile',
    'PasswordAuthentication',
    'ConnectTimeout',
    'ServerAliveInterval',
    'RequestTTY',
    'LogLevel',
]);

function isAllowedSshOption(name, value) {
    const fixedOptions = {
        StrictHostKeyChecking: 'no',
        UserKnownHostsFile: '/dev/null',
        PasswordAuthentication: 'no',
        LogLevel: 'ERROR',
        ControlMaster: 'auto',
        ProxyCommand: 'cloudflared access ssh --hostname %h',
    };

    if (Object.hasOwn(fixedOptions, name)) {
        return value === fixedOptions[name];
    }

    if (name === 'RequestTTY') {
        return value === 'yes' || value === 'no';
    }

    if (name === 'ConnectTimeout' || name === 'ServerAliveInterval' || name === 'ControlPersist') {
        return /^\d+$/.test(value) && Number(value) > 0;
    }

    if (name === 'ControlPath') {
        return /^\/var\/www\/html\/storage\/app\/ssh\/mux\/mux_[a-zA-Z0-9_-]+$/.test(value);
    }

    return false;
}

export function validateSshArgs(sshArgs, authorizedHosts = []) {
    if (!Array.isArray(sshArgs) || sshArgs.length === 0) {
        return false;
    }

    const seenOptions = new Set();
    let hasIdentityFile = false;
    let hasPort = false;
    let targetHost = null;

    for (let index = 0; index < sshArgs.length; index++) {
        const argument = sshArgs[index];

        if (typeof argument !== 'string' || /[\0\r\n]/.test(argument)) {
            return false;
        }

        if (argument === '-i') {
            const identityFile = sshArgs[++index];
            if (hasIdentityFile || !/^\/var\/www\/html\/storage\/app\/ssh\/keys\/ssh_key@[a-zA-Z0-9_-]+$/.test(identityFile ?? '')) {
                return false;
            }
            hasIdentityFile = true;
            continue;
        }

        if (argument === '-p') {
            const port = sshArgs[++index];
            if (hasPort || !/^\d+$/.test(port ?? '') || Number(port) < 1 || Number(port) > 65535) {
                return false;
            }
            hasPort = true;
            continue;
        }

        if (argument === '-o') {
            const option = sshArgs[++index];
            const separator = option?.indexOf('=') ?? -1;
            if (separator < 1) {
                return false;
            }

            const name = option.slice(0, separator);
            const value = option.slice(separator + 1);
            if (seenOptions.has(name) || !isAllowedSshOption(name, value)) {
                return false;
            }
            seenOptions.add(name);
            continue;
        }

        if (/^[a-zA-Z0-9_][a-zA-Z0-9._-]*@[^@]+$/.test(argument) && targetHost === null) {
            targetHost = extractTargetHost([argument]);
            continue;
        }

        return false;
    }

    const hasRequiredOptions = [...REQUIRED_SSH_OPTIONS].every(option => seenOptions.has(option));
    const hasCompleteMultiplexingOptions =
        !['ControlMaster', 'ControlPath', 'ControlPersist'].some(option => seenOptions.has(option))
        || ['ControlMaster', 'ControlPath', 'ControlPersist'].every(option => seenOptions.has(option));

    return hasIdentityFile
        && hasPort
        && targetHost !== null
        && hasRequiredOptions
        && hasCompleteMultiplexingOptions
        && isAuthorizedTargetHost(targetHost, authorizedHosts);
}

export function sanitizeSshArgs(sshArgs) {
    const multiplexingOptions = new Set(['ControlMaster', 'ControlPath', 'ControlPersist']);
    const sanitizedArgs = [];

    for (let index = 0; index < sshArgs.length; index++) {
        if (sshArgs[index] === '-o') {
            const optionName = sshArgs[index + 1]?.split('=', 1)[0];
            if (multiplexingOptions.has(optionName)) {
                index++;
                continue;
            }
        }

        sanitizedArgs.push(sshArgs[index]);
    }

    return sanitizedArgs;
}
