# Lord ICL — Platform Rebuild
## Status & Full-Flow Report

**Prepared for:** Client / Stakeholders
**Date:** 11 June 2026
**System:** Lord ICL Jewellery MLM Platform (next-generation rebuild)

---

## 1. Executive Summary

The legacy Lord ICL application (built on CodeIgniter 3) is being rebuilt on a modern,
secure, and maintainable technology stack — **Laravel 12 + Filament 3.3**. The new platform
("lordicl-next") preserves every business rule of the original system while fixing its
structural weaknesses (raw SQL, duplicated logic, no automated tests, no clear approval
controls).

The rebuild is **functionally comprehensive**: trade/billing, the complete 7-stream earning
model, the distributor supply-chain hierarchy, commission approval, digi-gold, e-commerce,
member network/genealogy, and the public website are all in place and working. The system is
backed by an **automated test suite of 81 tests / 413 assertions, all passing**, which
guarantees the money-handling logic behaves correctly on every change.

Key principles enforced throughout:

- **One source of truth for money** — every earning is a typed ledger record; nothing is
  credited to a wallet without an explicit admin approval.
- **Branch isolation** — a distributor logging in sees only their own branch's data.
- **Server-side pricing** — all amounts are recomputed on the server from the live metal
  rate and catalog; the browser is never trusted for money.
- **Indian operations** — the whole platform runs on IST (Asia/Kolkata); GST, TDS and
  service charges follow Indian norms.

---

## 2. What the Platform Does

Lord ICL is a jewellery savings + multi-level distribution business. Customers buy gold/silver
savings plans through a network of distributors organised in a strict hierarchy. The platform
manages:

- **Trade & billing** — point-of-sale invoicing, purchases, stock, RD (gold-saving) collection.
- **The MLM earning model** — seven distinct income streams for members and distributors.
- **The distributor supply chain** — stock flows down a 6-level hierarchy; commissions are
  earned on each hop.
- **Digi-gold** — redeemable gold/cash QR codes delivered to customers over WhatsApp.
- **E-commerce + website** — public storefront, CMS pages, blog, FAQ, community.

---

## 3. Technology & Architecture

| Layer | Choice | Why it matters to you |
|---|---|---|
| Framework | Laravel 12 (PHP 8.2) | Modern, long-term-supported, secure |
| Admin panel | Filament 3.3 | Fast, consistent back-office UI |
| Database | MySQL 8 | Reliable, transactional money handling |
| Access control | Role + permission engine | Clean separation of HQ vs distributor |
| Messaging | WhatsApp gateway integration | Instant contract + QR delivery |
| Quality | Automated test suite (PHPUnit) | Every release is regression-checked |
| Localisation | Multi-language + multi-currency core | Ready for scale beyond India |

All financial operations run inside **database transactions** — an action either completes
fully or not at all, so balances can never be left half-updated.

---

## 4. Roles & Access Model

There are two kinds of back-office login, and the sidebar is **aligned** so both see the same
groups in the same order — the distributor simply sees a clean subset:

**Head Office (Super-Admin / Admin)** — sees and manages everything: masters, plans, catalog,
all branches, commissions, approvals, website, system settings.

**Distributor (Branch "Semi-Admin")** — a dealer at one of the six levels who logs in scoped to
**their own branch only**. They see Trade (billing, order form, order requests, purchases,
stock, RD collection), their Sales & Bonds, and Digi-Gold — all filtered to their branch.

A super-admin can also use **"View as Dealer"** — an eye-button on the Branches list that opens
the back-office exactly as that branch's distributor would see it, for support and verification,
then returns to the admin session.

---

## 5. Module Inventory & Status

