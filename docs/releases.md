# Releases

Every request, error and issue carries the release it ran on: the SDK
resolves it (its docs describe how), and the
[browser script](./projects#browser-script) takes it from `data-release`. The
**Releases** page of a project puts the releases seen in the period side by side, newest
first, so you can tell which deploy broke something. The period and environment at the
top apply here too.

![The Releases page with five releases side by side](./screenshots/releases.png)

| Column      | Shows                                                                           |
| ----------- | ------------------------------------------------------------------------------- |
| First seen  | The first request or error on the release in the period.                        |
| Last seen   | The last request or error on it in the period.                                  |
| Requests    | Requests served by the release.                                                 |
| 5xx rate    | The share of those requests that ended in a server error.                       |
| p95         | The 95th percentile response time.                                              |
| Errors      | Occurrences of any issue on the release.                                        |
| New issues  | Issues first seen in the period whose earliest occurrence carried this release. |
| Regressions | Resolved issues that came back with an occurrence on this release.              |

Next to the 5xx rate and p95 an arrow shows the change from the release before it: red
and up when it got worse, green and down when it got better. A red arrow without a
percent means the release has server errors where the one before had none.

Click a release for the new issues it brought. **Issues last seen in this release** opens
the issues list filtered by the release.

A new issue belongs to the release of its earliest occurrence, not to whatever release
was deployed when it was first seen: during a rolling deploy, or with several
environments, two releases run at once. With an environment picked, it is the earliest
occurrence in that environment.
