# Activity Log — board 2026-10-31

Every activity on the platform is written to `activity_logs` and read by Head Office on
**System → Activity Log** (super admin / HQ staff only). The table toolbar exports the
filtered rows as CSV / Excel / PDF / Print. Rows are kept for one year (`activity:prune`,
scheduled monthly).

## What is logged

| Category (filter value) | What lands here | How |
|---|---|---|
| Sales & billing (`sales`) | invoice billed / cancelled, invoice + customer rows | `SalesService`, `SalesCancelService`, model events |
| Contracts & settlements (`contract`) | bond, member contract, settlement rows | model events |
| Gold Saving collections (`gold_saving`) | instalments, auto-debit mandates | model events |
| Stock QR & redemptions (`redemption`) | stock QR minted / redeemed / cancelled, redemption invoices | model events |
| Commissions & payouts (`commission`) | ledger lines, dealer margins, CBC coupons, payout statements (status changes = approvals) | model events |
| Wallet & withdrawals (`wallet`) | wallet balances, withdrawal requests / disbursals | model events |
| Digi Market (`digi`) | purchases, transactions, withdrawals, queue | model events |
| Stock, purchases & transfers (`stock`) | stock rows, movements, purchases, transfers, returns, opening-stock imports | model events |
| Order requests & customised orders (`orders`) | branch order requests, online orders | model events |
| Distributors & network (`network`) | member rows (profile, KYC flags, BV), e-pins, pincode auto-mapping | model events, `members:auto-map` |
| Branches & dealers (`branch`) | branch rows, supplier changes, staff / dealer logins (users), pincode auto-mapping | model events, `branches:auto-map` |
| Master data & settings (`master`) | plans, catalog, charge brackets, settings, push / WhatsApp / KYC settings (secrets hidden) | model events |
| Live rates (`rates`) | every rate row created / changed | model events |
| Logins & sessions (`auth`) | login, logout, failed login, lockout — panel and app; impersonation start / end | auth events |
| OTP (`otp`) | login OTP sent / verified / rejected, redemption OTP sent, OTP rows | `OtpService`, Redeem QR page, model events |
| Push, inbox & WhatsApp (`communication`) | **every** push + inbox notification (title, body, devices reached, stale tokens, failures) and every WhatsApp text / media send with the gateway answer | `Notifier`, `FcmChannel`, `WhatsappSender` |
| App requests (`app`) | every state-changing app request (POST / PUT / PATCH / DELETE): route, member, HTTP status, duration, input (secrets stripped) | `LogApiActivity` middleware |
| Support & tickets (`support`) | tickets, replies, contact messages | model events |
| Meetings & community (`meetings`) | meetings, attendance, memos | model events |
| Attendance & payroll (`payroll`) | attendance records, leave requests, payroll runs | model events |
| L-BOX devices (`lbox`) | devices (not heartbeats), device tokens, firmware, branch open / close | model events |
| Monthly tasks (`tasks`) | scores, submissions | model events |
| System jobs & imports (`system`) | console jobs (engine, tasks, imports, mappers, pushes …) with arguments and exit code | console events |

Each row carries: when, category, action, status (ok / failed / denied), channel (push,
inbox, whatsapp, api, panel, console), summary, who (admin / dealer login, distributor,
L-BOX, system), the affected distributor and branch, the subject record, details (JSON:
changed fields old → new, payloads, gateway answers), IP, user agent and a request id
that ties together everything one request did.

Secrets are never stored: passwords, keys, tokens, OTP codes, Aadhaar / PAN / bank
numbers are written as `[hidden]`; phone numbers appear masked (98xxxxxx82).

## Filters on the viewer

Category (multi), status, channel, actor type, distributor (search by name / code /
phone), branch, "action contains", date range (defaults to the last 7 days), plus the
free-text search over summary / action / actor / distributor code. "Details" opens the
full row.

## Volume

Bulk jobs (`legacy:rollout`, `legacy:import`, seeders) do not write per-row entries.
Heartbeat-only device updates and timestamp-only touches are skipped.
`php artisan activity:prune --days=365` removes older rows (scheduled on the 2nd of
each month).

## Hierarchy auto-mapping by pincode

- `php artisan branches:auto-map` — proposes each dealer's supplier: the nearest dealer
  of an allowed upper level in the **same pincode > same taluka > same district**
  (never just "same state"), preferring the level directly above; a supplier HQ already
  set that is at least as local stays; otherwise Head Office. `--apply` writes it (logged
  under Branches & dealers); `--only-unmapped` limits to dealers without a supplier.
- `php artisan members:auto-map` — maps distributors **without a branch** to the local
  counter (taluka / retailer / sub-dealer / wholesaler / area distributor) by the same
  locality rule; `--apply` writes, `--all` also re-maps distributors that already have a
  billing branch (use with care: branch scoping of their bills follows it).
- Both need the pincode master (`pincodes:import`) and branch / distributor pincodes
  (`branches:pincodes --fill`).
