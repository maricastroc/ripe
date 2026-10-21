import { db } from "./db";

const STEPS = [
  {
    title: "Estrutura da proposta",
    description: "Esqueleto das seções que a proposta precisa ter.",
    output: "estrutura",
    content: `## 1. Resumo
- O que é a horta, onde fica e quem participa (3–4 linhas).

## 2. Problema
- Terreno ocioso na [[a definir: nome da rua ou praça]].
- Pouco acesso a alimentos frescos e a espaços de convivência no bairro.

## 3. Objetivos
- Transformar o terreno em uma horta coletiva mantida por moradores.
- Oferecer oficinas mensais de cultivo para crianças e adultos.

## 4. Como vai funcionar
- Mutirões, escala de cuidado e regras de uso dos canteiros.

## 5. Orçamento e cronograma
- Itens, valores e etapas em seis meses.

## 6. Quem assina
- Responsável e contato.`,
  },
  {
    title: "Rascunho: problema e objetivos",
    description: "Texto corrido das seções de problema e objetivos.",
    output: "rascunho",
    content: `## Problema

Há anos o terreno da [[a definir: nome da rua ou praça]] está sem uso. Ele acumula lixo, fica escuro à noite e afasta quem passa por ali. Ao mesmo tempo, muitos moradores comentam que é difícil comprar verduras frescas perto de casa e que faltam lugares para as famílias se encontrarem.

## Objetivos

1. **Recuperar o terreno**, limpando e preparando canteiros para uma horta coletiva.
2. **Produzir alimentos frescos** para as famílias que participam do cuidado.
3. **Criar um ponto de encontro**, com oficinas mensais de cultivo abertas ao bairro.
4. **Garantir continuidade**, com uma escala de cuidado e regras simples combinadas entre os participantes.`,
  },
  {
    title: "Orçamento e cronograma",
    description: "Tabela de custos e plano em seis meses.",
    output: "cronograma",
    content: `## Orçamento (teto de R$ 8.000)

| Item | Valor estimado |
| --- | --- |
| Limpeza e preparo do terreno | R$ 1.800 |
| Terra, adubo e mudas | R$ 2.200 |
| Ferramentas e regadores | R$ 1.400 |
| Sistema simples de irrigação | R$ 1.600 |
| Material para oficinas | R$ 1.000 |
| **Total** | **R$ 8.000** |

## Cronograma

- [ ] **Mês 1** — mutirão de limpeza e reunião de moradores
- [ ] **Mês 2** — preparo dos canteiros e instalação da irrigação
- [ ] **Mês 3** — primeiro plantio e escala de cuidado
- [ ] **Meses 4 a 6** — oficinas mensais e primeira colheita`,
  },
  {
    title: "Proposta completa",
    description: "Versão integrada, pronta para revisar e enviar.",
    output: "rascunho",
    content: `# Horta Comunitária do Bairro

## Resumo
Propomos transformar o terreno ocioso da [[a definir: nome da rua ou praça]] em uma horta coletiva cuidada por moradores, com oficinas mensais abertas ao bairro. O apoio solicitado é de **R$ 8.000** para seis meses de implantação.

## Problema
O terreno está sem uso há anos, acumula lixo e afasta quem passa. Faltam alimentos frescos por perto e espaços de convivência para as famílias.

## Objetivos
Recuperar o terreno, produzir alimentos frescos, criar um ponto de encontro e garantir a continuidade com uma escala de cuidado combinada entre os participantes.

## Como vai funcionar
Os canteiros serão cuidados em mutirões e por uma escala semanal. Cada família participante assume um turno por mês. As regras de uso serão definidas na primeira reunião.

## Orçamento e cronograma
O orçamento cobre preparo do terreno, insumos, ferramentas, irrigação e oficinas, somando R$ 8.000. A implantação acontece em seis meses, do mutirão de limpeza à primeira colheita.

## Responsável
[[a definir: nome de quem assina e contato]]`,
  },
];

const REVIEW = `**Veredito:** Quase lá — precisa de complementos.

A proposta tem estrutura clara e um orçamento que fecha no teto informado. Faltam dois dados que só você tem para ela poder ser enviada.

## O que já está bom
- Objetivos concretos e fáceis de avaliar.
- Orçamento detalhado e coerente com o valor pedido.

## O que melhorar
- **Proposta completa:** a seção "Como vai funcionar" ainda é genérica → cite quantas famílias já demonstraram interesse, se souber.

## O que ainda depende de você
- Nome da rua ou praça onde fica o terreno.
- Nome e contato de quem assina.

## Próximos passos quando voltar
- [ ] Preencher o local do terreno
- [ ] Definir quem assina a proposta
- [ ] Ler a versão completa em voz alta uma vez`;

export async function createExample() {
  const now = Date.now();
  const at = (minutesAgo: number) => new Date(now - minutesAgo * 60_000);
  const task = await db.task.create({
    data: {
      prompt:
        "Escrever a proposta de projeto da horta comunitária do bairro para o edital da prefeitura. O apoio máximo é de R$ 8.000.",
      title: "Proposta da horta comunitária",
      status: "ripe",
      isExample: true,
      deliverable: "texto",
      fruit: "ameixa",
      outcome: "Uma proposta completa para o edital, com problema, objetivos, funcionamento, orçamento e cronograma.",
      context: JSON.stringify(["É para o edital da prefeitura.", "O apoio máximo é de R$ 8.000."]),
      assumptions: JSON.stringify(["Implantação em seis meses.", "Linguagem simples, sem jargão técnico."]),
      createdAt: at(190),
      deferredAt: at(189),
      interpretedAt: at(187),
      plannedAt: at(185),
      reviewedAt: at(160),
      ripeAt: at(160),
      returnAt: new Date(now + 16 * 3600_000),
    },
  });

  await db.question.createMany({
    data: [
      {
        taskId: task.id,
        position: 0,
        text: "Onde fica o terreno?",
        why: "O local aparece no resumo e no problema.",
        blocking: false,
        status: "skipped",
      },
      {
        taskId: task.id,
        position: 1,
        text: "Quem assina a proposta?",
        why: "O edital pede um responsável.",
        blocking: false,
        status: "open",
      },
    ],
  });

  for (const [position, step] of STEPS.entries()) {
    const created = await db.step.create({
      data: {
        taskId: task.id,
        position,
        title: step.title,
        description: step.description,
        output: step.output,
        status: "done",
      },
    });
    await db.artifact.create({
      data: {
        taskId: task.id,
        stepId: created.id,
        kind: "step",
        title: step.title,
        content: step.content,
        model: "exemplo",
      },
    });
  }
  await db.artifact.create({
    data: { taskId: task.id, kind: "review", title: "Revisão do agente", content: REVIEW, model: "exemplo" },
  });

  await db.event.createMany({
    data: [
      {
        taskId: task.id,
        kind: "info",
        message: "Este é um exemplo ilustrativo, escrito à mão para mostrar o resultado. Nada aqui foi gerado agora.",
        createdAt: at(190),
      },
      {
        taskId: task.id,
        kind: "done",
        message: "Entendi o pedido. Uma proposta completa para o edital.",
        createdAt: at(187),
      },
      { taskId: task.id, kind: "done", message: "Tracei um caminho em 4 etapas.", createdAt: at(185) },
      {
        taskId: task.id,
        kind: "done",
        message: "Concluí “Proposta completa” — deixei 2 lacunas marcadas em vez de inventar.",
        createdAt: at(165),
      },
      { taskId: task.id, kind: "done", message: "Madura. Tudo produzido e revisado.", createdAt: at(160) },
    ],
  });

  return task.id;
}
