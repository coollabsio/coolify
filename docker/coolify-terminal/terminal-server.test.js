import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('./terminal-server.js', import.meta.url), 'utf8');
const handler = source.slice(source.indexOf('async function handleTerminalToken('), source.indexOf('async function handleError('));

for (const exitDuringStartup of [false, true]) {
    test(`startup failure reports one end (exit during startup: ${exitDuringStartup})`, async () => {
        const reports = [];
        let exit;
        const context = vm.createContext({
            postToCoolify: async () => ({ status: 200, data: { command: 'ssh', session_id: 'session' } }),
            handleCommand: async (_ws, _command, _userId, _id, reportEnd) => {
                exit = () => reportEnd('exited');
                if (exitDuringStartup) exit();
                throw new Error('Startup failed');
            },
            reportTerminalSessionEnd: (id, reason) => reports.push({ id, reason }),
            logTerminal() {},
            rejectTerminalToken() {},
        });
        vm.runInContext(handler, context);
        await context.handleTerminalToken({ ws: {}, userId: 'user' }, 'a'.repeat(64));
        if (!exitDuringStartup) exit();
        assert.deepEqual(reports, [{ id: 'session', reason: exitDuringStartup ? 'exited' : 'rejected' }]);
    });
}
