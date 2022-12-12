/*
  Warnings:

  - A unique constraint covering the columns `[name,applicationId]` on the table `Secret` will be added. If there are existing duplicate values, this will fail.

*/
-- DropIndex
DROP INDEX "Secret_name_key";

-- CreateIndex
CREATE UNIQUE INDEX "Secret_name_applicationId_key" ON "Secret"("name", "applicationId");
