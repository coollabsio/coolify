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
    "organization" TEXT,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    "githubAppId" TEXT,
    "gitlabAppId" TEXT,
    CONSTRAINT "GitSource_githubAppId_fkey" FOREIGN KEY ("githubAppId") REFERENCES "GithubApp" ("id") ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT "GitSource_gitlabAppId_fkey" FOREIGN KEY ("gitlabAppId") REFERENCES "GitlabApp" ("id") ON DELETE SET NULL ON UPDATE CASCADE
);
INSERT INTO "new_GitSource" ("apiUrl", "createdAt", "customPort", "githubAppId", "gitlabAppId", "htmlUrl", "id", "name", "organization", "type", "updatedAt") SELECT "apiUrl", "createdAt", "customPort", "githubAppId", "gitlabAppId", "htmlUrl", "id", "name", "organization", "type", "updatedAt" FROM "GitSource";
DROP TABLE "GitSource";
ALTER TABLE "new_GitSource" RENAME TO "GitSource";
CREATE UNIQUE INDEX "GitSource_githubAppId_key" ON "GitSource"("githubAppId");
CREATE UNIQUE INDEX "GitSource_gitlabAppId_key" ON "GitSource"("gitlabAppId");
CREATE TABLE "new_ApplicationSettings" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "applicationId" TEXT NOT NULL,
    "dualCerts" BOOLEAN NOT NULL DEFAULT false,
    "debug" BOOLEAN NOT NULL DEFAULT false,
    "previews" BOOLEAN NOT NULL DEFAULT false,
    "autodeploy" BOOLEAN NOT NULL DEFAULT true,
    "isBot" BOOLEAN NOT NULL DEFAULT false,
    "isPublicRepository" BOOLEAN NOT NULL DEFAULT false,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "ApplicationSettings_applicationId_fkey" FOREIGN KEY ("applicationId") REFERENCES "Application" ("id") ON DELETE RESTRICT ON UPDATE CASCADE
);
INSERT INTO "new_ApplicationSettings" ("applicationId", "autodeploy", "createdAt", "debug", "dualCerts", "id", "isBot", "previews", "updatedAt") SELECT "applicationId", "autodeploy", "createdAt", "debug", "dualCerts", "id", "isBot", "previews", "updatedAt" FROM "ApplicationSettings";
DROP TABLE "ApplicationSettings";
ALTER TABLE "new_ApplicationSettings" RENAME TO "ApplicationSettings";
CREATE UNIQUE INDEX "ApplicationSettings_applicationId_key" ON "ApplicationSettings"("applicationId");
PRAGMA foreign_key_check;
PRAGMA foreign_keys=ON;
