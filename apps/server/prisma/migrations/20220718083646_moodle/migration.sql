-- CreateTable
CREATE TABLE "Moodle" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "serviceId" TEXT NOT NULL,
    "defaultUsername" TEXT NOT NULL,
    "defaultPassword" TEXT NOT NULL,
    "defaultEmail" TEXT NOT NULL,
    "mariadbUser" TEXT NOT NULL,
    "mariadbPassword" TEXT NOT NULL,
    "mariadbRootUser" TEXT NOT NULL,
    "mariadbRootUserPassword" TEXT NOT NULL,
    "mariadbDatabase" TEXT NOT NULL,
    "mariadbPublicPort" INTEGER,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "Moodle_serviceId_fkey" FOREIGN KEY ("serviceId") REFERENCES "Service" ("id") ON DELETE RESTRICT ON UPDATE CASCADE
);

-- CreateIndex
CREATE UNIQUE INDEX "Moodle_serviceId_key" ON "Moodle"("serviceId");
