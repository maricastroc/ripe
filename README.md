# Ripe

**Tarefas que amadurecem enquanto você procrastina.**

Você escreve algo que vem adiando — "responder o e-mail difícil do cliente", "planejar a viagem de dezembro" — e deixa para depois. A partir daí, um agente de IA rodando **localmente no Ollama** trabalha nisso em etapas pequenas, salvando cada resultado. Quando você volta, encontra um plano, um rascunho, um checklist ou uma primeira versão — e uma lista clara do que ainda depende de você.

Projeto para o Hacktoberfest Open-Source AI Challenge, semana 3: _Slow and Steady_.

## O fluxo

**Criar → Adiar → Amadurecer → Retornar → Revisar**

1. **Criar** — uma frase em linguagem natural. Nada de formulário.
2. **Adiar** — você diz quando pretende voltar ("amanhã", "semana que vem"). O agente começa.
3. **Amadurecer** — o agente interpreta o pedido, divide em etapas, escreve um material por etapa e, no fim, revisa o conjunto.
4. **Retornar** — a página da tarefa mostra o que foi feito, o que está sendo feito agora, o que falta e o que ele precisa saber de você.
5. **Revisar** — todo material é editável, copiável, baixável em `.md` e pode ser reescrito sob instrução ("mais curto", "tom mais formal").

## A metáfora

Cada tarefa é uma planta num vaso, num parapeito. Ela é desenhada proceduralmente em SVG a partir do estado real do trabalho — **nunca pela passagem do tempo**:

| Estágio  | O que precisa ter acontecido                |
| -------- | ------------------------------------------- |
| Semente  | Tarefa plantada                             |
| Broto    | O agente interpretou o pedido               |
| Folhas   | Existe um plano; cada etapa é uma folha     |
| Flor     | O primeiro material ficou pronto            |
| Fruto    | A maior parte das etapas está pronta        |
| Madura   | Tudo produzido e revisado pelo agente       |

Cada etapa é uma folha: botão fechado quando pendente, abrindo enquanto é escrita, aberta quando pronta e amarelada quando espera uma resposta sua. O fruto cresce e muda de cor conforme as etapas terminam. A espécie (caqui, ameixa, limão-siciliano, figo) só aparece quando o agente entende que tipo de resultado você quer.

## Rodando

Requisitos: Node 20.9+ e [Ollama](https://ollama.com).

```bash
ollama pull gemma4:e4b
ollama serve
```

```bash
npm install
npm run setup
npm run dev
```

Abra http://localhost:3000. O modelo, o endereço do Ollama e o ritmo do agente podem ser trocados pela interface (clique no indicador de status no topo). Qualquer modelo instalado no Ollama funciona; modelos com suporte a saída estruturada em JSON dão os melhores resultados.

Para ver uma tarefa madura sem esperar, clique em "veja um exemplo" no parapeito vazio ou rode `npm run db:seed`. O exemplo é marcado como **ilustrativo** na interface e nunca é processado pelo agente.

### Variáveis de ambiente (opcionais)

Veja `.env.example`.

| Variável       | Padrão                       |
| -------------- | ---------------------------- |
| `DATABASE_URL` | `file:./prisma/ripe.db`      |
| `OLLAMA_URL`   | `http://127.0.0.1:11434`     |
| `OLLAMA_MODEL` | `gemma4:e4b`                 |

## Arquitetura

```
src/
  agent/        o agente: cliente Ollama, prompts, schemas, fila, worker
  app/          rotas (páginas e API) do Next.js
  components/   interface: planta, parapeito, página da tarefa
  hooks/        polling e animação de texto ao vivo
  lib/          domínio: tipos, crescimento, consultas, operações
prisma/         schema, migrações e seed do exemplo
```

### O agente

- **Fases separadas** — cada tarefa passa por jobs distintos: `interpret` (entender o pedido, apontar o que falta), `plan` (dividir em 3–5 etapas), `generate` (um material por etapa), `review` (revisão crítica do conjunto) e `revise` (reescrita pedida por você).
- **Fila persistente** — todo job vive no SQLite com estado explícito: `queued → running → done | failed | cancelled`. O worker roda no próprio servidor Next (iniciado via `instrumentation.ts`), pega um job por vez e grava o resultado antes de passar para o próximo.
- **Recuperável** — jobs em execução têm um _lease_. Se o processo cair no meio de uma etapa, ao subir de novo o job volta para a fila sem perder o que já foi salvo, e o diário registra a retomada.
- **Honesto** — a interface lê o estado real do banco: o texto que aparece sendo escrito é a saída do modelo em streaming, salva a cada ~700 ms. Nada é simulado.
- **Sem inventar** — o agente marca informações ausentes como `[[a definir: ...]]`, que viram destaques no texto. Perguntas essenciais seguram apenas a versão final; as etapas anteriores avançam com marcadores.
- **Limites** — uma etapa por vez; até 5 etapas por tarefa; 3 tentativas por etapa com espera crescente; 4 minutos por chamada; 24 execuções por tarefa; pausa configurável entre etapas (o "ritmo").
- **Ollama fora do ar** — o worker verifica a saúde do Ollama antes de pegar um job. Se ele cair, nada é consumido: o job espera e o trabalho continua sozinho quando ele voltar.

### Estados de uma tarefa

| Estado      | Significado                                              |
| ----------- | -------------------------------------------------------- |
| `dormant`   | Plantada ou pausada; nada roda                           |
| `ripening`  | Na fila ou sendo trabalhada                              |
| `waiting`   | Fez tudo o que dava; precisa de uma resposta sua         |
| `ripe`      | Tudo produzido e revisado                                |
| `stalled`   | Falhou após as tentativas; dá para tentar de novo        |
| `harvested` | Você colheu; fica na cesta                               |

## Scripts

| Script              | O que faz                                   |
| ------------------- | ------------------------------------------- |
| `npm run dev`       | Servidor de desenvolvimento + agente        |
| `npm run build`     | Build de produção                           |
| `npm run setup`     | Gera o cliente Prisma e aplica as migrações |
| `npm run db:seed`   | Cria a tarefa de exemplo                    |
| `npm run db:reset`  | Apaga e recria o banco                      |
| `npm run typecheck` | Verificação de tipos                        |
| `npm run lint`      | ESLint                                      |

## Stack

Next.js 16 (App Router) · React 19 · TypeScript · Tailwind CSS 4 · Motion · Radix UI · Prisma 7 + SQLite · Zod · Ollama
