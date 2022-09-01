import type { OnlyId } from "../../../../types";

export interface SaveDatabaseType extends OnlyId {
    Body: { type: string }
}
export interface DeleteDatabase extends OnlyId {
    Body: { force: string }
}