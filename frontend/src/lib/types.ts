import type { components } from "@/generated/api";

type Schemas = components["schemas"];

export type TaskStatus = Schemas["TaskStatus"];
export type Stage = Schemas["Stage"];
export type Fruit = Schemas["Fruit"];
export type Deliverable = Schemas["Deliverable"];
export type StepPhase = Schemas["StepPhase"];
export type QuestionStatus = Schemas["QuestionStatus"];
export type QuestionKind = Schemas["QuestionKind"];
export type RunKind = Schemas["RunKind"];
export type Pace = Schemas["Pace"];
export type AgentMode = Schemas["AgentMode"];
export type NoteKind = Schemas["NoteKind"];

export type TaskSummary = Schemas["TaskSummaryResource"];
export type TaskDetail = Schemas["TaskResource"];
export type Activity = Schemas["ActivityResource"];
export type AgentStatus = Schemas["AgentStatusResource"];

export type Growth = TaskSummary["growth"];
export type LeafState = TaskSummary["leaves"][number];
export type StepView = TaskDetail["steps"][number];
export type QuestionView = TaskDetail["questions"][number];
export type ArtifactView = TaskDetail["artifacts"][number];
export type ArtifactDraft = NonNullable<ArtifactView["draft"]>;
export type EventView = TaskDetail["events"][number];

export type Garden = { tasks: TaskSummary[]; agent: AgentStatus };
export type TaskPage = { task: TaskDetail; agent: AgentStatus };
