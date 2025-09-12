import { z } from 'zod';

export const storeUserSchema = z.object({
    username: z
        .string()
        .min(1, 'Username is required (Zod Error)')
        .max(3, 'The username field must not be greater than 3 characters (Zod Error)'),
    // .regex(/^[a-zA-Z]+$/, 'The username field must only contain letters (Zod Error)'), // This is commented out to check if server side validation is working.

    notifications_enabled: z
        .boolean()
});
