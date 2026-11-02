---
paths:
    - 'app/Domains/*/Http/Controllers/**'
---

# Controllers

## Controllers are thin

A controller authorizes through the Policy, validates through a FormRequest, calls one or more Actions and returns an Inertia response, a redirect or JSON. Business logic never lives in a controller - the MCP server reaches the same functionality through the same Actions, and anything left in a controller is something MCP cannot do.

## Controller validation goes through a FormRequest

A controller action that validates input type-hints a dedicated FormRequest from `{Domain}/Http/Requests/`. Never call `$request->validate()` or `Validator::make()` inside a controller. Route-segment values that need validating are merged in via `prepareForValidation()` on the FormRequest.

## No Eloquent API Resources

Inertia props are built explicitly (arrays or Data objects) in the controller or a dedicated presenter. Do not add Eloquent API Resources.
