/*
  Warnings:

  - You are about to alter the column `time` on the `BuildLog` table. The data in that column could be lost. The data in that column will be cast from `Int` to `BigInt`.

*/
-- RedefineTables
PRAGMA foreign_keys=OFF;
CREATE TABLE "new_BuildLog" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "applicationId" TEXT,
    "buildId" TEXT NOT NULL,
    "line" TEXT NOT NULL,
    "time" BIGINT NOT NULL
);
INSERT INTO "new_BuildLog" ("applicationId", "buildId", "id", "line", "time") SELECT "applicationId", "buildId", "id", "line", "time" FROM "BuildLog";
DROP TABLE "BuildLog";
ALTER TABLE "new_BuildLog" RENAME TO "BuildLog";
PRAGMA foreign_key_check;
PRAGMA foreign_keys=ON;
