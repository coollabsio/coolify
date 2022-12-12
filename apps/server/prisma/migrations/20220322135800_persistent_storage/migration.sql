-- CreateTable
CREATE TABLE "ApplicationPersistentStorage" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "applicationId" TEXT NOT NULL,
    "path" TEXT NOT NULL,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "ApplicationPersistentStorage_applicationId_fkey" FOREIGN KEY ("applicationId") REFERENCES "Application" ("id") ON DELETE RESTRICT ON UPDATE CASCADE
);

-- CreateIndex
CREATE UNIQUE INDEX "ApplicationPersistentStorage_applicationId_key" ON "ApplicationPersistentStorage"("applicationId");

-- CreateIndex
CREATE UNIQUE INDEX "ApplicationPersistentStorage_path_key" ON "ApplicationPersistentStorage"("path");

-- CreateIndex
CREATE UNIQUE INDEX "ApplicationPersistentStorage_applicationId_path_key" ON "ApplicationPersistentStorage"("applicationId", "path");
