# Finding things

## The command palette

Press `Ctrl+K` (`⌘K` on a Mac), or click **Search** at the top of the sidebar.

Without typing anything you can jump to:

- any page of the project you are in,
- the team and administration pages,
- another project in the team,
- another team (this switches team).

Type at least two characters and the palette also searches the project you are in:

| What            | Matched on                                        | Opens                            |
| --------------- | ------------------------------------------------- | -------------------------------- |
| Issues          | title, message or number (`DEMO-42`, `#42`, `42`) | the issue                        |
| Routes          | route or URL, seen in the last 7 days             | the route on the Routes page     |
| Jobs            | class name, run in the last 7 days                | the job on the Jobs page         |
| Commands        | command name, run in the last 7 days              | the command on the Commands page |
| Scheduled tasks | task name                                         | the Schedule page                |
| Traces          | the exact trace id                                | the trace                        |

Use the arrow keys and `Enter`, or click a result.

## Search syntax

The same rules apply to the palette and every search field (issues, routes, queries
and users):

- A term matches anywhere in the text, regardless of case: `checkout` finds
  `/api/checkout/{order}`.
- `*` stands for any number of characters: `/api/*/refund` finds
  `/api/orders/{order}/refund`, and `App\Jobs\*Invoice` finds `App\Jobs\SendInvoice`.
- Every other character, `%` and `_` included, is searched for as itself.
