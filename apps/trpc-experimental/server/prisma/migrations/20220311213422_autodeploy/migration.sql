-- RedefineTables
PRAGMA foreign_keys=OFF;
CREATE TABLE "new_ApplicationSettings" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "applicationId" TEXT NOT NULL,
    "dualCerts" BOOLEAN NOT NULL DEFAULT false,
    "debug" BOOLEAN NOT NULL DEFAULT false,
    "previews" BOOLEAN NOT NULL DEFAULT false,
    "autodeploy" BOOLEAN NOT NULL DEFAULT true,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "ApplicationSettings_applicationId_fkey" FOREIGN KEY ("applicationId") REFERENCES "Application" ("id") ON DELETE RESTRICT ON UPDATE CASCADE
);
INSERT INTO "new_ApplicationSettings" ("applicationId", "createdAt", "debug", "dualCerts", "id", "previews", "updatedAt") SELECT "applicationId", "createdAt", "debug", "dualCerts", "id", "previews", "updatedAt" FROM "ApplicationSettings";
DROP TABLE "ApplicationSettings";
ALTER TABLE "new_ApplicationSettings" RENAME TO "ApplicationSettings";
CREATE UNIQUE INDEX "ApplicationSettings_applicationId_key" ON "ApplicationSettings"("applicationId");
PRAGMA foreign_key_check;
PRAGMA foreign_keys=ON;
