-- CreateTable
CREATE TABLE "SshKey" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "name" TEXT NOT NULL,
    "privateKey" TEXT NOT NULL,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL
);

-- RedefineTables
PRAGMA foreign_keys=OFF;
CREATE TABLE "new_DestinationDocker" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "network" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "engine" TEXT,
    "remoteEngine" BOOLEAN NOT NULL DEFAULT false,
    "remoteIpAddress" TEXT,
    "remoteUser" TEXT,
    "remotePort" INTEGER,
    "remoteVerified" BOOLEAN NOT NULL DEFAULT false,
    "isCoolifyProxyUsed" BOOLEAN DEFAULT false,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    "sshKeyId" TEXT,
    CONSTRAINT "DestinationDocker_sshKeyId_fkey" FOREIGN KEY ("sshKeyId") REFERENCES "SshKey" ("id") ON DELETE SET NULL ON UPDATE CASCADE
);
INSERT INTO "new_DestinationDocker" ("createdAt", "engine", "id", "isCoolifyProxyUsed", "name", "network", "remoteEngine", "remoteIpAddress", "remotePort", "remoteUser", "updatedAt") SELECT "createdAt", "engine", "id", "isCoolifyProxyUsed", "name", "network", "remoteEngine", "remoteIpAddress", "remotePort", "remoteUser", "updatedAt" FROM "DestinationDocker";
DROP TABLE "DestinationDocker";
ALTER TABLE "new_DestinationDocker" RENAME TO "DestinationDocker";
CREATE UNIQUE INDEX "DestinationDocker_network_key" ON "DestinationDocker"("network");
PRAGMA foreign_key_check;
PRAGMA foreign_keys=ON;
