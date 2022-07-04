-- RedefineTables
PRAGMA foreign_keys=OFF;
CREATE TABLE "new_Secret" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "name" TEXT NOT NULL,
    "value" TEXT NOT NULL,
    "isPRMRSecret" BOOLEAN NOT NULL DEFAULT false,
    "isBuildSecret" BOOLEAN NOT NULL DEFAULT false,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    "applicationId" TEXT NOT NULL,
    CONSTRAINT "Secret_applicationId_fkey" FOREIGN KEY ("applicationId") REFERENCES "Application" ("id") ON DELETE RESTRICT ON UPDATE CASCADE
);
INSERT INTO "new_Secret" ("applicationId", "createdAt", "id", "isBuildSecret", "name", "updatedAt", "value") SELECT "applicationId", "createdAt", "id", "isBuildSecret", "name", "updatedAt", "value" FROM "Secret";
DROP TABLE "Secret";
ALTER TABLE "new_Secret" RENAME TO "Secret";
CREATE UNIQUE INDEX "Secret_name_applicationId_isPRMRSecret_key" ON "Secret"("name", "applicationId", "isPRMRSecret");
PRAGMA foreign_key_check;
PRAGMA foreign_keys=ON;
