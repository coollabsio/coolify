-- RedefineTables
PRAGMA foreign_keys=OFF;
CREATE TABLE "new_Build" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "type" TEXT NOT NULL,
    "applicationId" TEXT,
    "destinationDockerId" TEXT,
    "gitSourceId" TEXT,
    "githubAppId" TEXT,
    "gitlabAppId" TEXT,
    "commit" TEXT,
    "pullmergeRequestId" TEXT,
    "forceRebuild" BOOLEAN NOT NULL DEFAULT false,
    "sourceBranch" TEXT,
    "branch" TEXT,
    "status" TEXT DEFAULT 'queued',
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL
);
INSERT INTO "new_Build" ("applicationId", "branch", "commit", "createdAt", "destinationDockerId", "gitSourceId", "githubAppId", "gitlabAppId", "id", "status", "type", "updatedAt") SELECT "applicationId", "branch", "commit", "createdAt", "destinationDockerId", "gitSourceId", "githubAppId", "gitlabAppId", "id", "status", "type", "updatedAt" FROM "Build";
DROP TABLE "Build";
ALTER TABLE "new_Build" RENAME TO "Build";
PRAGMA foreign_key_check;
PRAGMA foreign_keys=ON;
