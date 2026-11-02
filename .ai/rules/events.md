---
paths:
    - 'app/Domains/*/Events/**'
---

# Events

## Broadcast events keep models private and expose an explicit payload

A `ShouldBroadcast` event promotes its models as `private readonly` constructor properties and returns the wire payload from `broadcastWith()` - never expose a model as a public property. Give it a `broadcastAs()` name, and build `broadcastOn()` from the domain's channel classes in `{Domain}/Broadcasting/` instead of raw channel strings.
