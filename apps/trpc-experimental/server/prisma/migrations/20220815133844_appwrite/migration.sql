-- CreateTable
CREATE TABLE "Appwrite" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "serviceId" TEXT NOT NULL,
    "opensslKeyV1" TEXT NOT NULL,
    "executorSecret" TEXT NOT NULL,
    "redisPassword" TEXT NOT NULL,
    "mariadbHost" TEXT,
    "mariadbPort" INTEGER NOT NULL DEFAULT 3306,
    "mariadbUser" TEXT NOT NULL,
    "mariadbPassword" TEXT NOT NULL,
    "mariadbRootUser" TEXT NOT NULL,
    "mariadbRootUserPassword" TEXT NOT NULL,
    "mariadbDatabase" TEXT NOT NULL,
    "mariadbPublicPort" INTEGER,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "Appwrite_serviceId_fkey" FOREIGN KEY ("serviceId") REFERENCES "Service" ("id") ON DELETE RESTRICT ON UPDATE CASCADE
);

-- CreateIndex
CREATE UNIQUE INDEX "Appwrite_serviceId_key" ON "Appwrite"("serviceId");
