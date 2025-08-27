---
id: task-00003
title: Simplify resource operations UI - replace boxes with dropdown selections
status: To Do
assignee: []
created_date: '2025-08-26 13:22'
updated_date: '2025-08-26 13:22'
labels:
  - ui
  - frontend
  - livewire
dependencies: []
priority: medium
---

## Description

Replace the current box-based layout in resource-operations.blade.php with clean dropdown selections to improve UX when there are many servers, projects, or environments. The current interface becomes overwhelming and cluttered with multiple modal confirmation boxes for each option.

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 Clone section shows a dropdown to select server/destination instead of multiple boxes
- [ ] #2 Move section shows a dropdown to select project/environment instead of multiple boxes
- [ ] #3 Single "Clone Resource" button that triggers modal after dropdown selection
- [ ] #4 Single "Move Resource" button that triggers modal after dropdown selection
- [ ] #5 Authorization warnings remain in place for users without permissions
- [ ] #6 All existing functionality preserved (cloning, moving, success messages)
- [ ] #7 Clean, simple interface that scales well with many options
- [ ] #8 Mobile-friendly dropdown interface
<!-- AC:END -->
