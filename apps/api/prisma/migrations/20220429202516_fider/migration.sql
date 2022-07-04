-- CreateTable
CREATE TABLE "Fider" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "serviceId" TEXT NOT NULL,
    "postgresqlUser" TEXT NOT NULL,
    "postgresqlPassword" TEXT NOT NULL,
    "postgresqlDatabase" TEXT NOT NULL,
    "postgresqlPublicPort" INTEGER,
    "jwtSecret" TEXT NOT NULL,
    "emailNoreply" TEXT,
    "emailMailgunApiKey" TEXT,
    "emailMailgunDomain" TEXT,
    "emailMailgunRegion" TEXT,
    "emailSmtpHost" TEXT,
    "emailSmtpPort" INTEGER,
    "emailSmtpUser" TEXT,
    "emailSmtpPassword" TEXT,
    "emailSmtpEnableStartTls" BOOLEAN NOT NULL DEFAULT false,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "Fider_serviceId_fkey" FOREIGN KEY ("serviceId") REFERENCES "Service" ("id") ON DELETE RESTRICT ON UPDATE CASCADE
);

-- CreateIndex
CREATE UNIQUE INDEX "Fider_serviceId_key" ON "Fider"("serviceId");
