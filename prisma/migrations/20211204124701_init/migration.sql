-- CreateTable
CREATE TABLE "Setting" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "name" TEXT NOT NULL,
    "value" TEXT NOT NULL,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL
);

-- CreateTable
CREATE TABLE "User" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "email" TEXT NOT NULL,
    "type" TEXT NOT NULL,
    "password" TEXT,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL
);

-- CreateTable
CREATE TABLE "Permission" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "userId" TEXT NOT NULL,
    "teamId" TEXT NOT NULL,
    "permission" TEXT NOT NULL,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "Permission_userId_fkey" FOREIGN KEY ("userId") REFERENCES "User" ("id") ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT "Permission_teamId_fkey" FOREIGN KEY ("teamId") REFERENCES "Team" ("id") ON DELETE RESTRICT ON UPDATE CASCADE
);

-- CreateTable
CREATE TABLE "Team" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "name" TEXT,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL
);

-- CreateTable
CREATE TABLE "TeamInvitation" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "uid" TEXT NOT NULL,
    "email" TEXT NOT NULL,
    "teamId" TEXT NOT NULL,
    "teamName" TEXT NOT NULL,
    "permission" TEXT NOT NULL,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- CreateTable
CREATE TABLE "Application" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "domain" TEXT,
    "name" TEXT NOT NULL,
    "oldDomain" TEXT,
    "repository" TEXT,
    "branch" TEXT,
    "buildPack" TEXT,
    "projectId" INTEGER,
    "port" INTEGER,
    "installCommand" TEXT,
    "buildCommand" TEXT,
    "startCommand" TEXT,
    "configHash" TEXT,
    "baseDirectory" TEXT,
    "publishDirectory" TEXT,
    "forceSsl" BOOLEAN DEFAULT false,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    "destinationDockerId" TEXT,
    "gitSourceId" TEXT,
    CONSTRAINT "Application_destinationDockerId_fkey" FOREIGN KEY ("destinationDockerId") REFERENCES "DestinationDocker" ("id") ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT "Application_gitSourceId_fkey" FOREIGN KEY ("gitSourceId") REFERENCES "GitSource" ("id") ON DELETE SET NULL ON UPDATE CASCADE
);

-- CreateTable
CREATE TABLE "BuildLog" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "applicationId" TEXT,
    "buildId" TEXT NOT NULL,
    "line" TEXT NOT NULL,
    "time" INTEGER NOT NULL
);

-- CreateTable
CREATE TABLE "Build" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "applicationId" TEXT,
    "destinationDockerId" TEXT,
    "gitSourceId" TEXT,
    "githubAppId" TEXT,
    "gitlabAppId" TEXT,
    "commit" TEXT,
    "status" TEXT DEFAULT 'queued',
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL
);

-- CreateTable
CREATE TABLE "DestinationDocker" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "network" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "engine" TEXT NOT NULL,
    "isSwarm" BOOLEAN DEFAULT false,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL
);

-- CreateTable
CREATE TABLE "GitSource" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "name" TEXT NOT NULL,
    "type" TEXT,
    "apiUrl" TEXT,
    "htmlUrl" TEXT,
    "organization" TEXT,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    "githubAppId" TEXT,
    "gitlabAppId" TEXT,
    CONSTRAINT "GitSource_githubAppId_fkey" FOREIGN KEY ("githubAppId") REFERENCES "GithubApp" ("id") ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT "GitSource_gitlabAppId_fkey" FOREIGN KEY ("gitlabAppId") REFERENCES "GitlabApp" ("id") ON DELETE SET NULL ON UPDATE CASCADE
);

-- CreateTable
CREATE TABLE "GithubApp" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "name" TEXT,
    "appId" INTEGER,
    "installationId" INTEGER,
    "clientId" TEXT,
    "clientSecret" TEXT,
    "webhookSecret" TEXT,
    "privateKey" TEXT,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL
);

-- CreateTable
CREATE TABLE "GitlabApp" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "oauthId" INTEGER NOT NULL,
    "groupName" TEXT,
    "deployKeyId" INTEGER,
    "privateSshKey" TEXT,
    "appId" TEXT,
    "appSecret" TEXT,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL
);

-- CreateTable
CREATE TABLE "_TeamToUser" (
    "A" TEXT NOT NULL,
    "B" TEXT NOT NULL,
    FOREIGN KEY ("A") REFERENCES "Team" ("id") ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY ("B") REFERENCES "User" ("id") ON DELETE CASCADE ON UPDATE CASCADE
);

