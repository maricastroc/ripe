import type { JobType } from "./types";

export function jobLabel(type: JobType | string, subject?: string | null) {
  switch (type) {
    case "interpret":
      return "Lendo o pedido com calma";
    case "plan":
      return "Traçando o caminho em etapas";
    case "generate":
      return subject ? `Escrevendo “${subject}”` : "Escrevendo";
    case "review":
      return "Revisando tudo o que foi produzido";
    case "revise":
      return subject ? `Reescrevendo “${subject}”` : "Reescrevendo";
    default:
      return "Trabalhando";
  }
}

export const OUTPUT_LABEL: Record<string, string> = {
  estrutura: "estrutura",
  rascunho: "rascunho",
  checklist: "checklist",
  cronograma: "cronograma",
  mensagem: "mensagem",
  notas: "notas",
  slides: "roteiro de slides",
  review: "revisão",
};

export const DELIVERABLE_LABEL: Record<string, string> = {
  texto: "Texto",
  plano: "Plano",
  apresentacao: "Apresentação",
  mensagem: "Mensagem",
  checklist: "Checklist",
  outro: "Material escrito",
};
