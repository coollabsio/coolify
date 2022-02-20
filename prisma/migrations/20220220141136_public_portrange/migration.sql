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
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL
);
INSERT INTO "new_Setting" ("createdAt", "dualCerts", "fqdn", "id", "isRegistrationEnabled", "proxyPassword", "proxyUser", "updatedAt") SELECT "createdAt", "dualCerts", "fqdn", "id", "isRegistrationEnabled", "proxyPassword", "proxyUser", "updatedAt" FROM "Setting";
DROP TABLE "Setting";
ALTER TABLE "new_Setting" RENAME TO "Setting";
CREATE UNIQUE INDEX "Setting_fqdn_key" ON "Setting"("fqdn");
PRAGMA foreign_key_check;
PRAGMA foreign_keys=ON;
