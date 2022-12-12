/*
  Warnings:

  - Added the required column `variableName` to the `ServiceSetting` table without a default value. This is not possible if the table is not empty.

*/
-- RedefineTables
PRAGMA foreign_keys=OFF;
CREATE TABLE "new_ServiceSetting" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "serviceId" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "value" TEXT NOT NULL,
    "variableName" TEXT NOT NULL,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "ServiceSetting_serviceId_fkey" FOREIGN KEY ("serviceId") REFERENCES "Service" ("id") ON DELETE RESTRICT ON UPDATE CASCADE
);
INSERT INTO "new_ServiceSetting" ("createdAt", "id", "name", "serviceId", "updatedAt", "value") SELECT "createdAt", "id", "name", "serviceId", "updatedAt", "value" FROM "ServiceSetting";
DROP TABLE "ServiceSetting";
ALTER TABLE "new_ServiceSetting" RENAME TO "ServiceSetting";
CREATE UNIQUE INDEX "ServiceSetting_serviceId_name_key" ON "ServiceSetting"("serviceId", "name");
PRAGMA foreign_key_check;
PRAGMA foreign_keys=ON;
