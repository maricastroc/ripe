import { z } from "zod";

export const DELIVERABLES = ["texto", "plano", "apresentacao", "mensagem", "checklist", "outro"] as const;
export const OUTPUTS = ["estrutura", "rascunho", "checklist", "cronograma", "mensagem", "notas", "slides"] as const;

export const interpretSchema = z.object({
  title: z.string().min(1),
  deliverable: z.enum(DELIVERABLES),
  outcome: z.string().min(1),
  context: z.array(z.string()),
  assumptions: z.array(z.string()),
  questions: z.array(
    z.object({
      question: z.string().min(1),
      why: z.string(),
      essential: z.boolean(),
    }),
  ),
});

export const planSchema = z.object({
  steps: z
    .array(
      z.object({
        title: z.string().min(1),
        description: z.string(),
        output: z.enum(OUTPUTS),
      }),
    )
    .min(1),
});

export const reviewSchema = z.object({
  verdict: z.enum(["pronto", "quase", "rascunho"]),
  summary: z.string(),
  strengths: z.array(z.string()),
  issues: z.array(
    z.object({
      where: z.string(),
      problem: z.string(),
      suggestion: z.string(),
    }),
  ),
  missing: z.array(z.string()),
  nextActions: z.array(z.string()),
});

export type Interpretation = z.infer<typeof interpretSchema>;
export type Plan = z.infer<typeof planSchema>;
export type Review = z.infer<typeof reviewSchema>;

export function formatOf(schema: z.ZodType) {
  const json = z.toJSONSchema(schema) as Record<string, unknown>;
  delete json.$schema;
  return json;
}

export function parseJson<T>(schema: z.ZodType<T>, text: string): T {
  const start = text.indexOf("{");
  const end = text.lastIndexOf("}");
  if (start < 0 || end <= start) throw new Error("O modelo não devolveu JSON");
  let raw: unknown;
  try {
    raw = JSON.parse(text.slice(start, end + 1));
  } catch {
    throw new Error("O modelo devolveu JSON incompleto");
  }
  const parsed = schema.safeParse(raw);
  if (!parsed.success) throw new Error(`Resposta fora do formato: ${parsed.error.issues[0]?.message ?? "inválida"}`);
  return parsed.data;
}
