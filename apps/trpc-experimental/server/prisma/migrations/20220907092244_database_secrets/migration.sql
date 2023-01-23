-- CreateTable
CREATE TABLE "DatabaseSecret" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "name" TEXT NOT NULL,
    "value" TEXT NOT NULL,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    "databaseId" TEXT NOT NULL,
    CONSTRAINT "DatabaseSecret_databaseId_fkey" FOREIGN KEY ("databaseId") REFERENCES "Database" ("id") ON DELETE RESTRICT ON UPDATE CASCADE
);

-- CreateIndex
CREATE UNIQUE INDEX "DatabaseSecret_name_databaseId_key" ON "DatabaseSecret"("name", "databaseId");