| Group | Module | Status |
|---|---|---|
| **Trade** | Sales (Billing), Order Form, Order Requests, Purchases, Stock, RD Collection | ✅ Working |
| **Trade** | Stock Transfers (ledger), Source-Change Requests | ✅ Working |
| **Sales & Bonds** | Bonds, E-Pins, Sales Invoices, RD Collections | ✅ Working |
| **Digi-Gold** | Redeemable Stock QR, Digi Orders, Digi Withdrawals, Digi Queue | ✅ Working |
| **Commissions & Payouts** | Commission Approval, Commission Ledger, Payout Statements | ✅ Working |
| **Network** | Members, Genealogy Tree, Ranks | ✅ Working |
| **Master** | Branches, Catalog, Plans, Stock-Transfer Margins, Charge Brackets, Staff, Vendors, MOU, Categories | ✅ Working |
| **Orders / Website / Community** | E-com Orders & Payments, CMS Pages, Blog, FAQ, Testimonials, Social | ✅ Working |
| **System** | Live Rates, Currencies, Languages, WhatsApp & Verification Settings | ✅ Working |
| **Withdrawals** | Member withdrawal from wallet | 🔜 Next phase (to be specified) |

---

## 6. The Earning Model — Seven Income Streams

This is the heart of the business. There are **seven** earning streams, split between the
member network and the distributor branches.

### Member network (3)

| # | Stream | When earned | Basis |
|---|---|---|---|
| 1 | **IC — Instant Commission** | Instantly at sale | Plan's IC schedule, paid up the genealogy chain (always 10 levels) |
| 2 | **CBC — Cash Back Coupon** | Monthly schedule | A coupon installment per active plan; split 40% E-Pin / 60% coupon |
| 3 | **Level (GAP) Commission** | Monthly batch | Plan's level schedule, paid to 5 uplines, gated by each upline's rank |

### Distributor / branch (4)

| # | Stream | When earned | Basis |
|---|---|---|---|
| 4 | **Billing Margin** | Instantly at billing | Plan's billing-margin % of the cross total → billing branch |
| 5 | **Gold Margin** | Instantly at billing | ₹/gram on gold line-items → billing branch |
| 6 | **Silver Margin** | Instantly at billing | ₹/gram on silver line-items → billing branch |
| 7 | **Stock-Transfer Margin** | At order fulfilment | Per-level % of goods value → the selling (source) branch |

**Important distinction:** "earned" and "paid" are two different events.

- A margin is **recorded** the moment it is earned (so books are accurate in real time).
- The amount is **released to a wallet only after admin approval** (see §7-B).

This separation is deliberate — it gives Head Office a single control point over all money
leaving the business, exactly as requested.

---

## 7. End-to-End Flows

### A. Sale / Billing Flow

```
Distributor bills a customer (Sales page)
        │
        ▼
One transaction creates:
  • Sales Invoice + line items
  • Customer record (if new) + Bond (the savings contract)
  • IC commission rows (10 levels up the chain)  ........... status: PENDING
  • CBC coupon schedule (cbc_entries)            ........... status: PENDING
  • Billing margin → billing branch balance      ........... recorded
  • Gold margin (gold lines) → branch balance     .......... recorded
  • Silver margin (silver lines) → branch balance .......... recorded
  • Redeemable Stock QR (for digi/redeemable plans)
        │
        ▼
Contract PDF + QR sent to the customer instantly over WhatsApp
```

Pricing for every line (metal value + making + wastage + hallmark + GST) is computed
server-side from the **live metal rate** and the catalog item — the cart is never trusted.

### B. Commission Lifecycle & Approval — the single wallet gate

All seven streams are recorded as separate, typed records when earned, but **no money reaches a
member's wallet until an admin approves it.**

```
Earning recorded (PENDING)
        │
        ▼
Head Office opens  Commissions & Payouts → Commission Approval
        │
        ├─ Filter by COMMISSION TYPE (dropdown of all 7)
        ├─ Filter by DATE RANGE
        │
        ▼
Filtered records listed, each with a checkbox
  (select individual rows, or select / deselect all)
        │
        ▼
Click  "Approve & credit wallet"
        │
        ▼
For each selected record:
  • marked PAID with a paid date
  • amount posted to the beneficiary's single wallet (by User ID)
      - IC / GAP  → cash balance
      - CBC       → 40% E-Pin + 60% coupon
      - Margins   → the branch's distributor member's wallet
```

The approval action is **idempotent** — approving an already-paid row does nothing, so there is
no risk of double payment.

### C. Supply Chain & Stock-Transfer Commission

The distributor network is a strict hierarchy. Each branch orders stock from exactly one branch
above it (its "source"), configured by Head Office on the Branches screen. A distributor can
request to change their source; Head Office approves it.

