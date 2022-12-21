-- RedefineTables
PRAGMA foreign_keys=OFF;
CREATE TABLE "new_GitSource" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "name" TEXT NOT NULL,
    "forPublic" BOOLEAN NOT NULL DEFAULT false,
    "type" TEXT,
    "apiUrl" TEXT,
    "htmlUrl" TEXT,
    "customPort" INTEGER NOT NULL DEFAULT 22,
    "customUser" TEXT NOT NULL DEFAULT 'git',
    "organization" TEXT,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    "githubAppId" TEXT,
    "gitlabAppId" TEXT,
    "isSystemWide" BOOLEAN NOT NULL DEFAULT false,
    CONSTRAINT "GitSource_gitlabAppId_fkey" FOREIGN KEY ("gitlabAppId") REFERENCES "GitlabApp" ("id") ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT "GitSource_githubAppId_fkey" FOREIGN KEY ("githubAppId") REFERENCES "GithubApp" ("id") ON DELETE SET NULL ON UPDATE CASCADE
);
INSERT INTO "new_GitSource" ("apiUrl", "createdAt", "customPort", "forPublic", "githubAppId", "gitlabAppId", "htmlUrl", "id", "isSystemWide", "name", "organization", "type", "updatedAt") SELECT "apiUrl", "createdAt", "customPort", "forPublic", "githubAppId", "gitlabAppId", "htmlUrl", "id", "isSystemWide", "name", "organization", "type", "updatedAt" FROM "GitSource";
DROP TABLE "GitSource";
ALTER TABLE "new_GitSource" RENAME TO "GitSource";
CREATE UNIQUE INDEX "GitSource_githubAppId_key" ON "GitSource"("githubAppId");
CREATE UNIQUE INDEX "GitSource_gitlabAppId_key" ON "GitSource"("gitlabAppId");
PRAGMA foreign_key_check;
PRAGMA foreign_keys=ON;
