-- CreateTable
CREATE TABLE "Hasura" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "serviceId" TEXT NOT NULL,
    "postgresqlUser" TEXT NOT NULL,
    "postgresqlPassword" TEXT NOT NULL,
    "postgresqlDatabase" TEXT NOT NULL,
    "postgresqlPublicPort" INTEGER,
    "graphQLAdminPassword" TEXT NOT NULL,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "Hasura_serviceId_fkey" FOREIGN KEY ("serviceId") REFERENCES "Service" ("id") ON DELETE RESTRICT ON UPDATE CASCADE
);

-- CreateIndex
CREATE UNIQUE INDEX "Hasura_serviceId_key" ON "Hasura"("serviceId");