```
Lord (HQ / Super-Admin)        ← earns no stock-transfer margin
   └─ Zonal Director
        └─ District
             └─ Taluk
                  └─ Wholesaler
                       └─ Reseller
   Area Dealer  ← may source from any level above (HQ-assigned)
```

Stock transfer is **not** a standalone single-item entry — it is the **fulfilment of an order
request**, exactly like the existing branch-order system:

```
A downstream branch places a multi-item ORDER REQUEST with its source branch
        │
        ▼
The source branch approves / fulfils it
        │
        ▼
For every line:
  • goods added to the buyer's stock
  • goods deducted from the seller's stock
  • a Stock-Transfer record is logged (audit trail)
  • the SELLER earns the stock-transfer commission
        = goods value × (the % for the seller's level on that item)
```

The commission rate is a **rate card per catalog item**, with a separate percentage for each
seller level (Zonal / District / Taluk / Wholesaler / Reseller), editable under
**Master → Stock Transfer Margins**. The earned commission then flows through the same approval
gate in §7-B.

### D. Redeemable Stock QR + WhatsApp

```
Contract / Bond created for a redeemable plan
        │
        ▼
A unique Redeemable Stock QR is generated (gold-gram + cash worth)
        │
        ▼
Contract PDF + QR image sent to the customer over WhatsApp — instantly
        │
        ▼
Later: customer presents the QR at a branch → OTP verification → redemption
```

A built-in test-number safeguard lets the team route test messages to a single number so live
customers are never messaged during testing.

### E. Dealer Impersonation ("View as Dealer")

A super-admin can step into any branch's distributor session from the Branches list (eye
button), see the platform exactly as that dealer does, and return to their admin session — used
for support and verification without sharing passwords.

---

## 8. Data Integrity & Money Safety

- **Transactional** — every multi-step money operation runs in a database transaction.
- **Server-authoritative pricing** — amounts are recomputed from live rates + catalog; the
  browser cannot alter prices.
- **Approval gate** — wallets are credited only by an explicit admin approval; idempotent, so
  no double credit.
- **Branch isolation** — distributors can only see and act on their own branch's data.
- **Order limit** — a distributor's outstanding orders are capped at max(their business volume,
  their investment); Head Office is unlimited.
- **Audit trail** — IC/GAP/CBC, margins and stock transfers each leave a permanent, typed
  record with dates and references.

---

## 9. Quality Assurance

The platform is protected by an **automated test suite — 81 tests, 413 assertions, all
passing.** These cover the high-risk areas: instant commission and level/GAP qualification, CBC
splitting, the 7-stream earning logic, gold/silver margin at billing, stock-transfer commission
on order fulfilment, the commission-approval crediting rules, charge brackets / cash-to-gold
reversal, branch order limits, the storefront and checkout, and translations.

Every future change is run against this suite, so business rules cannot silently break.

---

## 10. What's Pending / Next Phase

| Item | Notes |
|---|---|
| **Withdrawals** | The wallet now accumulates approved earnings; the member/distributor withdrawal-request → approval → payout flow is the next module to specify and build. |
| **Stock-transfer rate cards** | Every catalog item has a rate card seeded at 0%; Head Office fills in the per-level percentages via the UI. |
| **Production WhatsApp media** | Media (QR/PDF) delivery is validated; final go-live uses the public production domain so the gateway can fetch media. |

---

## 11. Glossary

- **HQ / Lord** — Head Office; the top of the chain; earns no stock-transfer margin.
- **Distributor** — a branch dealer (Zonal → District → Taluk → Wholesaler → Reseller →
  Area Dealer) who logs in scoped to their branch.
- **Bond** — a customer's savings contract created at sale.
- **IC / GAP / CBC** — the three member income streams (instant, level, cashback).
- **Margin** — distributor earnings (billing, gold, silver, stock-transfer).
- **Wallet** — a member's single balance account (cash / coupon / E-Pin buckets), credited only
  on admin approval.
- **Live Rate** — the current per-gram gold/silver price used to value all transactions.

---

*This document reflects the platform state as of 11 June 2026. All flows described are
implemented and covered by the automated test suite.*
