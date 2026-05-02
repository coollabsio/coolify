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
