import { PrismaBetterSqlite3 } from "@prisma/adapter-better-sqlite3";
import { PrismaClient } from "@/generated/prisma/client";

function createClient() {
  const adapter = new PrismaBetterSqlite3({ url: process.env.DATABASE_URL ?? "file:./prisma/ripe.db" });
  return new PrismaClient({ adapter });
}

const globalForDb = globalThis as unknown as { __ripeDb?: PrismaClient };

export const db = (globalForDb.__ripeDb ??= createClient());
