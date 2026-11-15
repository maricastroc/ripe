<?php

declare(strict_types=1);

namespace App\Agent\Prompts;

use App\Agent\Brief;
use App\Agent\BriefQuestion;
use App\Agent\Limits;
use App\Agent\Schemas\InterpretationSchema;
use App\Agent\Schemas\PlanSchema;
use App\Agent\Schemas\ReviewSchema;
use App\Agent\Text\Markdown;
use App\Enums\OutputFormat;

final class PromptBook
{
    public const string DEFAULT_REVISION = 'Incorporate the person\'s latest answers and replace the [[TBD]] markers that now have an answer.';

    private const string SYSTEM = <<<'TEXT'
        You are Ripe's agent. You work slowly and carefully on tasks a person decided to put off, so that they find something concrete when they come back.

        Principles:
        - Write in the same language the person used in their request.
        - Never invent specific facts the person did not provide: names, dates, amounts, numbers, places, decisions, quotes or third-party data. When something like that is needed, write the marker [[TBD: short description of what is missing]] in its place.
        - Deliver usable material, not generic advice on how to do it. Write as someone handing over the work.
        - Your work is limited to writing and planning. Never claim you sent, booked, searched the web or executed anything.
        - Be direct. No preambles, no sign-offs, no mention of being an AI.
        TEXT;

    public function interpret(string $request): Prompt
    {
        $questions = Limits::MAX_QUESTIONS;
        $quoted = Markdown::quote($request, Limits::MAX_PROMPT_CHARACTERS);

        return new Prompt(self::SYSTEM, <<<TEXT
            The person's request:
            {$quoted}

            Interpret the request and answer in JSON with:
            - title: a short title (up to 6 words) naming the task.
            - language: the BCP 47 tag of the language the person wrote in, for example "en" or "pt-BR".
            - deliverable: the main kind of result. One of: "document" (article, proposal, essay, report), "plan" (trip, project, event, study, routine), "presentation", "message" (email, letter, reply), "checklist", "other".
            - outcome: one or two sentences describing the concrete result expected when the task is done.
            - context: facts the person provided in the request. Empty list if none. Do not add anything.
            - assumptions: up to 3 safe, generic assumptions you will adopt to move forward without asking.
            - questions: up to {$questions} missing pieces of information only the person can provide. For each one: question (direct and short), why (why it matters, in a few words), kind ("essential" only if, without it, the final version would be wrong or useless even with markers; otherwise "optional").

            Simple names, dates and numbers are almost never essential: they become [[TBD: ...]] markers in the text. Usually there is at most one essential question, and often none. If the request is already clear, return few or no questions.
            TEXT, new InterpretationSchema);
    }

    public function plan(Brief $brief): Prompt
    {
        $essential = array_values(array_filter($brief->questions, static fn (BriefQuestion $question): bool => $question->isOpenEssential()));
        $kind = $brief->deliverable->value ?? 'other';
        $maxSteps = Limits::MAX_STEPS;
        $open = $essential === []
            ? 'Essential questions still open: none.'
            : "Essential questions still open:\n".implode("\n", array_map(static fn (BriefQuestion $question): string => "{$question->number}. {$question->text}", $essential));

        return new Prompt(self::SYSTEM, <<<TEXT
            {$this->briefBlock($brief)}

            Kind of result: {$kind}

            {$open}

            Split the work into 3 to {$maxSteps} small steps that can be done with writing and planning alone. Each step produces a concrete, self-contained piece the person can review and reuse. The first steps prepare the ground (structure, key points, options); the last one delivers the most complete version of the main result, building on the previous ones. Missing information does not block steps: it becomes [[TBD: ...]] markers.

            Answer in JSON with steps. For each step:
            - title: a short name starting with a noun (e.g. "Proposal outline").
            - description: one sentence saying what will be produced.
            - output: one of "outline", "draft", "checklist", "schedule", "message", "notes", "slides".
            - buildsOn: the numbers of earlier steps whose piece this step needs. Empty list if none.
            - requires: the numbers of essential questions without which this step cannot be written even with markers. Usually an empty list.
            TEXT, new PlanSchema(array_map(static fn (BriefQuestion $question): int => $question->number, $essential)));
    }

