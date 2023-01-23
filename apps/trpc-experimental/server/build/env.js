"use strict";
const dotenv = require("dotenv");
dotenv.config();
const { z } = require("zod");
const envSchema = z.object({
  CODESANDBOX_HOST: z.string().optional(),
  NODE_ENV: z.enum(["development", "test", "production"]),
  COOLIFY_DATABASE_URL: z.string(),
  COOLIFY_SECRET_KEY: z.string().length(32),
  COOLIFY_WHITE_LABELED: z.string().optional(),
  COOLIFY_WHITE_LABELED_ICON: z.string().optional()
});
const env = envSchema.safeParse(process.env);
if (!env.success) {
  console.error("\u274C Invalid environment variables:", JSON.stringify(env.error.format(), null, 4));
  process.exit(1);
}
module.exports.env = env.data;
