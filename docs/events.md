# Events

An event is something that happened in your app, recorded on purpose: an order placed,
a user signed in, an import finished. Lumberjack keeps each one with who did it, what it
was done to and what the app said about it, and counts them per hour. Use events for an
activity trail and for simple analytics: how often something happens, and when.

## Recording an event

The [Laravel library](./libraries.md) records an event with `Lumberjack::event()`; its
docs show the options. In the raw ingest API an event is an event of type `event` in a
batch, see [Event events](./api.md#event-events).

An event has:

- **A name**, such as `order.placed`: letters, digits, `.`, `_`, `:` and `-`, at most
  128 characters. An event with any other name is skipped.
- **A group**, such as `billing`, made into a slug. Without one, `default`. An event is
  its group and name together, so `created` in `orders` and `created` in `users` are two
  events.
- **An actor**, optional: who did it, as a type and a key, such as `App\Models\User` and
  `17`. It can be anything with a key, not only a user: a team, an API client, `system`.
- **A subject**, optional: what it was done to, in the same form, such as `order` and
  `42`.
- **A payload**, optional: what else the app wants to keep, as JSON. It is scrubbed like
  [error context](./administration.md#secrets-in-stored-data). A payload larger than 16 KB is not kept; the
  event is, marked as having had one too large.

Each event also gets the batch's environment, release and host, and the trace it was
recorded in, if any.

### No personal data by default

Lumberjack only gets an actor's and a subject's type and key, never a name or an email
address. Nothing is recorded unless the app asks: the library sets no actor by itself.
What goes into the payload is up to the app.

## The Events page

**Events** in a project's sidebar lists the events of the period, by group, most frequent
first, for the period and environment picked at the top. Each shows how often it
happened, a trend over the period and when it last happened.

Click an event to see its occurrences, newest first, 50 at a time:

- **Sort by:** **Newest first**, or **Actor**, which puts each actor's events together.
- **Actor** and **Subject:** click one to show only its events. The button above the
  list takes the filter off again.
- **Payload:** click it to see it in full.
- **Trace:** opens the request or job the event was recorded in.

## Forgetting an actor

To delete every event of one actor in a project, such as when a user deletes their
account:

- **From the app:** `DELETE /api/events/actor` with the server token and the actor's
  `type` and `id`, see [Ingest and API](./api.md#forgetting-an-actor). The Laravel
  library has a method for it.
- **From the Events page:** filter an event's occurrences to the actor, then **Forget
  actor**. Team owners and admins only.

The hourly counts stay: they never held the actor.

## Retention

Events are kept 30 days and their hourly counts 365 days by default, each its own data
type in [retention](./projects.md#retention). Charts and widgets read the counts, so they
reach back further than the events themselves.
