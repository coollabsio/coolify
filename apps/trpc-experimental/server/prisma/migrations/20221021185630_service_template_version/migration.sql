-- RedefineTables
PRAGMA foreign_keys=OFF;
CREATE TABLE "new_Service" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "name" TEXT NOT NULL,
    "fqdn" TEXT,
    "exposePort" INTEGER,
    "dualCerts" BOOLEAN NOT NULL DEFAULT false,
    "type" TEXT,
    "version" TEXT,
    "templateVersion" TEXT NOT NULL DEFAULT '0.0.0',
    "destinationDockerId" TEXT,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "Service_destinationDockerId_fkey" FOREIGN KEY ("destinationDockerId") REFERENCES "DestinationDocker" ("id") ON DELETE SET NULL ON UPDATE CASCADE
);
INSERT INTO "new_Service" ("createdAt", "destinationDockerId", "dualCerts", "exposePort", "fqdn", "id", "name", "type", "updatedAt", "version") SELECT "createdAt", "destinationDockerId", "dualCerts", "exposePort", "fqdn", "id", "name", "type", "updatedAt", "version" FROM "Service";
DROP TABLE "Service";
ALTER TABLE "new_Service" RENAME TO "Service";
PRAGMA foreign_key_check;
PRAGMA foreign_keys=ON;
