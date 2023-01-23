-- RedefineTables
PRAGMA foreign_keys=OFF;
CREATE TABLE "new_Setting" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "fqdn" TEXT,
    "isRegistrationEnabled" BOOLEAN NOT NULL DEFAULT false,
    "dualCerts" BOOLEAN NOT NULL DEFAULT false,
    "minPort" INTEGER NOT NULL DEFAULT 9000,
    "maxPort" INTEGER NOT NULL DEFAULT 9100,
    "proxyPassword" TEXT NOT NULL,
    "proxyUser" TEXT NOT NULL,
    "proxyHash" TEXT,
    "isAutoUpdateEnabled" BOOLEAN NOT NULL DEFAULT false,
    "isDNSCheckEnabled" BOOLEAN NOT NULL DEFAULT true,
    "DNSServers" TEXT,
    "isTraefikUsed" BOOLEAN NOT NULL DEFAULT true,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    "ipv4" TEXT,
    "ipv6" TEXT,
    "arch" TEXT,
    "concurrentBuilds" INTEGER NOT NULL DEFAULT 1
);
INSERT INTO "new_Setting" ("DNSServers", "arch", "createdAt", "dualCerts", "fqdn", "id", "ipv4", "ipv6", "isAutoUpdateEnabled", "isDNSCheckEnabled", "isRegistrationEnabled", "isTraefikUsed", "maxPort", "minPort", "proxyHash", "proxyPassword", "proxyUser", "updatedAt") SELECT "DNSServers", "arch", "createdAt", "dualCerts", "fqdn", "id", "ipv4", "ipv6", "isAutoUpdateEnabled", "isDNSCheckEnabled", "isRegistrationEnabled", "isTraefikUsed", "maxPort", "minPort", "proxyHash", "proxyPassword", "proxyUser", "updatedAt" FROM "Setting";
DROP TABLE "Setting";
ALTER TABLE "new_Setting" RENAME TO "Setting";
CREATE UNIQUE INDEX "Setting_fqdn_key" ON "Setting"("fqdn");
PRAGMA foreign_key_check;
PRAGMA foreign_keys=ON;
