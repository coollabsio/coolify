/*
  Warnings:

  - You are about to drop the column `serviceId` on the `Database` table. All the data in the column will be lost.
  - You are about to drop the column `isSwarm` on the `DestinationDocker` table. All the data in the column will be lost.
  - You are about to drop the column `databaseId` on the `Service` table. All the data in the column will be lost.

*/
-- CreateTable
CREATE TABLE "Vscodeserver" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "password" TEXT NOT NULL,
    "serviceId" TEXT NOT NULL,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "Vscodeserver_serviceId_fkey" FOREIGN KEY ("serviceId") REFERENCES "Service" ("id") ON DELETE RESTRICT ON UPDATE CASCADE
);

-- CreateTable
CREATE TABLE "Wordpress" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "extraConfig" TEXT,
    "tablePrefix" TEXT,
    "mysqlUser" TEXT NOT NULL,
    "mysqlPassword" TEXT NOT NULL,
    "mysqlRootUser" TEXT NOT NULL,
    "mysqlRootUserPassword" TEXT NOT NULL,
    "mysqlDatabase" TEXT,
    "mysqlPublicPort" INTEGER,
    "serviceId" TEXT NOT NULL,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "Wordpress_serviceId_fkey" FOREIGN KEY ("serviceId") REFERENCES "Service" ("id") ON DELETE RESTRICT ON UPDATE CASCADE
);

-- RedefineTables
PRAGMA foreign_keys=OFF;
CREATE TABLE "new_Database" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "name" TEXT NOT NULL,
    "publicPort" INTEGER,
    "defaultDatabase" TEXT,
    "type" TEXT,
    "version" TEXT,
    "dbUser" TEXT,
    "dbUserPassword" TEXT,
    "rootUser" TEXT,
    "rootUserPassword" TEXT,
    "destinationDockerId" TEXT,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "Database_destinationDockerId_fkey" FOREIGN KEY ("destinationDockerId") REFERENCES "DestinationDocker" ("id") ON DELETE SET NULL ON UPDATE CASCADE
);
INSERT INTO "new_Database" ("createdAt", "dbUser", "dbUserPassword", "defaultDatabase", "destinationDockerId", "id", "name", "publicPort", "rootUser", "rootUserPassword", "type", "updatedAt", "version") SELECT "createdAt", "dbUser", "dbUserPassword", "defaultDatabase", "destinationDockerId", "id", "name", "publicPort", "rootUser", "rootUserPassword", "type", "updatedAt", "version" FROM "Database";
DROP TABLE "Database";
ALTER TABLE "new_Database" RENAME TO "Database";
CREATE TABLE "new_DestinationDocker" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "network" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "engine" TEXT NOT NULL,
    "remoteEngine" BOOLEAN NOT NULL DEFAULT false,
    "isCoolifyProxyUsed" BOOLEAN DEFAULT false,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL
);
INSERT INTO "new_DestinationDocker" ("createdAt", "engine", "id", "isCoolifyProxyUsed", "name", "network", "updatedAt") SELECT "createdAt", "engine", "id", "isCoolifyProxyUsed", "name", "network", "updatedAt" FROM "DestinationDocker";
DROP TABLE "DestinationDocker";
ALTER TABLE "new_DestinationDocker" RENAME TO "DestinationDocker";
CREATE UNIQUE INDEX "DestinationDocker_network_key" ON "DestinationDocker"("network");
CREATE TABLE "new_Service" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "name" TEXT NOT NULL,
    "fqdn" TEXT,
    "type" TEXT,
    "version" TEXT,
    "destinationDockerId" TEXT,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "Service_destinationDockerId_fkey" FOREIGN KEY ("destinationDockerId") REFERENCES "DestinationDocker" ("id") ON DELETE SET NULL ON UPDATE CASCADE
);
INSERT INTO "new_Service" ("createdAt", "destinationDockerId", "fqdn", "id", "name", "type", "updatedAt", "version") SELECT "createdAt", "destinationDockerId", "fqdn", "id", "name", "type", "updatedAt", "version" FROM "Service";
DROP TABLE "Service";
ALTER TABLE "new_Service" RENAME TO "Service";
PRAGMA foreign_key_check;
PRAGMA foreign_keys=ON;

-- CreateIndex
CREATE UNIQUE INDEX "Vscodeserver_serviceId_key" ON "Vscodeserver"("serviceId");

-- CreateIndex
CREATE UNIQUE INDEX "Wordpress_serviceId_key" ON "Wordpress"("serviceId");
