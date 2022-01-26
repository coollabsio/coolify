import { prisma, PrismaErrorHandler } from "./common"

export async function listTeams() {
    return await prisma.team.findMany()
}
export async function newTeam({ name, userId }) {
    return await prisma.team.create({ data: { name, permissions: { create: { user: { connect: { id: userId } }, permission: 'owner' } }, users: { connect: { id: userId } } } })
}