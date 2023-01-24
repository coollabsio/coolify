-- CreateTable
CREATE TABLE "Searxng" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "secretKey" TEXT NOT NULL,
    "redisPassword" TEXT NOT NULL,
    "serviceId" TEXT NOT NULL,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "Searxng_serviceId_fkey" FOREIGN KEY ("serviceId") REFERENCES "Service" ("id") ON DELETE RESTRICT ON UPDATE CASCADE
);

-- CreateIndex
CREATE UNIQUE INDEX "Searxng_serviceId_key" ON "Searxng"("serviceId");
