/*
  Warnings:

  - You are about to drop the column `isSystemWide` on the `DockerRegistry` table. All the data in the column will be lost.

*/
-- RedefineTables
PRAGMA foreign_keys=OFF;
CREATE TABLE "new_DockerRegistry" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "name" TEXT NOT NULL,
    "url" TEXT NOT NULL,
    "username" TEXT,
    "password" TEXT,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    "teamId" TEXT,
    CONSTRAINT "DockerRegistry_teamId_fkey" FOREIGN KEY ("teamId") REFERENCES "Team" ("id") ON DELETE SET NULL ON UPDATE CASCADE
);
INSERT INTO "new_DockerRegistry" ("createdAt", "id", "name", "password", "teamId", "updatedAt", "url", "username") SELECT "createdAt", "id", "name", "password", "teamId", "updatedAt", "url", "username" FROM "DockerRegistry";
DROP TABLE "DockerRegistry";
ALTER TABLE "new_DockerRegistry" RENAME TO "DockerRegistry";
CREATE TABLE "new_Application" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "name" TEXT NOT NULL,
    "fqdn" TEXT,
    "repository" TEXT,
    "configHash" TEXT,
    "branch" TEXT,
    "buildPack" TEXT,
    "projectId" INTEGER,
    "port" INTEGER,
    "exposePort" INTEGER,
    "installCommand" TEXT,
    "buildCommand" TEXT,
    "startCommand" TEXT,
    "baseDirectory" TEXT,
    "publishDirectory" TEXT,
    "deploymentType" TEXT,
    "phpModules" TEXT,
    "pythonWSGI" TEXT,
    "pythonModule" TEXT,
    "pythonVariable" TEXT,
    "dockerFileLocation" TEXT,
    "denoMainFile" TEXT,
    "denoOptions" TEXT,
    "dockerComposeFile" TEXT,
    "dockerComposeFileLocation" TEXT,
    "dockerComposeConfiguration" TEXT,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    "destinationDockerId" TEXT,
    "gitSourceId" TEXT,
    "gitCommitHash" TEXT,
    "baseImage" TEXT,
    "baseBuildImage" TEXT,
    "dockerRegistryId" TEXT,
    CONSTRAINT "Application_gitSourceId_fkey" FOREIGN KEY ("gitSourceId") REFERENCES "GitSource" ("id") ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT "Application_destinationDockerId_fkey" FOREIGN KEY ("destinationDockerId") REFERENCES "DestinationDocker" ("id") ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT "Application_dockerRegistryId_fkey" FOREIGN KEY ("dockerRegistryId") REFERENCES "DockerRegistry" ("id") ON DELETE SET NULL ON UPDATE CASCADE
);
INSERT INTO "new_Application" ("baseBuildImage", "baseDirectory", "baseImage", "branch", "buildCommand", "buildPack", "configHash", "createdAt", "denoMainFile", "denoOptions", "deploymentType", "destinationDockerId", "dockerComposeConfiguration", "dockerComposeFile", "dockerComposeFileLocation", "dockerFileLocation", "dockerRegistryId", "exposePort", "fqdn", "gitCommitHash", "gitSourceId", "id", "installCommand", "name", "phpModules", "port", "projectId", "publishDirectory", "pythonModule", "pythonVariable", "pythonWSGI", "repository", "startCommand", "updatedAt") SELECT "baseBuildImage", "baseDirectory", "baseImage", "branch", "buildCommand", "buildPack", "configHash", "createdAt", "denoMainFile", "denoOptions", "deploymentType", "destinationDockerId", "dockerComposeConfiguration", "dockerComposeFile", "dockerComposeFileLocation", "dockerFileLocation", "dockerRegistryId", "exposePort", "fqdn", "gitCommitHash", "gitSourceId", "id", "installCommand", "name", "phpModules", "port", "projectId", "publishDirectory", "pythonModule", "pythonVariable", "pythonWSGI", "repository", "startCommand", "updatedAt" FROM "Application";
DROP TABLE "Application";
ALTER TABLE "new_Application" RENAME TO "Application";
PRAGMA foreign_key_check;
PRAGMA foreign_keys=ON;
