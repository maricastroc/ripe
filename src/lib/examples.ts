import { db } from "./db";

const STEPS = [
  {
    title: "Proposal outline",
    description: "Skeleton of the sections the proposal needs.",
    output: "outline",
    content: `## 1. Summary
- What the garden is, where it is and who takes part (3–4 lines).

## 2. Problem
- Vacant lot on [[TBD: street or square name]].
- Little access to fresh food and few places for neighbors to meet.

## 3. Goals
- Turn the lot into a shared garden run by residents.
- Offer monthly growing workshops for kids and adults.

## 4. How it will work
- Work days, a care rota and simple rules for the beds.

## 5. Budget and timeline
- Items, amounts and phases over six months.

## 6. Signatory
- Person responsible and contact.`,
  },
  {
    title: "Draft: problem and goals",
    description: "Prose for the problem and goals sections.",
    output: "draft",
    content: `## Problem

For years the lot on [[TBD: street or square name]] has been unused. It collects trash, is dark at night and keeps people away. At the same time, many residents say it is hard to buy fresh vegetables close to home and that families have few places to get together.

## Goals

1. **Restore the lot**, clearing it and preparing beds for a shared garden.
2. **Grow fresh food** for the families who help look after it.
3. **Create a meeting point**, with monthly growing workshops open to the neighborhood.
4. **Make it last**, with a care rota and a few simple rules agreed by the participants.`,
  },
  {
    title: "Budget and timeline",
    description: "Cost table and a six-month plan.",
    output: "schedule",
    content: `## Budget (cap of $8,000)

| Item | Estimate |
| --- | --- |
| Clearing and preparing the lot | $1,800 |
| Soil, compost and seedlings | $2,200 |
| Tools and watering cans | $1,400 |
| Simple irrigation system | $1,600 |
| Workshop materials | $1,000 |
| **Total** | **$8,000** |

## Timeline

- [ ] **Month 1** — clean-up day and residents' meeting
- [ ] **Month 2** — build the beds and install irrigation
- [ ] **Month 3** — first planting and care rota
- [ ] **Months 4 to 6** — monthly workshops and first harvest`,
  },
  {
    title: "Full proposal",
    description: "Integrated version, ready to review and submit.",
    output: "draft",
    content: `# Neighborhood Community Garden

## Summary
We propose turning the vacant lot on [[TBD: street or square name]] into a shared garden cared for by residents, with monthly workshops open to the neighborhood. We are requesting **$8,000** for six months of setup.

## Problem
The lot has been unused for years, collects trash and keeps people away. Fresh food is hard to find nearby and families have few places to spend time together.

## Goals
Restore the lot, grow fresh food, create a meeting point and keep it going with a care rota agreed among the participants.

## How it will work
The beds will be tended on work days and through a weekly rota. Each participating family takes one shift per month. The rules for using the garden will be set at the first meeting.

## Budget and timeline
The budget covers preparing the lot, supplies, tools, irrigation and workshops, totaling $8,000. Setup takes six months, from the clean-up day to the first harvest.

## Signatory
[[TBD: name and contact of the person signing]]`,
  },
];

const REVIEW = `**Verdict:** Almost there — needs a few additions.

The proposal has a clear structure and a budget that matches the stated cap. Two details that only you know are still missing before it can be submitted.

## What already works
- Concrete goals that are easy to evaluate.
- A detailed budget consistent with the amount requested.

## What to improve
- **Full proposal:** the "How it will work" section is still generic → mention how many families have shown interest, if you know.

## What still depends on you
- The name of the street or square where the lot is.
- The name and contact of whoever signs.

## Next steps when you come back
- [ ] Fill in where the lot is
- [ ] Decide who signs the proposal
- [ ] Read the full version out loud once`;

export async function createExample() {
  const now = Date.now();
  const at = (minutesAgo: number) => new Date(now - minutesAgo * 60_000);
  const task = await db.task.create({
    data: {
      prompt:
        "Write the project proposal for our neighborhood community garden for the city's small grants program. The maximum grant is $8,000.",
      title: "Community garden proposal",
      status: "ripe",
      isExample: true,
      deliverable: "document",
      fruit: "plum",
      outcome: "A complete grant proposal covering the problem, goals, how it will work, budget and timeline.",
      context: JSON.stringify(["It's for the city's small grants program.", "The maximum grant is $8,000."]),
      assumptions: JSON.stringify(["Six-month setup.", "Plain language, no jargon."]),
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
        text: "Where is the lot?",
        why: "The location appears in the summary and the problem.",
        blocking: false,
        status: "skipped",
      },
      {
        taskId: task.id,
        position: 1,
        text: "Who signs the proposal?",
        why: "The grant requires a responsible person.",
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
        model: "example",
      },
    });
  }
  await db.artifact.create({
    data: { taskId: task.id, kind: "review", title: "Agent review", content: REVIEW, model: "example" },
  });

  await db.event.createMany({
    data: [
      {
        taskId: task.id,
        kind: "info",
        message: "This is an illustrative example, written by hand to show the result. Nothing here was generated now.",
        createdAt: at(190),
      },
      {
        taskId: task.id,
        kind: "done",
        message: "Understood the request. A complete proposal for the grant.",
        createdAt: at(187),
      },
      { taskId: task.id, kind: "done", message: "Mapped a path in 4 steps.", createdAt: at(185) },
      {
        taskId: task.id,
        kind: "done",
        message: "Finished “Full proposal” — left 2 gaps marked instead of making things up.",
        createdAt: at(165),
      },
      { taskId: task.id, kind: "done", message: "Ripe. Everything written and reviewed.", createdAt: at(160) },
    ],
  });

  return task.id;
}
