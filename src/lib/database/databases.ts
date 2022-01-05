import { prisma, PrismaErrorHandler } from "./common"

export async function listDatabases(teamId) {
    return await prisma.database.findMany({ where: { teams: { every: { id: teamId } } } })
}
export async function newDatabase({ name, teamId }) {
    try {
        const database = await prisma.database.create({ data: { name, teams: { connect: { id: teamId } } } })
        return { status: 201, body: { id: database.id } }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}

export async function getDatabase({ id, teamId }) {
    try {
        const body = await prisma.database.findFirst({ where: { id, teams: { every: { id: teamId } } } })
        return { ...body }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}

export async function removeDatabase({ id }) {
    try {
        console.log(id)
        await prisma.database.delete({ where: { id } })
        return { status: 200 }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}

export async function configureDatabaseType({ id, type }) {
    try {
        await prisma.database.update({
            where: { id },
            data: { type }
        })
        return { status: 200 }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}

export async function updateDatabase({ id, name }) {
    try {
        await prisma.database.update({ where: { id }, data: { name } })
        return { status: 200 }
    } catch (e) {
        throw PrismaErrorHandler(e)
    }
}