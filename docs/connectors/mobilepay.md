# Vipps MobilePay

Connect a project to a [Vipps MobilePay](https://vippsmobilepay.com) ledger, and Lumberjack
shows its revenue, transactions, refunds, fees, payouts and balance, its latest transactions
and largest sales, and when in the day and week it sells, on a page of its own and offers them to
screen [widgets](../widgets.md) as [data sources](../data-sources.md).

Lumberjack only reads Vipps MobilePay, through its
[Report API](https://developer.vippsmobilepay.com/docs/APIs/report-api/), which is read-only.
The Report API has no test environment, so it is always the production API at
`https://api.vipps.no`.

## Booked, not live

The Report API reports what Vipps MobilePay has booked on the ledger, per **ledger date**, not
live sales. A ledger date normally runs from midnight to midnight in the merchant's time zone,
and stays open until then:

- Today's figures can be empty or incomplete until Vipps MobilePay has booked them, and are
  final once the day closes, usually within minutes of midnight.
- A closed day never changes; a correction is booked as a new entry on a later day.

Lumberjack keeps what it needs of a closed day for 30 days and asks for an open day again after
10 minutes at most: the day's figures, its sales per hour and per sales unit, its 200 latest
sales and refunds, its 200 largest sales and its payouts. Days it does not have yet are asked for
ten at a time. Each entry Lumberjack reads carries an order reference, an amount, a time, a sales
unit and a balance; no customer's personal data.

## Connecting

Team owners and admins connect Vipps MobilePay from the project's **Connectors** page, or
from the **Vipps MobilePay** page of a project that has none yet.

1. In the business portal, choose **For developers**,
   then **Production**, and **Show keys** beside the sales unit.
2. In Lumberjack, choose **Connect** and give:

    | Field                      | What it is                                                            |
    | -------------------------- | --------------------------------------------------------------------- |
    | **Merchant serial number** | The sales unit's number (MSN), shown beside its keys.                 |
    | **Client ID**              | `client_id`.                                                          |
    | **Client secret**          | `client_secret`.                                                      |
    | **Subscription key**       | `Ocp-Apim-Subscription-Key`; the primary and the secondary both work. |
    | **Days begin in**          | The time zone the ledger's days run in, normally the merchant's own.  |

3. Choose **Connect**. Lumberjack asks for an access token with the keys and looks up the
   ledger the sales unit settles to, and says on the client secret if Vipps MobilePay refuses
   the keys or the keys see no ledger for that number.

The connection is named after the sales unit, and keeps the ledger's ID and currency. The keys
are stored encrypted, never shown again, and only ever sent to Vipps MobilePay. The access
token they get lasts 24 hours and is reused until shortly before it expires.

A project reads as many ledgers as you connect, one connection each. Connecting the keys of a
sales unit whose ledger it already reads renews that connection's keys. With more than one, a
picker beside the title switches between them. Under the page's **Connection** tab, for team
owners and admins:

- **Connect another ledger** opens the connect dialog.
- **Disconnect** removes this connection and its data sources.

## The Vipps MobilePay page

Once connected, **Vipps MobilePay** shows in the project's sidebar. The ledger is booked per day,
so the page offers 1, 7 or 30 days, today included, and no hour. For the period it shows, each
against as many days before:

| Figure        | What it counts                                                               |
| ------------- | ---------------------------------------------------------------------------- |
| Revenue       | The sum of the captures: what customers paid, before refunds and fees.       |
| Transactions  | How many captures. A payment captured in parts counts each part.             |
| Refunds       | The sum of the refunds.                                                      |
| Fees          | The capture fees and service fees charged, retained or invoiced.             |
| Payouts       | What was scheduled to be paid out to the bank account, less payouts aborted. |
| Average order | Revenue per transaction.                                                     |
| Refund rate   | Refunds as a percentage of the revenue.                                      |
| Fee rate      | Fees as a percentage of the revenue.                                         |

Amounts are in the ledger's currency, such as NOK, DKK or EUR. Beside them, **Balance** is the
ledger's balance now, see [The balance](#the-balance). Below:

- a chart of the revenue of each day;
- **Sales by hour of day** and **Sales by weekday**: how many captures fell in each hour and on
  each weekday of the period, in the connection's time zone, with their revenue on hover;
- **Latest transactions**: the period's sales and refunds, the newest first, with their time,
  amount and order reference;
- **Largest sales**: the period's largest captures;
- **Payouts**: each payout of the period by its ledger date;
- **Revenue per sales unit**: the captures of each sales unit settling to the ledger, which says
  most when the ledger has more than one.

The latest transactions and the payouts show 20 rows, and the largest sales 10; **Load more**
shows as many again, up to 200 (100 for the largest sales), without asking Vipps MobilePay again.

### The balance

Each ledger entry carries the ledger's balance after it: what Vipps MobilePay holds of the
merchant's money at that moment, that is, booked but not yet paid out. **Balance** is the balance
after the latest entry of the last 7 days, so it is the money still to be paid out. A day
normally ends with a payout of the whole balance, so it is usually 0 right after a payout and
grows through the day with the captures less refunds and fees. It can be negative when refunds
and fees exceed the captures, which carries over to the next day, and it stays above 0 when the
contract keeps a rolling reserve or pays out weekly or monthly. It shows as `-` when the last 7
days have no entries.

A payout normally closes a day, so a day's payout is often a day's revenue less refunds and
fees, but it need not be: a weekly or monthly payout, a negative balance carried over or a
rolling reserve all move it. A capture fee is counted on the day it is booked, which is usually,
but not always, the day of its capture.

## Data sources

A Vipps MobilePay connection offers these sources, filed under **Vipps MobilePay** and the
connection's name in the designer's **Data source** tree. Their names carry the ledger's
currency, such as **Revenue (NOK)**. They are read from Vipps MobilePay only while a screen
shows them, like an app's [connected sources](../data-sources.md#connected-sources).

| Source                          | Key                                     | Shape  | Notes                                                               |
| ------------------------------- | --------------------------------------- | ------ | ------------------------------------------------------------------- |
| Revenue, refunds, fees, payouts | `revenue`, `refunds`, `fees`, `payouts` | number |                                                                     |
| Transactions                    | `transactions`                          | number |                                                                     |
| Average order value             | `average_order`                         | number | Revenue per transaction; empty without sales.                       |
| Refund rate (%)                 | `refund_rate`                           | number | Refunds as a percentage of revenue, one decimal.                    |
| Fee rate (%)                    | `fee_rate`                              | number | Fees as a percentage of revenue, one decimal.                       |
| Balance to be paid out          | `balance`                               | number | As it is now, whatever the period; see [The balance](#the-balance). |
| Revenue per day                 | `revenue.series`                        | series |                                                                     |
| Latest transactions             | `latest_transactions`                   | list   | 10 rows: time, Sale or Refund and reference; the amount.            |
| Largest sales                   | `largest_sales`                         | list   | 10 rows: time and reference; the amount.                            |
| Latest payouts                  | `payout_list`                           | list   | 10 rows: ledger date; the amount.                                   |
| Sales by hour of day            | `sales_by_hour`                         | list   | 24 rows, `00:00` to `23:00`; the number of sales.                   |
| Sales by weekday                | `sales_by_weekday`                      | list   | 7 rows, Monday first; the number of sales.                          |
| Revenue per sales unit          | `revenue_by_unit`                       | list   | Up to 10 sales units, such as `DK:1234567`, the most first.         |

Amounts are whole units with two decimals, such as `849.5` for 849,50 kr; a refund in the latest
transactions is negative. Give the widget a **Custom** unit, such as `kr` or `€`, to write the
currency beside them. The rates have no unit of their own; give them `%`. Hours, weekdays and
times are in the connection's time zone. Lumberjack counts sales per whole hour of UTC, so in a
time zone off UTC by half an hour a sale can land in the hour next to its own.

The ledger is booked per day, so a widget showing a Vipps MobilePay source offers 24 hours, 7
days or 30 days, each read as that many ledger dates, today included: 24 hours is today so far.
A widget left on an hour, such as by the screen's own period, shows today and says **24h** in
its corner. A series has a point per day whatever the chart's resolution.

## When Vipps MobilePay cannot be read

The connection and the Vipps MobilePay page say why:

| Error                                                     | What to do                                                                                                |
| --------------------------------------------------------- | --------------------------------------------------------------------------------------------------------- |
| Vipps MobilePay refused the API keys                      | Check the keys and merchant serial number in the business portal; regenerated keys need connecting again. |
| The API keys may not read the Report API                  | Check the sales unit's access in the business portal.                                                     |
| The API keys see no ledger for merchant serial number ... | Check the number: it is the sales unit's, shown beside its keys.                                          |
| Vipps MobilePay no longer finds the ledger                | Connect it again.                                                                                         |
| The Vipps MobilePay keys cannot be decrypted              | `APP_KEY` changed. Connect Vipps MobilePay again.                                                         |
