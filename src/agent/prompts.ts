import type { ChatMessage } from "./ollama";
import { LIMITS } from "@/lib/limits";

const SYSTEM = `Você é o agente do Ripe. Você trabalha devagar e com cuidado em tarefas que uma pessoa decidiu adiar, para que ela encontre algo concreto quando voltar.

Princípios:
- Escreva sempre no mesmo idioma em que o pedido da pessoa foi escrito.
- Nunca invente fatos específicos que a pessoa não forneceu: nomes, datas, valores, números, lugares, decisões, citações ou dados de terceiros. Quando algo assim for necessário, escreva no lugar o marcador [[a definir: descrição curta do que falta]].
- Entregue material utilizável, não conselhos genéricos sobre como fazer. Escreva como quem entrega o trabalho.
- Seu trabalho se limita a escrita e planejamento. Nunca diga que enviou, reservou, pesquisou na internet ou executou algo.
- Seja direto. Sem preâmbulos, sem despedidas, sem mencionar que é uma IA.`;

export type Brief = {
  prompt: string;
  title: string;
  outcome: string | null;
  deliverable: string | null;
  context: string[];
  assumptions: string[];
  questions: { text: string; blocking: boolean; status: string; answer: string | null }[];
};

export type PlanLine = { position: number; title: string; status: string };
export type PriorWork = { title: string; content: string };

const OUTPUT_GUIDE: Record<string, string> = {
  estrutura: "um esqueleto hierárquico com as seções e o que cada uma deve conter",
  rascunho: "texto corrido, pronto para a pessoa editar",
  checklist: "lista de verificação com caixas (- [ ]) agrupadas por tema",
  cronograma: "plano em fases ou dias, em lista ou tabela; datas desconhecidas viram marcadores",
  mensagem: "a mensagem completa, com linha de assunto se for e-mail",
  notas: "notas organizadas em tópicos curtos",
  slides: "roteiro slide a slide: título de cada slide, 2 a 4 pontos e uma nota curta para quem apresenta",
};

function quote(text: string, max: number = LIMITS.maxPromptChars) {
  return `"""\n${text.slice(0, max)}\n"""`;
}

function list(items: string[], empty = "- (nada)") {
  return items.length ? items.map((i) => `- ${i}`).join("\n") : empty;
}

function briefBlock(brief: Brief) {
  const answered = brief.questions.filter((q) => q.status === "answered" && q.answer);
  const open = brief.questions.filter((q) => q.status !== "answered");
  return [
    `Tarefa: ${brief.title}`,
    `Pedido original da pessoa:\n${quote(brief.prompt)}`,
    brief.outcome ? `Resultado esperado: ${brief.outcome}` : null,
    `Fatos fornecidos pela pessoa:\n${list(brief.context)}`,
    answered.length
      ? `Respostas da pessoa:\n${answered.map((q) => `- ${q.text}\n  Resposta: ${q.answer}`).join("\n")}`
      : null,
    open.length ? `Ainda sem resposta (use [[a definir: ...]] onde precisar):\n${list(open.map((q) => q.text))}` : null,
    brief.assumptions.length ? `Suposições adotadas:\n${list(brief.assumptions)}` : null,
  ]
    .filter(Boolean)
    .join("\n\n");
}

function clip(text: string, max: number) {
  if (text.length <= max) return text;
  return `${text.slice(0, max)}\n[...]`;
}

export function interpretMessages(prompt: string): ChatMessage[] {
  return [
    { role: "system", content: SYSTEM },
    {
      role: "user",
      content: `Pedido da pessoa:
${quote(prompt)}

Interprete o pedido e responda em JSON com:
- title: título curto (até 6 palavras) que nomeie a tarefa.
- deliverable: tipo principal do resultado. Um de: "texto" (artigo, proposta, ensaio, documento), "plano" (viagem, projeto, evento, estudo, rotina), "apresentacao", "mensagem" (e-mail, carta, resposta), "checklist", "outro".
- outcome: uma ou duas frases descrevendo o resultado concreto esperado quando a tarefa estiver pronta.
- context: fatos que a própria pessoa forneceu no pedido. Lista vazia se não houver. Não acrescente nada.
- assumptions: até 3 suposições seguras e genéricas que você vai adotar para avançar sem perguntar.
- questions: até ${LIMITS.maxQuestions} informações que faltam e que só a pessoa pode dar. Para cada uma: question (pergunta direta e curta), why (por que importa, em poucas palavras), essential (true somente se, sem ela, a versão final ficaria errada ou inútil mesmo com marcadores).

Nomes, datas e números simples quase nunca são essenciais: viram marcadores [[a definir: ...]] no texto. Em geral há no máximo uma pergunta essencial, e muitas vezes nenhuma. Se o pedido já for claro, devolva poucas ou nenhuma pergunta.`,
    },
  ];
}

