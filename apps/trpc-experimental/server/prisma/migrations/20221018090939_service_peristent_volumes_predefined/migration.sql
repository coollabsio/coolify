-- RedefineTables
PRAGMA foreign_keys=OFF;
CREATE TABLE "new_ServicePersistentStorage" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "serviceId" TEXT NOT NULL,
    "path" TEXT NOT NULL,
    "volumeName" TEXT,
    "predefined" BOOLEAN NOT NULL DEFAULT false,
    "containerId" TEXT,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "ServicePersistentStorage_serviceId_fkey" FOREIGN KEY ("serviceId") REFERENCES "Service" ("id") ON DELETE RESTRICT ON UPDATE CASCADE
);
INSERT INTO "new_ServicePersistentStorage" ("createdAt", "id", "path", "serviceId", "updatedAt") SELECT "createdAt", "id", "path", "serviceId", "updatedAt" FROM "ServicePersistentStorage";
DROP TABLE "ServicePersistentStorage";
ALTER TABLE "new_ServicePersistentStorage" RENAME TO "ServicePersistentStorage";
CREATE UNIQUE INDEX "ServicePersistentStorage_serviceId_path_key" ON "ServicePersistentStorage"("serviceId", "path");
PRAGMA foreign_key_check;
PRAGMA foreign_keys=ON;
