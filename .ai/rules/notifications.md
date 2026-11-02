---
paths:
    - 'app/Domains/*/Notifications/**'
---

# Notifications

## The notifiable must implement HasLocalePreference, not just have the method

Laravel wraps a send in `withLocale()` only when the notifiable is `instanceof Illuminate\Contracts\Translation\HasLocalePreference`. A `preferredLocale()` method without the interface is dead code: every `__()` in `toMail()` resolves against the worker's locale. Any model notified directly (a team, a notification channel) declares the contract and forwards to the right user's preference. Never hardcode a locale in a mail.

Cover it with a test that reads the rendered subject (listen for `Illuminate\Mail\Events\MessageSent`): `Notification::fake()` never applies the locale, so a faked assertion passes while the real mail is in the wrong language.
