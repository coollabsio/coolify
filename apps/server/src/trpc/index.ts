import { router } from './trpc';
import type { Permission } from '@prisma/client';

import {
	settingsRouter,
	authRouter,
	dashboardRouter,
	applicationsRouter,
	servicesRouter,
	databasesRouter
} from './routers';

export const appRouter = router({
	settings: settingsRouter,
	auth: authRouter,
	dashboard: dashboardRouter,
	applications: applicationsRouter,
	services: servicesRouter,
	databases: databasesRouter
});

export type AppRouter = typeof appRouter;
export type PrismaPermission = Permission;
