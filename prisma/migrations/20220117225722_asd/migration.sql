-- CreateTable
CREATE TABLE "Service" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "name" TEXT NOT NULL,
    "domain" TEXT,
    "type" TEXT,
    "version" TEXT,
    "databaseId" TEXT,
    "destinationDockerId" TEXT,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "Service_destinationDockerId_fkey" FOREIGN KEY ("destinationDockerId") REFERENCES "DestinationDocker" ("id") ON DELETE SET NULL ON UPDATE CASCADE
);

-- CreateTable
CREATE TABLE "PlausibleAnalytics" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "email" TEXT,
    "username" TEXT,
    "password" TEXT NOT NULL,
    "postgresqlUser" TEXT NOT NULL,
    "postgresqlPassword" TEXT NOT NULL,
    "postgresqlDatabase" TEXT NOT NULL,
    "postgresqlPublicPort" INTEGER,
    "secretKeyBase" TEXT,
    "serviceId" TEXT NOT NULL,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "PlausibleAnalytics_serviceId_fkey" FOREIGN KEY ("serviceId") REFERENCES "Service" ("id") ON DELETE RESTRICT ON UPDATE CASCADE
);

-- CreateTable
CREATE TABLE "Minio" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "rootUser" TEXT NOT NULL,
    "rootUserPassword" TEXT NOT NULL,
    "publicPort" INTEGER,
    "serviceId" TEXT NOT NULL,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "Minio_serviceId_fkey" FOREIGN KEY ("serviceId") REFERENCES "Service" ("id") ON DELETE RESTRICT ON UPDATE CASCADE
);

-- CreateTable
CREATE TABLE "_ServiceToTeam" (
    "A" TEXT NOT NULL,
    "B" TEXT NOT NULL,
    FOREIGN KEY ("A") REFERENCES "Service" ("id") ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY ("B") REFERENCES "Team" ("id") ON DELETE CASCADE ON UPDATE CASCADE
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
    "serviceId" TEXT,
    CONSTRAINT "Database_destinationDockerId_fkey" FOREIGN KEY ("destinationDockerId") REFERENCES "DestinationDocker" ("id") ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT "Database_serviceId_fkey" FOREIGN KEY ("serviceId") REFERENCES "Service" ("id") ON DELETE SET NULL ON UPDATE CASCADE
);
INSERT INTO "new_Database" ("createdAt", "dbUser", "dbUserPassword", "defaultDatabase", "destinationDockerId", "id", "name", "publicPort", "rootUser", "rootUserPassword", "type", "updatedAt", "version") SELECT "createdAt", "dbUser", "dbUserPassword", "defaultDatabase", "destinationDockerId", "id", "name", "publicPort", "rootUser", "rootUserPassword", "type", "updatedAt", "version" FROM "Database";
DROP TABLE "Database";
ALTER TABLE "new_Database" RENAME TO "Database";
CREATE UNIQUE INDEX "Database_name_key" ON "Database"("name");
CREATE TABLE "new_Team" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "name" TEXT,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    "databaseId" TEXT,
    "serviceId" TEXT,
    FOREIGN KEY ("databaseId") REFERENCES "Database" ("id") ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY ("serviceId") REFERENCES "Service" ("id") ON DELETE SET NULL ON UPDATE CASCADE
);
INSERT INTO "new_Team" ("createdAt", "databaseId", "id", "name", "updatedAt") SELECT "createdAt", "databaseId", "id", "name", "updatedAt" FROM "Team";
DROP TABLE "Team";
ALTER TABLE "new_Team" RENAME TO "Team";
PRAGMA foreign_key_check;
PRAGMA foreign_keys=ON;

-- CreateIndex
CREATE UNIQUE INDEX "Service_databaseId_key" ON "Service"("databaseId");

-- CreateIndex
CREATE UNIQUE INDEX "PlausibleAnalytics_serviceId_key" ON "PlausibleAnalytics"("serviceId");

-- CreateIndex
CREATE UNIQUE INDEX "Minio_serviceId_key" ON "Minio"("serviceId");

-- CreateIndex
CREATE UNIQUE INDEX "_ServiceToTeam_AB_unique" ON "_ServiceToTeam"("A", "B");

-- CreateIndex
CREATE INDEX "_ServiceToTeam_B_index" ON "_ServiceToTeam"("B");
