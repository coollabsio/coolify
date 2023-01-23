-- CreateTable
CREATE TABLE "Weblate" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "adminPassword" TEXT NOT NULL,
    "postgresqlHost" TEXT NOT NULL,
    "postgresqlPort" INTEGER NOT NULL,
    "postgresqlUser" TEXT NOT NULL,
    "postgresqlPassword" TEXT NOT NULL,
    "postgresqlDatabase" TEXT NOT NULL,
    "postgresqlPublicPort" INTEGER,
    "serviceId" TEXT NOT NULL,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "Weblate_serviceId_fkey" FOREIGN KEY ("serviceId") REFERENCES "Service" ("id") ON DELETE RESTRICT ON UPDATE CASCADE
);

-- CreateIndex
CREATE UNIQUE INDEX "Weblate_serviceId_key" ON "Weblate"("serviceId");
