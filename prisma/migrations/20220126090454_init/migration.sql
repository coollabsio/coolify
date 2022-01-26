/*
  Warnings:

  - You are about to drop the column `oldFqdn` on the `Application` table. All the data in the column will be lost.
  - You are about to drop the column `name` on the `Setting` table. All the data in the column will be lost.
  - You are about to drop the column `value` on the `Setting` table. All the data in the column will be lost.

*/
-- RedefineTables
PRAGMA foreign_keys=OFF;
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
    "installCommand" TEXT,
    "buildCommand" TEXT,
    "startCommand" TEXT,
    "baseDirectory" TEXT,
    "publishDirectory" TEXT,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    "destinationDockerId" TEXT,
    "gitSourceId" TEXT,
    CONSTRAINT "Application_destinationDockerId_fkey" FOREIGN KEY ("destinationDockerId") REFERENCES "DestinationDocker" ("id") ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT "Application_gitSourceId_fkey" FOREIGN KEY ("gitSourceId") REFERENCES "GitSource" ("id") ON DELETE SET NULL ON UPDATE CASCADE
);
INSERT INTO "new_Application" ("baseDirectory", "branch", "buildCommand", "buildPack", "configHash", "createdAt", "destinationDockerId", "fqdn", "gitSourceId", "id", "installCommand", "name", "port", "projectId", "publishDirectory", "repository", "startCommand", "updatedAt") SELECT "baseDirectory", "branch", "buildCommand", "buildPack", "configHash", "createdAt", "destinationDockerId", "fqdn", "gitSourceId", "id", "installCommand", "name", "port", "projectId", "publishDirectory", "repository", "startCommand", "updatedAt" FROM "Application";
DROP TABLE "Application";
ALTER TABLE "new_Application" RENAME TO "Application";
CREATE UNIQUE INDEX "Application_fqdn_key" ON "Application"("fqdn");
CREATE TABLE "new_Setting" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "fqdn" TEXT,
    "isRegistrationEnabled" BOOLEAN DEFAULT false,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL
);
INSERT INTO "new_Setting" ("createdAt", "id", "updatedAt") SELECT "createdAt", "id", "updatedAt" FROM "Setting";
DROP TABLE "Setting";
ALTER TABLE "new_Setting" RENAME TO "Setting";
CREATE UNIQUE INDEX "Setting_fqdn_key" ON "Setting"("fqdn");
PRAGMA foreign_key_check;
PRAGMA foreign_keys=ON;
