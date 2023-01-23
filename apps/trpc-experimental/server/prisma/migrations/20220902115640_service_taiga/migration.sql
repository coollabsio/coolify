-- CreateTable
CREATE TABLE "Taiga" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "secretKey" TEXT NOT NULL,
    "erlangSecret" TEXT NOT NULL,
    "djangoAdminPassword" TEXT NOT NULL,
    "djangoAdminUser" TEXT NOT NULL,
    "rabbitMQUser" TEXT NOT NULL,
    "rabbitMQPassword" TEXT NOT NULL,
    "postgresqlHost" TEXT NOT NULL,
    "postgresqlPort" INTEGER NOT NULL,
    "postgresqlUser" TEXT NOT NULL,
    "postgresqlPassword" TEXT NOT NULL,
    "postgresqlDatabase" TEXT NOT NULL,
    "postgresqlPublicPort" INTEGER,
    "serviceId" TEXT NOT NULL,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "Taiga_serviceId_fkey" FOREIGN KEY ("serviceId") REFERENCES "Service" ("id") ON DELETE RESTRICT ON UPDATE CASCADE
);

-- CreateIndex
CREATE UNIQUE INDEX "Taiga_serviceId_key" ON "Taiga"("serviceId");
