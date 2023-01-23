/*
  Warnings:

  - A unique constraint covering the columns `[serviceId,containerId,path]` on the table `ServicePersistentStorage` will be added. If there are existing duplicate values, this will fail.

*/
-- DropIndex
DROP INDEX "ServicePersistentStorage_serviceId_path_key";

-- CreateIndex
CREATE UNIQUE INDEX "ServicePersistentStorage_serviceId_containerId_path_key" ON "ServicePersistentStorage"("serviceId", "containerId", "path");
