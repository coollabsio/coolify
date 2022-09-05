-- CreateTable
CREATE TABLE "ApplicationConnectedDatabase" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "applicationId" TEXT NOT NULL,
    "databaseId" TEXT,
    "hostedDatabaseType" TEXT,
    "hostedDatabaseHost" TEXT,
    "hostedDatabasePort" INTEGER,
    "hostedDatabaseName" TEXT,
    "hostedDatabaseUser" TEXT,
    "hostedDatabasePassword" TEXT,
    "hostedDatabaseDBName" TEXT,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "ApplicationConnectedDatabase_databaseId_fkey" FOREIGN KEY ("databaseId") REFERENCES "Database" ("id") ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT "ApplicationConnectedDatabase_applicationId_fkey" FOREIGN KEY ("applicationId") REFERENCES "Application" ("id") ON DELETE RESTRICT ON UPDATE CASCADE
);

-- CreateIndex
CREATE UNIQUE INDEX "ApplicationConnectedDatabase_applicationId_key" ON "ApplicationConnectedDatabase"("applicationId");
