import { PrismaErrorHandler } from ".";
import { prisma } from "./common";

export async function listSettings() {
    try {
        return await prisma.setting.findMany({})
    } catch (err) {
        throw PrismaErrorHandler(err)
    }
}
export async function getSetting({ name }) {
    try {
        return await prisma.setting.findFirst({ where: { name }, rejectOnNotFound: false })
    } catch (err) {
        throw PrismaErrorHandler(err)
    }
}