-- CreateTable
CREATE TABLE "_ApplicationToTeam" (
    "A" TEXT NOT NULL,
    "B" TEXT NOT NULL,
    FOREIGN KEY ("A") REFERENCES "Application" ("id") ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY ("B") REFERENCES "Team" ("id") ON DELETE CASCADE ON UPDATE CASCADE
);

-- CreateTable
CREATE TABLE "_GitSourceToTeam" (
    "A" TEXT NOT NULL,
    "B" TEXT NOT NULL,
    FOREIGN KEY ("A") REFERENCES "GitSource" ("id") ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY ("B") REFERENCES "Team" ("id") ON DELETE CASCADE ON UPDATE CASCADE
);

-- CreateTable
CREATE TABLE "_GithubAppToTeam" (
    "A" TEXT NOT NULL,
    "B" TEXT NOT NULL,
    FOREIGN KEY ("A") REFERENCES "GithubApp" ("id") ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY ("B") REFERENCES "Team" ("id") ON DELETE CASCADE ON UPDATE CASCADE
);

-- CreateTable
CREATE TABLE "_GitlabAppToTeam" (
    "A" TEXT NOT NULL,
    "B" TEXT NOT NULL,
    FOREIGN KEY ("A") REFERENCES "GitlabApp" ("id") ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY ("B") REFERENCES "Team" ("id") ON DELETE CASCADE ON UPDATE CASCADE
);

-- CreateTable
CREATE TABLE "_DestinationDockerToTeam" (
    "A" TEXT NOT NULL,
    "B" TEXT NOT NULL,
    FOREIGN KEY ("A") REFERENCES "DestinationDocker" ("id") ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY ("B") REFERENCES "Team" ("id") ON DELETE CASCADE ON UPDATE CASCADE
);

-- CreateIndex
CREATE UNIQUE INDEX "Setting_name_key" ON "Setting"("name");

-- CreateIndex
CREATE UNIQUE INDEX "User_id_key" ON "User"("id");

-- CreateIndex
CREATE UNIQUE INDEX "User_email_key" ON "User"("email");

-- CreateIndex
CREATE UNIQUE INDEX "Application_domain_key" ON "Application"("domain");

-- CreateIndex
CREATE UNIQUE INDEX "DestinationDocker_network_key" ON "DestinationDocker"("network");

-- CreateIndex
CREATE UNIQUE INDEX "GitSource_githubAppId_key" ON "GitSource"("githubAppId");

-- CreateIndex
CREATE UNIQUE INDEX "GitSource_gitlabAppId_key" ON "GitSource"("gitlabAppId");

-- CreateIndex
CREATE UNIQUE INDEX "GithubApp_name_key" ON "GithubApp"("name");

-- CreateIndex
CREATE UNIQUE INDEX "GitlabApp_oauthId_key" ON "GitlabApp"("oauthId");

-- CreateIndex
CREATE UNIQUE INDEX "GitlabApp_groupName_key" ON "GitlabApp"("groupName");

-- CreateIndex
CREATE UNIQUE INDEX "_TeamToUser_AB_unique" ON "_TeamToUser"("A", "B");

-- CreateIndex
CREATE INDEX "_TeamToUser_B_index" ON "_TeamToUser"("B");

-- CreateIndex
CREATE UNIQUE INDEX "_ApplicationToTeam_AB_unique" ON "_ApplicationToTeam"("A", "B");

-- CreateIndex
CREATE INDEX "_ApplicationToTeam_B_index" ON "_ApplicationToTeam"("B");

-- CreateIndex
CREATE UNIQUE INDEX "_GitSourceToTeam_AB_unique" ON "_GitSourceToTeam"("A", "B");

-- CreateIndex
CREATE INDEX "_GitSourceToTeam_B_index" ON "_GitSourceToTeam"("B");

-- CreateIndex
CREATE UNIQUE INDEX "_GithubAppToTeam_AB_unique" ON "_GithubAppToTeam"("A", "B");

-- CreateIndex
CREATE INDEX "_GithubAppToTeam_B_index" ON "_GithubAppToTeam"("B");

-- CreateIndex
CREATE UNIQUE INDEX "_GitlabAppToTeam_AB_unique" ON "_GitlabAppToTeam"("A", "B");

-- CreateIndex
CREATE INDEX "_GitlabAppToTeam_B_index" ON "_GitlabAppToTeam"("B");

-- CreateIndex
CREATE UNIQUE INDEX "_DestinationDockerToTeam_AB_unique" ON "_DestinationDockerToTeam"("A", "B");

-- CreateIndex
CREATE INDEX "_DestinationDockerToTeam_B_index" ON "_DestinationDockerToTeam"("B");
