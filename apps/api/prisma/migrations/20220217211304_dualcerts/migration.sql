-- RedefineTables
PRAGMA foreign_keys=OFF;
CREATE TABLE "new_Setting" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "fqdn" TEXT,
    "isRegistrationEnabled" BOOLEAN NOT NULL DEFAULT false,
    "dualCerts" BOOLEAN NOT NULL DEFAULT false,
    "proxyPassword" TEXT NOT NULL,
    "proxyUser" TEXT NOT NULL,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL
);
INSERT INTO "new_Setting" ("createdAt", "fqdn", "id", "isRegistrationEnabled", "proxyPassword", "proxyUser", "updatedAt") SELECT "createdAt", "fqdn", "id", "isRegistrationEnabled", "proxyPassword", "proxyUser", "updatedAt" FROM "Setting";
DROP TABLE "Setting";
ALTER TABLE "new_Setting" RENAME TO "Setting";
CREATE UNIQUE INDEX "Setting_fqdn_key" ON "Setting"("fqdn");
CREATE TABLE "new_ApplicationSettings" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "applicationId" TEXT NOT NULL,
    "dualCerts" BOOLEAN NOT NULL DEFAULT false,
    "debug" BOOLEAN NOT NULL DEFAULT false,
    "previews" BOOLEAN NOT NULL DEFAULT false,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "ApplicationSettings_applicationId_fkey" FOREIGN KEY ("applicationId") REFERENCES "Application" ("id") ON DELETE RESTRICT ON UPDATE CASCADE
);
INSERT INTO "new_ApplicationSettings" ("applicationId", "createdAt", "debug", "id", "previews", "updatedAt") SELECT "applicationId", "createdAt", "debug", "id", "previews", "updatedAt" FROM "ApplicationSettings";
DROP TABLE "ApplicationSettings";
ALTER TABLE "new_ApplicationSettings" RENAME TO "ApplicationSettings";
CREATE UNIQUE INDEX "ApplicationSettings_applicationId_key" ON "ApplicationSettings"("applicationId");
CREATE TABLE "new_Service" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "name" TEXT NOT NULL,
    "fqdn" TEXT,
    "dualCerts" BOOLEAN NOT NULL DEFAULT false,
    "type" TEXT,
    "version" TEXT,
    "destinationDockerId" TEXT,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "Service_destinationDockerId_fkey" FOREIGN KEY ("destinationDockerId") REFERENCES "DestinationDocker" ("id") ON DELETE SET NULL ON UPDATE CASCADE
);
INSERT INTO "new_Service" ("createdAt", "destinationDockerId", "fqdn", "id", "name", "type", "updatedAt", "version") SELECT "createdAt", "destinationDockerId", "fqdn", "id", "name", "type", "updatedAt", "version" FROM "Service";
DROP TABLE "Service";
ALTER TABLE "new_Service" RENAME TO "Service";
PRAGMA foreign_key_check;
PRAGMA foreign_keys=ON;
