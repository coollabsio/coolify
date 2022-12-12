-- CreateTable
CREATE TABLE "GlitchTip" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "postgresqlUser" TEXT NOT NULL,
    "postgresqlPassword" TEXT NOT NULL,
    "postgresqlDatabase" TEXT NOT NULL,
    "postgresqlPublicPort" INTEGER,
    "secretKeyBase" TEXT,
    "defaultEmail" TEXT NOT NULL,
    "defaultUsername" TEXT NOT NULL,
    "defaultPassword" TEXT NOT NULL,
    "defaultEmailFrom" TEXT NOT NULL DEFAULT 'glitchtip@domain.tdl',
    "emailSmtpHost" TEXT DEFAULT 'domain.tdl',
    "emailSmtpPort" INTEGER DEFAULT 25,
    "emailSmtpUser" TEXT,
    "emailSmtpPassword" TEXT,
    "emailSmtpUseTls" BOOLEAN DEFAULT false,
    "emailSmtpUseSsl" BOOLEAN DEFAULT false,
    "emailBackend" TEXT,
    "mailgunApiKey" TEXT,
    "sendgridApiKey" TEXT,
    "enableOpenUserRegistration" BOOLEAN NOT NULL DEFAULT true,
    "serviceId" TEXT NOT NULL,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "GlitchTip_serviceId_fkey" FOREIGN KEY ("serviceId") REFERENCES "Service" ("id") ON DELETE RESTRICT ON UPDATE CASCADE
);

-- CreateIndex
CREATE UNIQUE INDEX "GlitchTip_serviceId_key" ON "GlitchTip"("serviceId");
