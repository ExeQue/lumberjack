# Packagist

Connect a project to a Composer package on [Packagist](https://packagist.org), and
Lumberjack shows its downloads, the versions in use, its releases and the security advisories
against it on a page of its own, and offers the figures to screen [widgets](../widgets.md) as
[data sources](../data-sources.md).

Lumberjack only reads Packagist, through its public [API](https://packagist.org/apidoc), so
no key is needed. Packagist counts downloads per day and publishes a day once it is over,
usually a day or so late, so the latest figures are from yesterday or the day before.

## Connecting

Team owners and admins connect Packagist from the project's **Connectors** page, or from the
**Packagist** page of a project that has none yet.

1. Choose **Connect Packagist**.
2. Start typing in **Package**: Packagist is searched as you type, the most downloaded
   packages first, each with its downloads. A name typed in full as `vendor/package` can be
   picked too.
3. Choose **Connect**. Lumberjack asks Packagist once, and says on the field if it has no such
   package.

A project reads as many packages as you connect. Connecting the same package again renews its
connection. With more than one, a picker beside the title switches between them. Under the
page's **Connection** tab, for team owners and admins:

- **Connect another package** opens the connect dialog.
- **Disconnect** removes this connection and its data sources.

## The Packagist page

Once connected, **Packagist** shows in the project's sidebar. Packagist counts whole days, so
the page offers 1, 7 or 30 days and no hour, each the last days Packagist has published; a
line below the cards says through which day.

On top: the downloads in the period against as many days before, the downloads in all, and
the package's favers, dependents, suggesters, GitHub stars and security advisories, with links
to the package on Packagist and its repository. The tabs below:

- **Overview**: the downloads per day.
- **Versions**: the period's downloads by major version, with each one's share, and the
  latest tagged releases (branches such as `dev-main` are left out).
- **Advisories**: the known security advisories against the package, newest first, with
  their severity, the versions they affect and a link to each.

## Data sources

A Packagist connection offers these sources, filed under **Packagist** and the package's name
in the designer's **Data source** tree. They are read from Packagist only while a screen shows
them, like an app's [connected sources](../data-sources.md#connected-sources).

| Source              | Shape  |
| ------------------- | ------ |
| Downloads           | number |
| Downloads per day   | series |
| Downloads in all    | number |
| Favers              | number |
| Dependents          | number |
| Security advisories | number |

Packagist counts whole days, so a widget showing a Packagist source offers 24 hours, 7 days or
30 days, each read as that many of the last days Packagist published. A series has a point per
day whatever the chart's resolution.

## When Packagist cannot be read

The connection and the Packagist page say why:

| Error                          | What to do                                 |
| ------------------------------ | ------------------------------------------ |
| Packagist has no such package  | Check its name, or whether it was removed. |
| Packagist asks to slow down    | Nothing; it is asked again shortly.        |
| Packagist could not be reached | Nothing; it is asked again shortly.        |
