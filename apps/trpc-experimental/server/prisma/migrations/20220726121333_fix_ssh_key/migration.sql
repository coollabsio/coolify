-- RedefineTables
PRAGMA foreign_keys=OFF;
CREATE TABLE "new_SshKey" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "name" TEXT NOT NULL,
    "privateKey" TEXT NOT NULL,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    "teamId" TEXT,
    CONSTRAINT "SshKey_teamId_fkey" FOREIGN KEY ("teamId") REFERENCES "Team" ("id") ON DELETE SET NULL ON UPDATE CASCADE
);
INSERT INTO "new_SshKey" ("createdAt", "id", "name", "privateKey", "updatedAt") SELECT "createdAt", "id", "name", "privateKey", "updatedAt" FROM "SshKey";
DROP TABLE "SshKey";
ALTER TABLE "new_SshKey" RENAME TO "SshKey";
PRAGMA foreign_key_check;
PRAGMA foreign_keys=ON;
