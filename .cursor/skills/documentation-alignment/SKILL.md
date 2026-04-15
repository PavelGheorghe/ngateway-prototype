---
name: documentation-alignment
description: >-
  Ensures implementations and answers follow project documentation before coding
  or advising. Use when implementing features, fixing bugs in specified areas,
  answering questions about protocols or APIs, or whenever the user references
  @documentation, payloads, gateway specs, or project docs.
---

# Documentation-aligned implementation

Project truth lives in versioned docs and the root README. Do not rely on memory or generic framework defaults when specs exist here.

## Where to look (in order)

1. **`documentation/`** — authoritative specs (e.g. Payload 2.0, gateway contracts). Read the sections that match the task.
2. **`README.md`** — project setup, conventions, and pointers not duplicated in `documentation/`.
3. **Code** — only after the relevant doc sections are identified; code must match docs, not the other way around unless the user explicitly changes the spec.

For a full file list of `documentation/`, see [reference.md](reference.md).

## Workflow (every implementation or technical answer)

1. **Scope** — Name the feature, endpoint, payload type, or rule in question.
2. **Discover** — Search or list `documentation/` (and README) for matching titles, headings, or keywords (payload, gateway, field names).
3. **Read** — Use the Read tool on the relevant HTML or markdown. For large HTML files, search inside the file first, then read the needed range.
4. **Align** — Implement or explain using the same names, types, required fields, flows, and error semantics as the doc. If something is ambiguous, state the ambiguity and choose the smallest interpretation consistent with surrounding spec text.
5. **Verify** — Before finishing, mentally check: field names, nesting, enums, version (e.g. Payload 2.0), and any MUST/SHOULD-style rules stated in the doc.

## When documentation is missing or outdated

- Prefer documenting the gap briefly in the answer (what was not found).
- Do not invent protocol details; ask the user or align with the closest documented section and note assumptions.

## Anti-patterns

- Implementing from Symfony or HTTP conventions alone when `documentation/` defines different behavior.
- Paraphrasing specs from memory after a long chat — re-open the doc when changing behavior.
