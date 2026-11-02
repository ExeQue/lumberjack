---
paths:
    - routes/console.php
---

# Routes

## Scheduling lives in routes/console.php

Schedule commands with `Schedule::command(SomeCommand::class)` by class name, never a command string. Add `->withoutOverlapping()` to anything that can run long (retention cleanup, rollups), and pin day-specific jobs with `->timezone('Europe/Copenhagen')`. Do not schedule from a service provider or inside the command.
