-- RedefineTables
PRAGMA foreign_keys=OFF;
CREATE TABLE "new_Fider" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "serviceId" TEXT NOT NULL,
    "postgresqlUser" TEXT NOT NULL,
    "postgresqlPassword" TEXT NOT NULL,
    "postgresqlDatabase" TEXT NOT NULL,
    "postgresqlPublicPort" INTEGER,
    "jwtSecret" TEXT NOT NULL,
    "emailNoreply" TEXT,
    "emailMailgunApiKey" TEXT,
    "emailMailgunDomain" TEXT,
    "emailMailgunRegion" TEXT NOT NULL DEFAULT 'EU',
    "emailSmtpHost" TEXT,
    "emailSmtpPort" INTEGER,
    "emailSmtpUser" TEXT,
    "emailSmtpPassword" TEXT,
    "emailSmtpEnableStartTls" BOOLEAN NOT NULL DEFAULT false,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "Fider_serviceId_fkey" FOREIGN KEY ("serviceId") REFERENCES "Service" ("id") ON DELETE RESTRICT ON UPDATE CASCADE
);
INSERT INTO "new_Fider" ("createdAt", "emailMailgunApiKey", "emailMailgunDomain", "emailMailgunRegion", "emailNoreply", "emailSmtpEnableStartTls", "emailSmtpHost", "emailSmtpPassword", "emailSmtpPort", "emailSmtpUser", "id", "jwtSecret", "postgresqlDatabase", "postgresqlPassword", "postgresqlPublicPort", "postgresqlUser", "serviceId", "updatedAt") SELECT "createdAt", "emailMailgunApiKey", "emailMailgunDomain", coalesce("emailMailgunRegion", 'EU') AS "emailMailgunRegion", "emailNoreply", "emailSmtpEnableStartTls", "emailSmtpHost", "emailSmtpPassword", "emailSmtpPort", "emailSmtpUser", "id", "jwtSecret", "postgresqlDatabase", "postgresqlPassword", "postgresqlPublicPort", "postgresqlUser", "serviceId", "updatedAt" FROM "Fider";
DROP TABLE "Fider";
ALTER TABLE "new_Fider" RENAME TO "Fider";
CREATE UNIQUE INDEX "Fider_serviceId_key" ON "Fider"("serviceId");
PRAGMA foreign_key_check;
PRAGMA foreign_keys=ON;
