-- CreateTable
CREATE TABLE "MeiliSearch" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "masterKey" TEXT NOT NULL,
    "serviceId" TEXT NOT NULL,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "MeiliSearch_serviceId_fkey" FOREIGN KEY ("serviceId") REFERENCES "Service" ("id") ON DELETE RESTRICT ON UPDATE CASCADE
);

-- CreateIndex
CREATE UNIQUE INDEX "MeiliSearch_serviceId_key" ON "MeiliSearch"("serviceId");
