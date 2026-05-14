# Agent Instructions

## General Rules
1. **Plan Changes Thoroughly**: Before making any changes, check all connected components, files, and folder structures to ensure consistency.
2. **Optimize Token Usage**: Save tokens whenever possible. Keep explanations concise and avoid lengthy narratives.
3. **High Token Usage Tasks**: For tasks that consume significant tokens, ask for confirmation before proceeding.

## Project Specific Instructions
1. **Database Synchronization**: Any changes made to the form or the generated prompt output must also be reflected in `db.sql`. When updating `db.sql`, do NOT modify the existing `CREATE TABLE` statements. Instead, append new changes as `ALTER TABLE` or separate `UPDATE DB` scripts so that existing data structures are preserved and incremental changes are clear.
2. **Modification Timestamps**: Every new database modification in `db.sql` must include a timestamp in IST format (e.g., `2026-05-14 10:30:00 IST`).
