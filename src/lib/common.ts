import shell from 'shelljs';

export function execShellAsync(cmd, opts = {}) {
	try {
		return new Promise(function (resolve, reject) {
			shell.config.silent = true;
			shell.exec(cmd, opts, function (code, stdout, stderr) {
				if (code !== 0) return reject(new Error(stderr));
				return resolve(stdout);
			});
		});
	} catch (error) {
		return new Error('Oops');
	}
}
export function cleanupTmp(dir) {
	if (dir !== '/') shell.rm('-fr', dir);
}
