export type TaskStatus = "dormant" | "ripening" | "waiting" | "ripe" | "stalled" | "harvested";
export type StepStatus = "pending" | "blocked" | "working" | "done";
export type QuestionStatus = "open" | "answered" | "skipped";
export type JobType = "interpret" | "plan" | "generate" | "review" | "revise";
export type JobStatus = "queued" | "running" | "done" | "failed" | "cancelled";
export type Fruit = "persimmon" | "plum" | "lemon" | "fig";
export type Deliverable = "document" | "plan" | "presentation" | "message" | "checklist" | "other";
export type Stage = "seed" | "sprout" | "leafing" | "blossom" | "fruit" | "ripe";
export type Pace = "calm" | "steady" | "brisk";
export type AgentMode =
  "starting" | "idle" | "working" | "resting" | "offline" | "model-missing" | "paused" | "stopped";
export type EventKind = "user" | "info" | "work" | "done" | "warn" | "error";

export type Growth = {
  stage: Stage;
  maturity: number;
  stepsDone: number;
  stepsTotal: number;
};

export type Activity = {
  jobId: string;
  type: JobType;
  label: string;
  status: "queued" | "running";
  attempts: number;
  maxAttempts: number;
  startedAt: string | null;
  runAfter: string;
  partial: string | null;
  stepId: string | null;
  artifactId: string | null;
  lastError: string | null;
};

export type LeafState = { id: string; status: StepStatus; artifactId: string | null; title: string };

export type TaskSummary = {
  id: string;
  title: string;
  prompt: string;
  status: TaskStatus;
  fruit: Fruit | null;
  isExample: boolean;
  returnAt: string | null;
  createdAt: string;
  ripeAt: string | null;
  harvestedAt: string | null;
  stallReason: string | null;
  growth: Growth;
  leaves: LeafState[];
  openQuestions: number;
  essentialOpen: number;
  activity: Activity | null;
};

export type StepView = {
  id: string;
  position: number;
  title: string;
  description: string;
  output: string;
  status: StepStatus;
  needs: string[];
  artifactId: string | null;
};

export type QuestionView = {
  id: string;
  text: string;
  why: string;
  blocking: boolean;
  answer: string | null;
  status: QuestionStatus;
};

export type ArtifactView = {
  id: string;
  stepId: string | null;
  kind: "step" | "review";
  title: string;
  content: string;
  hasPrevious: boolean;
  version: number;
  editedByUser: boolean;
  model: string | null;
  updatedAt: string;
  gaps: string[];
};

export type EventView = {
  id: string;
  kind: EventKind;
  message: string;
  createdAt: string;
};

export type TaskDetail = TaskSummary & {
  number: number;
  deliverable: Deliverable | null;
  outcome: string | null;
  context: string[];
  assumptions: string[];
  steps: StepView[];
  questions: QuestionView[];
  artifacts: ArtifactView[];
  events: EventView[];
  jobsUsed: number;
  jobBudget: number;
};

export type Settings = {
  model: string;
  baseUrl: string;
  pace: Pace;
  paused: boolean;
};

export type AgentStatus = {
  mode: AgentMode;
  message: string | null;
  online: boolean;
  models: string[];
  settings: Settings;
  current: { taskId: string; taskTitle: string; label: string } | null;
  queued: number;
  heartbeatAt: string | null;
};

export type Garden = {
  tasks: TaskSummary[];
  agent: AgentStatus;
};
