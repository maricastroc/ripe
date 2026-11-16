# Stage 10 report: languages

Branch `migration/stage-10-languages`, 2026-11-16. Ripe now speaks English and Brazilian Portuguese.

## What changed

```
frontend/src/lib/i18n/                     locales, typed dictionaries, the provider
frontend/src/components/LanguageSwitch.tsx the EN · PT switch in the greenhouse header
frontend/src/lib/gateway.ts                forwards the resolved locale to Laravel
backend/app/Http/Middleware/UseRequestLocale.php
backend/app/Observability/StallReason.php  stored in English, presented in the visitor's language
backend/lang/pt_BR.json                    every sentence the API shows, in Portuguese
backend/database/examples/*-pt.json        the two examples, grown from Portuguese requests
frontend/e2e/language.spec.ts
```

- **No migrations, no new dependencies, no change to the API contract.** Only the text inside `message`, the field notes and `stallReason` changes with the locale.

## Choosing the language

- **One decision point.** The site resolves the locale once: the `ripe_locale` cookie, then the browser's `Accept-Language`, then English. `proxy.ts` and the server-side API client both pass through the gateway, which overwrites the `Accept-Language` it forwards with that result. Laravel never guesses on its own, so the interface and the API cannot disagree.
- **No locale in URLs.** `/t/[id]` links work the same in both languages.
- **The switch** in the greenhouse header stores the cookie for a year and reloads the page, so every state already on screen is read again in the new language.

## The interface

- **Typed dictionaries, no library.** `messages/en.ts` defines the shape; `messages/pt-BR.ts` is typed as that shape, so a missing or extra key fails the typecheck. Sentences that depend on numbers or names are functions, which keeps plurals and word order right in each language.
- **Formatting** follows the locale: times, dates, weekdays, decimals in durations, relative times.
- **About 200 strings** across 30 components moved out of the markup, including labels only screen readers hear and the botanical plate's SVG callouts.
- **Glossary**, as approved: estufa, semente, plantar, amadurecer, madura, o fruto vingou, colher, replantar, despensa, ficha do espécime, anéis de crescimento, caderno de campo. `[[TBD: …]]` markers keep their syntax; the highlight reads "A definir".

## The API

- **Laravel's localization.** `UseRequestLocale` reads the forwarded `Accept-Language` and accepts only `en` and `pt_BR`. The plan named `lang/en` and `lang/pt_BR` groups; the implementation keys a single `lang/pt_BR.json` by the English sentence instead, since English is the source and needs no file of its own.
- **What is translated:** refusals and conflicts, validation messages (with custom messages for every limit a visitor can reach), field notes, agent states, public failure reasons, the 404 and both 429s.
- **Stall reasons** are composed in English when a run fails, inside a worker that has no visitor. The task keeps that English text for operators and `ripe:inspect`; the API presents it in the visitor's language. Composing and presenting live in the same class, so the format cannot drift between them.
- **Operator text stays English:** logs, `ripe:*` commands, the local model's health message and halt reason.

## Content follows the request

- The agent already wrote in the request's language. Its prompts stay in English.
- **A gap closed on the way.** The agent review's headings ("What to improve", "Verdict") were fixed English text even for a Portuguese task. They now follow the task's language, and so does the review piece's title.

## Examples

- **Two new examples,** `lisbon-trip-pt` and `late-order-reply-pt`, are the same scenarios as the English ones, written as Portuguese requests and grown through the real executor with Groq's free plan (`openai/gpt-oss-120b`), with the prepared answers matched in Portuguese.
- **The examples endpoint** answers with the examples in the visitor's language and falls back to all of them when none matches.

## Verification

| Check | Result |
|---|---|
| Backend: Pint, Larastan at the maximum level, Pest | pass; 386 tests |
| Frontend: Prettier, ESLint, TypeScript, production build, API types | pass |
| End to end, on an empty database | 3 scenarios |
| Each commit on a clean clone, end to end on an empty database | pass |
| The greenhouse and a task page in Portuguese, in the browser | checked |

**New tests:**
- **Translations.** Every sentence the code passes to `__()` has a Portuguese translation with the same placeholders and plural forms, and no translation is left that the code no longer uses.
- **Language through the API.** With `Accept-Language: pt-BR` a stalled task's reason, its field note and a 404 come back in Portuguese, both forms of stall reason included, while a request without the header and the stored reason stay English.
- **End to end in Portuguese.** A Portuguese browser plants and ripens a task, reads a Portuguese field note from the API, switches back to English, and gets different examples for each language.

**Local environment fixes.** A Turnstile secret in a developer's `.env` made every test that plants fail, in Pest and end to end. The test environments now clear it; CI was never affected.
