-- CreateTable
CREATE TABLE "Certificate" (
    "id" TEXT NOT NULL PRIMARY KEY,
    "key" TEXT NOT NULL,
    "cert" TEXT NOT NULL,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL
);

-- CreateTable
CREATE TABLE "_CertificateToTeam" (
    "A" TEXT NOT NULL,
    "B" TEXT NOT NULL,
    CONSTRAINT "_CertificateToTeam_A_fkey" FOREIGN KEY ("A") REFERENCES "Certificate" ("id") ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT "_CertificateToTeam_B_fkey" FOREIGN KEY ("B") REFERENCES "Team" ("id") ON DELETE CASCADE ON UPDATE CASCADE
);

-- CreateIndex
CREATE UNIQUE INDEX "_CertificateToTeam_AB_unique" ON "_CertificateToTeam"("A", "B");

-- CreateIndex
CREATE INDEX "_CertificateToTeam_B_index" ON "_CertificateToTeam"("B");
