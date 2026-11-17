---
type: added
---
Executions list of every schedule on the `Backups` page of a standalone database

- The `Schedules` and `Executions` tables look the same as on the `Backups` page of a service
- A click on a schedule opens its settings in a modal, as on the `Backups` page of a service
- The separate pages of a schedule are gone. Old links open the schedule modal on the `Backups` page
- Each row of `Executions` can delete its backup, and `Clean failed backups` and `Clean deleted entries` remove failed executions and executions without a local file