export function planMessages(brief: Brief): ChatMessage[] {
  return [
    { role: "system", content: SYSTEM },
    {
      role: "user",
      content: `${briefBlock(brief)}

Tipo de resultado: ${brief.deliverable ?? "outro"}

Divida o trabalho em 3 a ${LIMITS.maxSteps} etapas pequenas, executáveis apenas com escrita e planejamento. Cada etapa produz um material concreto e autocontido que a pessoa vai poder revisar e aproveitar. As primeiras etapas preparam o terreno (estrutura, pontos-chave, opções); a última entrega a versão mais completa do resultado principal, aproveitando as anteriores. Informações que faltam não impedem as etapas: elas viram marcadores [[a definir: ...]].

Responda em JSON com steps. Para cada etapa:
- title: nome curto começando por substantivo (ex.: "Estrutura da proposta").
- description: uma frase dizendo o que será produzido.
- output: um de "estrutura", "rascunho", "checklist", "cronograma", "mensagem", "notas", "slides".`,
    },
  ];
}

export function generateMessages(
  brief: Brief,
  plan: PlanLine[],
  current: { position: number; title: string; description: string; output: string },
  prior: PriorWork[],
): ChatMessage[] {
  const planText = plan
    .map(
      (p) =>
        `${p.position + 1}. ${p.title}${p.position === current.position ? "  <- etapa atual" : p.status === "done" ? "  (feita)" : ""}`,
    )
    .join("\n");
  const priorText = prior.length
    ? prior.map((p) => `### ${p.title}\n${clip(p.content, LIMITS.contextCharsPerArtifact)}`).join("\n\n")
    : "(nenhum ainda)";
  return [
    { role: "system", content: SYSTEM },
    {
      role: "user",
      content: `${briefBlock(brief)}

Plano de trabalho:
${planText}

Material já produzido:
${priorText}

Agora produza a etapa ${current.position + 1}: "${current.title}".
O que ela entrega: ${current.description}
Formato: ${OUTPUT_GUIDE[current.output] ?? "markdown organizado"}.

Escreva em Markdown, começando direto no conteúdo, sem repetir o nome da etapa na primeira linha. Use títulos (##), listas e tabelas quando ajudarem. No máximo cerca de 600 palavras. Onde faltar informação específica, use [[a definir: ...]] em vez de inventar.`,
    },
  ];
}

export function reviewMessages(brief: Brief, work: PriorWork[]): ChatMessage[] {
  return [
    { role: "system", content: SYSTEM },
    {
      role: "user",
      content: `${briefBlock(brief)}

Materiais produzidos:
${work.map((w) => `### ${w.title}\n${clip(w.content, LIMITS.contextCharsPerArtifact)}`).join("\n\n")}

Revise o trabalho como um editor cuidadoso e honesto. Responda em JSON com:
- verdict: "pronto" (utilizável com pequenos ajustes), "quase" (precisa de complementos) ou "rascunho" (boa base, ainda longe do final).
- summary: duas frases sobre o estado do trabalho.
- strengths: até 3 pontos fortes.
- issues: até 4 problemas concretos, cada um com where (qual material), problem e suggestion.
- missing: informações que ainda precisam vir da pessoa, incluindo marcadores [[a definir]] pendentes.
- nextActions: até 4 próximos passos que a pessoa consegue fazer em poucos minutos ao voltar.
Aponte qualquer trecho que pareça inventado sem base no pedido ou nas respostas.`,
    },
  ];
}

export function reviseMessages(brief: Brief, title: string, content: string, instruction: string): ChatMessage[] {
  return [
    { role: "system", content: SYSTEM },
    {
      role: "user",
      content: `${briefBlock(brief)}

Material atual: "${title}"
${quote(content, 6000)}

Pedido de revisão: ${instruction}

Reescreva o material completo aplicando o pedido. Preserve o que não precisa mudar. Mantenha [[a definir: ...]] para o que continua desconhecido e substitua os marcadores que agora têm resposta. Responda apenas com o Markdown final.`,
    },
  ];
}

export function cleanMarkdown(text: string) {
  let out = text.trim();
  const fenced = out.match(/^```(?:markdown|md)?\s*\n([\s\S]*?)\n```$/i);
  if (fenced) out = fenced[1].trim();
  return out;
}
