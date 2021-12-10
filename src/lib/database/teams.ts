import { prisma, PrismaErrorHandler } from "./common"

export async function listTeams() {
    return await prisma.team.findMany()
}
export async function newTeam({ name, userId }) {
    try {
        const team = await prisma.team.create({ data: { name, permissions: { create: { user: { connect: { id: userId } }, permission: 'admin' } }, users: { connect: { id: userId } } } })
        return { status: 201, body: { id: team.id } }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}