-- AlterTable
ALTER TABLE "Build" ADD COLUMN "previewApplicationId" TEXT;

-- CreateTable
CREATE TABLE "PreviewApplication" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "pullmergeRequestId" TEXT NOT NULL,
    "sourceBranch" TEXT NOT NULL,
    "isRandomDomain" BOOLEAN NOT NULL DEFAULT false,
    "customDomain" TEXT,
    "applicationId" TEXT NOT NULL,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "PreviewApplication_applicationId_fkey" FOREIGN KEY ("applicationId") REFERENCES "Application" ("id") ON DELETE RESTRICT ON UPDATE CASCADE
);

-- CreateIndex
CREATE UNIQUE INDEX "PreviewApplication_applicationId_key" ON "PreviewApplication"("applicationId");
