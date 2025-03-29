import { executeCommand } from "../../../../utils/command";

// Define the BackupInput interface
interface BackupInput {
    id: string;
    destination: string;
    type: string;
    excludeFromEncryption?: boolean;
    excludeFromCompression?: boolean;
    saveContainerLogs?: boolean;
    customName?: string;
    customCommand?: string;
    configuration: MongoDBConfiguration;
}

// Define MongoDB configuration interface
interface MongoDBConfiguration {
    username: string;
    password: string;
    nodes: string[];
    port: number;
    tls?: boolean;
    tlsCa?: string;
}

export const backupMongoDB = async ({
    id,
    destination,
    type,
    excludeFromEncryption,
    excludeFromCompression,
    saveContainerLogs,
    customName,
    customCommand,
    configuration,
}: BackupInput) => {
    const { username, password, nodes, port, tls, tlsCa } = configuration;

    let command = "";
    let connectionString = ""; // Define connectionString variable

    if (customCommand) {
        command = customCommand;
    } else {
        // Build connection string logic would go here
        // ...

        // Check if TLS is enabled and TLS CA is provided
        if (tls && tlsCa) {
            try {
                // Create directory for CA certificate
                await executeCommand({
                    command: `mkdir -p /etc/mongo/certs`,
                    shell: true,
                });

                // Write the CA certificate to file
                await executeCommand({
                    command: `echo "${tlsCa}" > /etc/mongo/certs/ca.pem`,
                    shell: true,
                });

                // Verify the file exists
                await executeCommand({
                    command: `test -f /etc/mongo/certs/ca.pem`,
                    shell: true,
                });

                // Add TLS parameters to connection string
                connectionString += `&tls=true&tlsCAFile=/etc/mongo/certs/ca.pem`;
            } catch (error) {
                console.error("Failed to create or verify CA file:", error);
                throw new Error(
                    "Failed to create or verify CA certificate file for MongoDB backup"
                );
            }
        }

        // ... existing code ...
    }

    // ... existing code ...
};