    /**
     * @param  list<PlanLine>  $plan
     * @param  list<Piece>  $prior
     */
    public function generate(Brief $brief, array $plan, CurrentStep $current, array $prior): Prompt
    {
        $planText = implode("\n", array_map(static fn (PlanLine $line): string => "{$line->number}. {$line->title}".match (true) {
            $line->number === $current->number => '  <- current step',
            $line->done => '  (done)',
            default => '',
        }, $plan));
        $priorText = $prior === [] ? '(nothing yet)' : $this->pieces($prior);
        $guide = $this->guide($current->output);

        return new Prompt(self::SYSTEM, <<<TEXT
            {$this->briefBlock($brief)}

            Work plan:
            {$planText}

            Material already produced:
            {$priorText}

            Now produce step {$current->number}: "{$current->title}".
            What it delivers: {$current->description}
            Format: {$guide}.

            Write in Markdown, starting straight with the content, without repeating the step name on the first line. Use headings (##), lists and tables when they help. At most about 600 words. Where specific information is missing, use [[TBD: ...]] instead of inventing it.
            TEXT);
    }

    /** @param list<Piece> $work */
    public function review(Brief $brief, array $work): Prompt
    {
        return new Prompt(self::SYSTEM, <<<TEXT
            {$this->briefBlock($brief)}

            Material produced:
            {$this->pieces($work)}

            Review the work like a careful, honest editor. Answer in JSON with:
            - verdict: "ready" (usable with small tweaks), "almost" (needs additions) or "draft" (a good base, still far from final).
            - summary: two sentences on the state of the work.
            - strengths: up to 3 strong points.
            - issues: up to 4 concrete problems, each with where (which piece), problem and suggestion.
            - missing: information that still has to come from the person, including pending [[TBD]] markers.
            - nextActions: up to 4 next steps the person can do in a few minutes when they come back.
            Point out any passage that seems invented without basis in the request or the answers.
            TEXT, new ReviewSchema);
    }

    public function revise(Brief $brief, string $title, string $content, ?string $instruction = null): Prompt
    {
        $request = $instruction === null || trim($instruction) === '' ? self::DEFAULT_REVISION : trim($instruction);
        $quoted = Markdown::quote($content, Limits::REVISION_CHARACTERS);

        return new Prompt(self::SYSTEM, <<<TEXT
            {$this->briefBlock($brief)}

            Current material: "{$title}"
            {$quoted}

            Rewrite request: {$request}

            Rewrite the complete material applying the request. Keep what doesn't need to change. Keep [[TBD: ...]] for what is still unknown and replace the markers that now have an answer. Answer only with the final Markdown.
            TEXT);
    }

    private function briefBlock(Brief $brief): string
    {
        $answered = array_values(array_filter($brief->questions, static fn (BriefQuestion $question): bool => $question->isAnswered()));
        $open = array_values(array_filter($brief->questions, static fn (BriefQuestion $question): bool => ! $question->isAnswered()));

        $blocks = [
            "Task: {$brief->title}",
            "The person's original request:\n".Markdown::quote($brief->prompt, Limits::MAX_PROMPT_CHARACTERS),
            $brief->language === null ? null : "Language of the request: {$brief->language}. Write everything in this language.",
            $brief->outcome === null ? null : "Expected outcome: {$brief->outcome}",
            "Facts provided by the person:\n".Markdown::bullets($brief->context),
            $answered === [] ? null : "The person's answers:\n".implode("\n", array_map(
                static fn (BriefQuestion $question): string => "- {$question->text}\n  Answer: {$question->answer}",
                $answered,
            )),
            $open === [] ? null : "Still unanswered (use [[TBD: ...]] where needed):\n".Markdown::bullets(array_map(
                static fn (BriefQuestion $question): string => $question->text,
                $open,
            )),
            $brief->assumptions === [] ? null : "Assumptions adopted:\n".Markdown::bullets($brief->assumptions),
        ];

        return implode("\n\n", array_filter($blocks, static fn (?string $block): bool => $block !== null));
    }

    /** @param list<Piece> $pieces */
    private function pieces(array $pieces): string
    {
        return implode("\n\n", array_map(
            static fn (Piece $piece): string => "### {$piece->title}\n".Markdown::clip($piece->content, Limits::CONTEXT_CHARACTERS_PER_PIECE),
            $pieces,
        ));
    }

    private function guide(OutputFormat $output): string
    {
        return match ($output) {
            OutputFormat::Outline => 'a hierarchical skeleton with the sections and what each one should contain',
            OutputFormat::Draft => 'flowing prose, ready for the person to edit',
            OutputFormat::Checklist => 'a checklist with boxes (- [ ]) grouped by theme',
            OutputFormat::Schedule => 'a plan in phases or days, as a list or table; unknown dates become markers',
            OutputFormat::Message => 'the complete message, with a subject line if it is an email',
            OutputFormat::Notes => 'notes organized in short bullet points',
            OutputFormat::Slides => 'a slide-by-slide outline: a title for each slide, 2 to 4 points and a short speaker note',
        };
    }
}
