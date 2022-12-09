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
    "doNotTrack" BOOLEAN NOT NULL DEFAULT false,
    "isAPIDebuggingEnabled" BOOLEAN NOT NULL DEFAULT false,
    "isRegistrationEnabled" BOOLEAN NOT NULL DEFAULT false,
    "isAutoUpdateEnabled" BOOLEAN NOT NULL DEFAULT false,
    "isDNSCheckEnabled" BOOLEAN NOT NULL DEFAULT true,
    "isTraefikUsed" BOOLEAN NOT NULL DEFAULT true,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL
);
INSERT INTO "new_Setting" ("DNSServers", "applicationStoragePathMigrationFinished", "arch", "concurrentBuilds", "createdAt", "doNotTrack", "dualCerts", "fqdn", "id", "ipv4", "ipv6", "isAPIDebuggingEnabled", "isAutoUpdateEnabled", "isDNSCheckEnabled", "isRegistrationEnabled", "isTraefikUsed", "maxPort", "minPort", "proxyDefaultRedirect", "updatedAt") SELECT "DNSServers", "applicationStoragePathMigrationFinished", "arch", "concurrentBuilds", "createdAt", "doNotTrack", "dualCerts", "fqdn", "id", "ipv4", "ipv6", coalesce("isAPIDebuggingEnabled", false) AS "isAPIDebuggingEnabled", "isAutoUpdateEnabled", "isDNSCheckEnabled", "isRegistrationEnabled", "isTraefikUsed", "maxPort", "minPort", "proxyDefaultRedirect", "updatedAt" FROM "Setting";
DROP TABLE "Setting";
ALTER TABLE "new_Setting" RENAME TO "Setting";
CREATE UNIQUE INDEX "Setting_fqdn_key" ON "Setting"("fqdn");
CREATE TABLE "new_GlitchTip" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "postgresqlUser" TEXT NOT NULL,
    "postgresqlPassword" TEXT NOT NULL,
    "postgresqlDatabase" TEXT NOT NULL,
    "postgresqlPublicPort" INTEGER,
    "secretKeyBase" TEXT,
    "defaultEmail" TEXT NOT NULL,
    "defaultUsername" TEXT NOT NULL,
    "defaultPassword" TEXT NOT NULL,
    "defaultEmailFrom" TEXT NOT NULL DEFAULT 'glitchtip@domain.tdl',
    "emailSmtpHost" TEXT DEFAULT 'domain.tdl',
    "emailSmtpPort" INTEGER DEFAULT 25,
    "emailSmtpUser" TEXT,
    "emailSmtpPassword" TEXT,
    "emailSmtpUseTls" BOOLEAN NOT NULL DEFAULT false,
    "emailSmtpUseSsl" BOOLEAN NOT NULL DEFAULT false,
    "emailBackend" TEXT,
    "mailgunApiKey" TEXT,
    "sendgridApiKey" TEXT,
    "enableOpenUserRegistration" BOOLEAN NOT NULL DEFAULT true,
    "serviceId" TEXT NOT NULL,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "GlitchTip_serviceId_fkey" FOREIGN KEY ("serviceId") REFERENCES "Service" ("id") ON DELETE RESTRICT ON UPDATE CASCADE
);
INSERT INTO "new_GlitchTip" ("createdAt", "defaultEmail", "defaultEmailFrom", "defaultPassword", "defaultUsername", "emailBackend", "emailSmtpHost", "emailSmtpPassword", "emailSmtpPort", "emailSmtpUseSsl", "emailSmtpUseTls", "emailSmtpUser", "enableOpenUserRegistration", "id", "mailgunApiKey", "postgresqlDatabase", "postgresqlPassword", "postgresqlPublicPort", "postgresqlUser", "secretKeyBase", "sendgridApiKey", "serviceId", "updatedAt") SELECT "createdAt", "defaultEmail", "defaultEmailFrom", "defaultPassword", "defaultUsername", "emailBackend", "emailSmtpHost", "emailSmtpPassword", "emailSmtpPort", coalesce("emailSmtpUseSsl", false) AS "emailSmtpUseSsl", coalesce("emailSmtpUseTls", false) AS "emailSmtpUseTls", "emailSmtpUser", "enableOpenUserRegistration", "id", "mailgunApiKey", "postgresqlDatabase", "postgresqlPassword", "postgresqlPublicPort", "postgresqlUser", "secretKeyBase", "sendgridApiKey", "serviceId", "updatedAt" FROM "GlitchTip";
DROP TABLE "GlitchTip";
ALTER TABLE "new_GlitchTip" RENAME TO "GlitchTip";
CREATE UNIQUE INDEX "GlitchTip_serviceId_key" ON "GlitchTip"("serviceId");
PRAGMA foreign_key_check;
PRAGMA foreign_keys=ON;
