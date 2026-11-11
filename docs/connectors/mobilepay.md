# Vipps MobilePay

Connect a project to a [Vipps MobilePay](https://vippsmobilepay.com) ledger, and Lumberjack
shows its revenue, transactions, refunds, fees and payouts on a page of its own and offers the
figures to screen [widgets](../widgets.md) as [data sources](../data-sources.md).

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

Lumberjack keeps a closed day's figures for 30 days and asks for an open day again after 10
minutes at most.

## Connecting

Team owners and admins connect Vipps MobilePay from **Data sources**, under **Connectors**, or
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

| Figure       | What it counts                                                               |
| ------------ | ---------------------------------------------------------------------------- |
| Revenue      | The sum of the captures: what customers paid, before refunds and fees.       |
| Transactions | How many captures. A payment captured in parts counts each part.             |
| Refunds      | The sum of the refunds.                                                      |
| Fees         | The capture fees and service fees charged, retained or invoiced.             |
| Payouts      | What was scheduled to be paid out to the bank account, less payouts aborted. |

Amounts are in the ledger's currency, such as NOK, DKK or EUR. Below, a chart shows the revenue
of each day.

A payout normally closes a day, so a day's payout is often a day's revenue less refunds and
fees, but it need not be: a weekly or monthly payout, a negative balance carried over or a
rolling reserve all move it. A capture fee is counted on the day it is booked, which is usually,
but not always, the day of its capture.

## Data sources

A Vipps MobilePay connection offers these sources, filed under **Vipps MobilePay** and the
connection's name in the designer's **Data source** tree. Their names carry the ledger's
currency, such as **Revenue (NOK)**. They are read from Vipps MobilePay only while a screen
shows them, like an app's [connected sources](../data-sources.md#connected-sources).

| Source                          | Shape  |
| ------------------------------- | ------ |
| Revenue, refunds, fees, payouts | number |
| Transactions                    | number |
| Revenue per day                 | series |

Amounts are whole units with two decimals, such as `849.5` for 849,50 kr. Give the widget a
**Custom** unit, such as `kr` or `€`, to write the currency beside them.

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
