"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { ApiError, api } from "@/lib/api";
import type { ArtifactDraft, ArtifactView, TaskDetail } from "@/lib/types";

const AUTOSAVE_MS = 800;
const KEEP_AFTER_MS = 30_000;

export type DraftState = "idle" | "saving" | "saved" | "kept" | "error";

export type DraftConflict = { kind: "overtaken"; currentVersionId: string } | { kind: "elsewhere" } | null;

type Session = {
  artifactId: string;
  base: string | null;
  revision: number | null;
  value: string;
  saved: string;
};

function sessionOf(artifact: ArtifactView): Session {
  const draft = artifact.draft;
  return {
    artifactId: artifact.id,
    base: draft?.baseVersionId ?? artifact.version?.id ?? null,
    revision: draft?.revision ?? null,
    value: draft?.content ?? artifact.content,
    saved: draft?.content ?? artifact.content,
  };
}

function conflictOf(artifact: ArtifactView): DraftConflict {
  return artifact.draft?.conflicting && artifact.version
    ? { kind: "overtaken", currentVersionId: artifact.version.id }
    : null;
}

export function useDraft(
  artifact: ArtifactView | null,
  onTask: (task: TaskDetail) => void,
  reload: () => Promise<TaskDetail | null>,
) {
  const session = useRef<Session | null>(artifact ? sessionOf(artifact) : null);
  const queue = useRef<Promise<void>>(Promise.resolve());
  const saveTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const keepTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const conflictRef = useRef<DraftConflict>(artifact ? conflictOf(artifact) : null);
  const [value, setValue] = useState(() => (artifact ? sessionOf(artifact).value : ""));
  const [state, setState] = useState<DraftState>("idle");
  const [conflict, setConflict] = useState<DraftConflict>(() => (artifact ? conflictOf(artifact) : null));
  const [hasDraft, setHasDraft] = useState(Boolean(artifact?.draft));

  const raise = useCallback((next: DraftConflict) => {
    conflictRef.current = next;
    setConflict(next);
  }, []);

  const clearTimers = useCallback(() => {
    if (saveTimer.current) clearTimeout(saveTimer.current);
    if (keepTimer.current) clearTimeout(keepTimer.current);
    saveTimer.current = null;
    keepTimer.current = null;
  }, []);

  const enqueue = useCallback((work: () => Promise<void>) => {
    const next = queue.current.then(work, work);
    queue.current = next.catch(() => undefined);
    return next;
  }, []);

  const adopt = useCallback(
    (artifactNow: ArtifactView) => {
      session.current = sessionOf(artifactNow);
      setValue(session.current.value);
      setHasDraft(Boolean(artifactNow.draft));
      raise(conflictOf(artifactNow));
    },
    [raise],
  );

  useEffect(() => {
    if (!artifact) return;
    const current = session.current;
    if (!current || current.artifactId !== artifact.id) {
      clearTimers();
      adopt(artifact);
      setState("idle");
      return;
    }
    const idle = current.value === current.saved && !saveTimer.current && !keepTimer.current;
    if (!idle || conflictRef.current) return;
    if (artifact.draft?.revision !== (current.revision ?? undefined) || artifact.version?.id !== current.base) {
      adopt(artifact);
    }
  }, [artifact, adopt, clearTimers]);

  const failed = useCallback(
    (err: unknown) => {
      if (!(err instanceof ApiError) || err.status !== 409 || !session.current) {
        setState("error");
        return;
      }
      const currentVersionId = typeof err.body.currentVersionId === "string" ? err.body.currentVersionId : null;
      const revision = typeof err.body.draftRevision === "number" ? err.body.draftRevision : null;
      session.current.revision = revision;
      clearTimers();
      raise(
        currentVersionId && currentVersionId !== session.current.base
          ? { kind: "overtaken", currentVersionId }
          : { kind: "elsewhere" },
      );
      setState("idle");
    },
    [clearTimers, raise],
  );

  const save = useCallback(
    (force = false) =>
      enqueue(async () => {
        const current = session.current;
        if (!current || conflictRef.current || !current.base) return;
        if (!force && current.value === current.saved) return;
        const content = current.value;
        setState("saving");
        try {
          const res = await api<{ draft: ArtifactDraft | null }>(`/api/artifacts/${current.artifactId}/draft`, {
            method: "PUT",
            body: { content, baseVersionId: current.base, draftRevision: current.revision },
          });
          current.revision = res.draft?.revision ?? null;
          current.saved = content;
          setHasDraft(res.draft !== null);
          setState("saved");
        } catch (err) {
          failed(err);
        }
      }),
    [enqueue, failed],
  );

  const keep = useCallback(async () => {
    clearTimers();
    await save();
    await enqueue(async () => {
      const current = session.current;
      if (!current || conflictRef.current || current.revision === null || !current.base) return;
      try {
        const res = await api<{ task: TaskDetail }>(`/api/artifacts/${current.artifactId}/draft/keep`, {
          method: "POST",
          body: { baseVersionId: current.base, draftRevision: current.revision },
        });
        const kept = res.task.artifacts.find((a) => a.id === current.artifactId);
        current.revision = null;
        current.base = kept?.version?.id ?? current.base;
        current.saved = current.value;
        setHasDraft(false);
        setState("kept");
        onTask(res.task);
      } catch (err) {
        failed(err);
      }
    });
  }, [clearTimers, enqueue, failed, onTask, save]);

  const edit = useCallback(
    (next: string) => {
      if (!session.current) return;
      session.current.value = next;
      setValue(next);
      if (conflictRef.current) return;
      setState("saving");
      if (saveTimer.current) clearTimeout(saveTimer.current);
      saveTimer.current = setTimeout(() => {
        saveTimer.current = null;
        void save();
      }, AUTOSAVE_MS);
      if (keepTimer.current) clearTimeout(keepTimer.current);
      keepTimer.current = setTimeout(() => {
        keepTimer.current = null;
        void keep();
      }, KEEP_AFTER_MS);
    },
    [keep, save],
  );

  const keepMine = useCallback(async () => {
    const current = session.current;
    const pending = conflictRef.current;
    if (!current || !pending) return;
    if (pending.kind === "overtaken") current.base = pending.currentVersionId;
    raise(null);
    await save(true);
    if (pending.kind === "overtaken") await keep();
  }, [keep, raise, save]);

  const takeTheirs = useCallback(async () => {
    const current = session.current;
    const pending = conflictRef.current;
    if (!current || !pending) return;
    clearTimers();
    if (pending.kind === "overtaken" && current.revision !== null) {
      await enqueue(async () => {
        try {
          await api(`/api/artifacts/${current.artifactId}/draft`, {
            method: "DELETE",
            body: { draftRevision: current.revision },
          });
        } catch (err) {
          failed(err);
        }
      });
    }
    const task = await reload();
    const fresh = task?.artifacts.find((a) => a.id === current.artifactId);
    if (fresh) adopt(fresh);
    setState("idle");
  }, [adopt, clearTimers, enqueue, failed, reload]);

  const flushOnHide = useCallback(() => {
    const current = session.current;
    if (!current || conflictRef.current || current.value === current.saved || !current.base) return;
    void api(`/api/artifacts/${current.artifactId}/draft`, {
      method: "PUT",
      keepalive: true,
      body: { content: current.value, baseVersionId: current.base, draftRevision: current.revision },
    }).catch(() => undefined);
  }, []);

  useEffect(() => {
    const onHide = () => {
      if (document.visibilityState === "hidden") flushOnHide();
    };
    document.addEventListener("visibilitychange", onHide);
    window.addEventListener("pagehide", flushOnHide);
    return () => {
      document.removeEventListener("visibilitychange", onHide);
      window.removeEventListener("pagehide", flushOnHide);
    };
  }, [flushOnHide]);

  useEffect(() => () => clearTimers(), [clearTimers]);

  return { value, state, conflict, hasDraft, edit, keep, keepMine, takeTheirs };
}
