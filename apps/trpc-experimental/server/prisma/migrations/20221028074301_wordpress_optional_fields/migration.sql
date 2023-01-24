-- RedefineTables
PRAGMA foreign_keys=OFF;
CREATE TABLE "new_Wordpress" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "extraConfig" TEXT,
    "tablePrefix" TEXT,
    "ownMysql" BOOLEAN NOT NULL DEFAULT false,
    "mysqlHost" TEXT,
    "mysqlPort" INTEGER,
    "mysqlUser" TEXT,
    "mysqlPassword" TEXT,
    "mysqlRootUser" TEXT,
    "mysqlRootUserPassword" TEXT,
    "mysqlDatabase" TEXT,
    "mysqlPublicPort" INTEGER,
    "ftpEnabled" BOOLEAN NOT NULL DEFAULT false,
    "ftpUser" TEXT,
    "ftpPassword" TEXT,
    "ftpPublicPort" INTEGER,
    "ftpHostKey" TEXT,
    "ftpHostKeyPrivate" TEXT,
    "serviceId" TEXT NOT NULL,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "Wordpress_serviceId_fkey" FOREIGN KEY ("serviceId") REFERENCES "Service" ("id") ON DELETE RESTRICT ON UPDATE CASCADE
);
INSERT INTO "new_Wordpress" ("createdAt", "extraConfig", "ftpEnabled", "ftpHostKey", "ftpHostKeyPrivate", "ftpPassword", "ftpPublicPort", "ftpUser", "id", "mysqlDatabase", "mysqlHost", "mysqlPassword", "mysqlPort", "mysqlPublicPort", "mysqlRootUser", "mysqlRootUserPassword", "mysqlUser", "ownMysql", "serviceId", "tablePrefix", "updatedAt") SELECT "createdAt", "extraConfig", "ftpEnabled", "ftpHostKey", "ftpHostKeyPrivate", "ftpPassword", "ftpPublicPort", "ftpUser", "id", "mysqlDatabase", "mysqlHost", "mysqlPassword", "mysqlPort", "mysqlPublicPort", "mysqlRootUser", "mysqlRootUserPassword", "mysqlUser", "ownMysql", "serviceId", "tablePrefix", "updatedAt" FROM "Wordpress";
DROP TABLE "Wordpress";
ALTER TABLE "new_Wordpress" RENAME TO "Wordpress";
CREATE UNIQUE INDEX "Wordpress_serviceId_key" ON "Wordpress"("serviceId");
PRAGMA foreign_key_check;
PRAGMA foreign_keys=ON;
