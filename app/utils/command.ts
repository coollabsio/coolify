interface CommandOptions {
    command: string;
    shell?: boolean;
}

export const executeCommand = async ({
    command,
    shell = false,
}: CommandOptions): Promise<string> => {
    const { exec } = await import("child_process");

    return new Promise((resolve, reject) => {
        const options = shell ? { shell: "/bin/sh" } : {};
        exec(command, options, (error, stdout, stderr) => {
            if (error) {
                reject(error);
                return;
            }
            resolve(stdout.trim());
        });
    });
};
