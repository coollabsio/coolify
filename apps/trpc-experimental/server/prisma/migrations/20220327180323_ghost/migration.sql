-- CreateTable
CREATE TABLE "Ghost" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "defaultEmail" TEXT NOT NULL,
    "defaultPassword" TEXT NOT NULL,
    "mariadbUser" TEXT NOT NULL,
    "mariadbPassword" TEXT NOT NULL,
    "mariadbRootUser" TEXT NOT NULL,
    "mariadbRootUserPassword" TEXT NOT NULL,
    "mariadbDatabase" TEXT,
    "mariadbPublicPort" INTEGER,
    "serviceId" TEXT NOT NULL,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "Ghost_serviceId_fkey" FOREIGN KEY ("serviceId") REFERENCES "Service" ("id") ON DELETE RESTRICT ON UPDATE CASCADE
);

-- CreateIndex
CREATE UNIQUE INDEX "Ghost_serviceId_key" ON "Ghost"("serviceId");
