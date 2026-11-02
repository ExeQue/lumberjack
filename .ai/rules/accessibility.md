---
paths:
    - 'resources/js/**'
---

# Accessibility

Treat this as part of writing the markup, not as a pass afterwards.

## `hidden` already removes an element from the accessibility tree

Tailwind's `hidden` is `display: none`, and a `display: none` subtree is not in the accessibility tree at all. An element hidden that way needs no `aria-hidden`.

## Never put a static `aria-hidden` on a responsively hidden element

In a responsive pair (`md:hidden` / `hidden md:inline`) exactly one is in the tree at every width, because `display` decides. Add `aria-hidden` to either and it is hidden from assistive technology at every width, including the one where it is the only label on screen.

## `hidden`, `sr-only` and `aria-hidden` are different tools

- **`hidden`** - gone for everyone.
- **`sr-only`** - gone visually, still read aloud. An icon-only button needs `<span className="sr-only">` naming the action.
- **`aria-hidden="true"`** - on screen, absent from the tree. For decoration beside a label that already says the same thing.

## Charts and colors never carry meaning alone

A status shown by color (error rate, job status, p95 over threshold) also carries a word or icon with a label. A chart has a text equivalent: a summary line or the table it was drawn from.

## An element that is only there for layout is not focusable

Anything reachable by Tab must do something when activated.

## What to check before calling a page done

- Every control has an accessible name - visible text, `sr-only` text, or `aria-label`.
- Every `<img>` has an `alt`; a decorative one has `alt=""`.
- Every interactive element is reachable by Tab, in an order that matches the page.
- Nothing that folds or toggles reports its state only through color or position: `aria-expanded`, `aria-pressed`, `aria-current`.
- Headings go down one level at a time, and there is exactly one `<h1>`.
