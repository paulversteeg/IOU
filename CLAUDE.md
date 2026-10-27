# Front door

You are working on **[project name]** for **[client]**. Read this file first. Then load only what the task needs.

## What lives where

- `context/project/`: the brief, stakeholders and links to Figma, ClickUp and Storybook
- `context/platform/`: rules that hold for every feature (brand, tech, design system, IA, roles, language, decisions)
- `context/features/<name>/`: everything specific to one feature
- `context/research/`: research that is broader than one feature

Platform rules win over feature rules, unless a feature's `history/design-decisions.md` explicitly overrides one (with a DD-ID).

## What to load per task

| Task | Load |
|---|---|
| Design a new screen in an existing feature | platform/design-system, platform/ui-patterns, platform/voice-and-terminology, features/<name>/ (all) |
| Start a new feature | project/brief, platform/information-architecture, platform/roles-and-permissions, platform/relations, research/ |
| Write copy or microcopy | platform/brand/voice-and-terminology, platform/history/glossary, features/<name>/states |
| Prepare refinement or a sprint | features/<name>/tasks/current-sprint, features/<name>/history/ |
| Check technical feasibility | platform/tech/tech-constraints, features/<name>/specs/ |
| Process user research | research/, features/<name>/research |

## Rules for AI tools

- Check `history/dont-do.md` (platform and feature) before proposing a solution.
- Use the preferred terms from `voice-and-terminology.md`.
- Refer to decisions by their ID (DD-001) instead of repeating them.
- Files with `status: outdated` are history, not instructions.
- When you notice the context is wrong or missing, say so and propose the edit.
