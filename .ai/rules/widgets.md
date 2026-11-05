---
paths:
    - 'app/Domains/Screens/Support/WidgetSchema.php'
    - 'app/Domains/Screens/Support/WidgetSources.php'
    - 'app/Domains/Screens/Enums/WidgetType.php'
    - 'resources/js/components/lj/screen/widgets.tsx'
---

# Status screen widgets

## A new widget is documented once it is approved

When a new widget type (or a new source or variant of one) has been built and the user has approved it, document it before calling it done:

1. A section in `docs/widgets.md`, in palette order: what it shows, its sources, every option with the label the UI shows, and notes (share links without names, limits).
2. The widget on the "Widget gallery" screen of `database/seeders/ShowcaseWidgetsSeeder.php`, with demo data that makes it show something real.
3. A screenshot of it from `scripts/docs-screenshots.mjs` (`widget-<id>.png`), taken with `npm run docs:screenshots -- widget-<id>` and looked at before it is committed: real content, no loading or error text, no raw translation keys, nothing cut off.

A changed option or behavior updates its section and, when the picture changes, its screenshot.
