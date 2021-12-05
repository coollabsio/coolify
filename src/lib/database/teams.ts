import { prisma, PrismaErrorHandler } from "./common"

export async function listTeams() {
    return await prisma.team.findMany()
}