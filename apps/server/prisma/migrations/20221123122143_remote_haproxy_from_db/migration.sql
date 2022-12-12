/*
  Warnings:

  - You are about to drop the column `proxyHash` on the `Setting` table. All the data in the column will be lost.
  - You are about to drop the column `proxyPassword` on the `Setting` table. All the data in the column will be lost.
  - You are about to drop the column `proxyUser` on the `Setting` table. All the data in the column will be lost.

*/
-- RedefineTables
PRAGMA foreign_keys=OFF;
CREATE TABLE "new_Setting" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "fqdn" TEXT,
    "dualCerts" BOOLEAN NOT NULL DEFAULT false,
    "minPort" INTEGER NOT NULL DEFAULT 9000,
    "maxPort" INTEGER NOT NULL DEFAULT 9100,
    "DNSServers" TEXT,
    "ipv4" TEXT,
    "ipv6" TEXT,
    "arch" TEXT,
    "concurrentBuilds" INTEGER NOT NULL DEFAULT 1,
    "applicationStoragePathMigrationFinished" BOOLEAN NOT NULL DEFAULT false,
    "proxyDefaultRedirect" TEXT,
    "isAPIDebuggingEnabled" BOOLEAN DEFAULT false,
    "isRegistrationEnabled" BOOLEAN NOT NULL DEFAULT false,
    "isAutoUpdateEnabled" BOOLEAN NOT NULL DEFAULT false,
    "isDNSCheckEnabled" BOOLEAN NOT NULL DEFAULT true,
    "isTraefikUsed" BOOLEAN NOT NULL DEFAULT true,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL
);
INSERT INTO "new_Setting" ("DNSServers", "applicationStoragePathMigrationFinished", "arch", "concurrentBuilds", "createdAt", "dualCerts", "fqdn", "id", "ipv4", "ipv6", "isAPIDebuggingEnabled", "isAutoUpdateEnabled", "isDNSCheckEnabled", "isRegistrationEnabled", "isTraefikUsed", "maxPort", "minPort", "proxyDefaultRedirect", "updatedAt") SELECT "DNSServers", "applicationStoragePathMigrationFinished", "arch", "concurrentBuilds", "createdAt", "dualCerts", "fqdn", "id", "ipv4", "ipv6", "isAPIDebuggingEnabled", "isAutoUpdateEnabled", "isDNSCheckEnabled", "isRegistrationEnabled", "isTraefikUsed", "maxPort", "minPort", "proxyDefaultRedirect", "updatedAt" FROM "Setting";
DROP TABLE "Setting";
ALTER TABLE "new_Setting" RENAME TO "Setting";
CREATE UNIQUE INDEX "Setting_fqdn_key" ON "Setting"("fqdn");
PRAGMA foreign_key_check;
PRAGMA foreign_keys=ON;
