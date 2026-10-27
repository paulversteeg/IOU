# Project name

One or two sentences: who this project is for and what it should achieve.

## How this repo works

This repo holds the context of the project, so people and AI tools work from the same source of truth.
Figma, ClickUp and Storybook stay the place where the work happens. This repo explains it.

- `CLAUDE.md` and `AGENTS.md`: the front door for AI tools
- `context/project/`: why the project exists, who is involved, where things live
- `context/platform/`: everything that holds for the whole product
- `context/features/`: one folder per feature, all with the same structure
- `context/research/`: research that is broader than a single feature

## Starting a new feature

```bash
./scripts/new-feature.sh my-feature
```

This copies `context/features/_template/` into a new folder.
