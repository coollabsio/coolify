-- RedefineTables
PRAGMA foreign_keys=OFF;
CREATE TABLE "new_Team" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "name" TEXT,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    "databaseId" TEXT,
    "serviceId" TEXT
);
INSERT INTO "new_Team" ("createdAt", "databaseId", "id", "name", "serviceId", "updatedAt") SELECT "createdAt", "databaseId", "id", "name", "serviceId", "updatedAt" FROM "Team";
DROP TABLE "Team";
ALTER TABLE "new_Team" RENAME TO "Team";
CREATE TABLE "new_DatabaseSettings" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "databaseId" TEXT NOT NULL,
    "isPublic" BOOLEAN NOT NULL DEFAULT false,
    "appendOnly" BOOLEAN NOT NULL DEFAULT true,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "DatabaseSettings_databaseId_fkey" FOREIGN KEY ("databaseId") REFERENCES "Database" ("id") ON DELETE RESTRICT ON UPDATE CASCADE
);
INSERT INTO "new_DatabaseSettings" ("createdAt", "databaseId", "id", "isPublic", "updatedAt") SELECT "createdAt", "databaseId", "id", "isPublic", "updatedAt" FROM "DatabaseSettings";
DROP TABLE "DatabaseSettings";
ALTER TABLE "new_DatabaseSettings" RENAME TO "DatabaseSettings";
CREATE UNIQUE INDEX "DatabaseSettings_databaseId_key" ON "DatabaseSettings"("databaseId");
PRAGMA foreign_key_check;
PRAGMA foreign_keys=ON;
