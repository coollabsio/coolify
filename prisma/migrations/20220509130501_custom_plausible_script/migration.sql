-- RedefineTables
PRAGMA foreign_keys=OFF;
CREATE TABLE "new_PlausibleAnalytics" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "email" TEXT,
    "username" TEXT,
    "password" TEXT NOT NULL,
    "postgresqlUser" TEXT NOT NULL,
    "postgresqlPassword" TEXT NOT NULL,
    "postgresqlDatabase" TEXT NOT NULL,
    "postgresqlPublicPort" INTEGER,
    "secretKeyBase" TEXT,
    "scriptName" TEXT NOT NULL DEFAULT 'plausible.js',
    "serviceId" TEXT NOT NULL,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "PlausibleAnalytics_serviceId_fkey" FOREIGN KEY ("serviceId") REFERENCES "Service" ("id") ON DELETE RESTRICT ON UPDATE CASCADE
);
INSERT INTO "new_PlausibleAnalytics" ("createdAt", "email", "id", "password", "postgresqlDatabase", "postgresqlPassword", "postgresqlPublicPort", "postgresqlUser", "secretKeyBase", "serviceId", "updatedAt", "username") SELECT "createdAt", "email", "id", "password", "postgresqlDatabase", "postgresqlPassword", "postgresqlPublicPort", "postgresqlUser", "secretKeyBase", "serviceId", "updatedAt", "username" FROM "PlausibleAnalytics";
DROP TABLE "PlausibleAnalytics";
ALTER TABLE "new_PlausibleAnalytics" RENAME TO "PlausibleAnalytics";
CREATE UNIQUE INDEX "PlausibleAnalytics_serviceId_key" ON "PlausibleAnalytics"("serviceId");
PRAGMA foreign_key_check;
PRAGMA foreign_keys=ON;
