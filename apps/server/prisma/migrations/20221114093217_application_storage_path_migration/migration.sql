-- RedefineTables
PRAGMA foreign_keys=OFF;
CREATE TABLE "new_Setting" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "fqdn" TEXT,
    "isAPIDebuggingEnabled" BOOLEAN DEFAULT false,
    "isRegistrationEnabled" BOOLEAN NOT NULL DEFAULT false,
    "dualCerts" BOOLEAN NOT NULL DEFAULT false,
    "minPort" INTEGER NOT NULL DEFAULT 9000,
    "maxPort" INTEGER NOT NULL DEFAULT 9100,
    "proxyPassword" TEXT NOT NULL,
    "proxyUser" TEXT NOT NULL,
    "proxyHash" TEXT,
    "proxyDefaultRedirect" TEXT,
    "isAutoUpdateEnabled" BOOLEAN NOT NULL DEFAULT false,
    "isDNSCheckEnabled" BOOLEAN NOT NULL DEFAULT true,
    "DNSServers" TEXT,
    "isTraefikUsed" BOOLEAN NOT NULL DEFAULT true,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    "ipv4" TEXT,
    "ipv6" TEXT,
    "arch" TEXT,
    "concurrentBuilds" INTEGER NOT NULL DEFAULT 1,
    "applicationStoragePathMigrationFinished" BOOLEAN NOT NULL DEFAULT false
);
INSERT INTO "new_Setting" ("DNSServers", "arch", "concurrentBuilds", "createdAt", "dualCerts", "fqdn", "id", "ipv4", "ipv6", "isAPIDebuggingEnabled", "isAutoUpdateEnabled", "isDNSCheckEnabled", "isRegistrationEnabled", "isTraefikUsed", "maxPort", "minPort", "proxyDefaultRedirect", "proxyHash", "proxyPassword", "proxyUser", "updatedAt") SELECT "DNSServers", "arch", "concurrentBuilds", "createdAt", "dualCerts", "fqdn", "id", "ipv4", "ipv6", "isAPIDebuggingEnabled", "isAutoUpdateEnabled", "isDNSCheckEnabled", "isRegistrationEnabled", "isTraefikUsed", "maxPort", "minPort", "proxyDefaultRedirect", "proxyHash", "proxyPassword", "proxyUser", "updatedAt" FROM "Setting";
DROP TABLE "Setting";
ALTER TABLE "new_Setting" RENAME TO "Setting";
CREATE UNIQUE INDEX "Setting_fqdn_key" ON "Setting"("fqdn");
CREATE TABLE "new_ApplicationPersistentStorage" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "applicationId" TEXT NOT NULL,
    "path" TEXT NOT NULL,
    "oldPath" BOOLEAN NOT NULL DEFAULT false,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "ApplicationPersistentStorage_applicationId_fkey" FOREIGN KEY ("applicationId") REFERENCES "Application" ("id") ON DELETE RESTRICT ON UPDATE CASCADE
);
INSERT INTO "new_ApplicationPersistentStorage" ("applicationId", "createdAt", "id", "path", "updatedAt") SELECT "applicationId", "createdAt", "id", "path", "updatedAt" FROM "ApplicationPersistentStorage";
DROP TABLE "ApplicationPersistentStorage";
ALTER TABLE "new_ApplicationPersistentStorage" RENAME TO "ApplicationPersistentStorage";
CREATE UNIQUE INDEX "ApplicationPersistentStorage_applicationId_path_key" ON "ApplicationPersistentStorage"("applicationId", "path");
PRAGMA foreign_key_check;
PRAGMA foreign_keys=ON;
