/*
  Warnings:

  - Added the required column `proxyPassword` to the `Setting` table without a default value. This is not possible if the table is not empty.

*/
-- RedefineTables
PRAGMA foreign_keys=OFF;
CREATE TABLE "new_Setting" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "fqdn" TEXT,
    "isRegistrationEnabled" BOOLEAN NOT NULL DEFAULT false,
    "proxyPassword" TEXT NOT NULL,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL
);
INSERT INTO "new_Setting" ("createdAt", "fqdn", "id", "isRegistrationEnabled", "updatedAt") SELECT "createdAt", "fqdn", "id", coalesce("isRegistrationEnabled", false) AS "isRegistrationEnabled", "updatedAt" FROM "Setting";
DROP TABLE "Setting";
ALTER TABLE "new_Setting" RENAME TO "Setting";
CREATE UNIQUE INDEX "Setting_fqdn_key" ON "Setting"("fqdn");
PRAGMA foreign_key_check;
PRAGMA foreign_keys=ON;
