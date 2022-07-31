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
    "isCoolifyProxyUsed" BOOLEAN DEFAULT false,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL
);
INSERT INTO "new_DestinationDocker" ("createdAt", "engine", "id", "isCoolifyProxyUsed", "name", "network", "remoteEngine", "updatedAt") SELECT "createdAt", "engine", "id", "isCoolifyProxyUsed", "name", "network", "remoteEngine", "updatedAt" FROM "DestinationDocker";
DROP TABLE "DestinationDocker";
ALTER TABLE "new_DestinationDocker" RENAME TO "DestinationDocker";
CREATE UNIQUE INDEX "DestinationDocker_network_key" ON "DestinationDocker"("network");
PRAGMA foreign_key_check;
PRAGMA foreign_keys=ON;
