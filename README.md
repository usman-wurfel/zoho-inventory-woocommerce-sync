# Zoho Inventory ↔ WooCommerce Sync

WordPress / WooCommerce plugin for **two-way sync** with **Zoho Inventory** — products/stock, contacts/customers, and invoices/orders — via OAuth, bulk AJAX sync, auto-hooks, and Zoho webhooks.

**Live store:** [skylerrubber.com.au](https://skylerrubber.com.au)

---

## What it is

A custom **Zoho Inventory ↔ WooCommerce** integration plugin (not Zoho’s native connector):

- Connect Zoho Inventory with OAuth (multi-region accounts)
- Sync **items ↔ products**, **contacts ↔ customers**, **invoices ↔ orders**
- Run **manual bulk sync** (both directions) with progress UI
- Enable **auto-sync** when WooCommerce data changes
- Receive **Zoho webhooks** for near real-time Zoho → Woo updates
- Configure **field mapping**, webhook secrets, and detailed **sync logs**

---

## Sync directions

| Entity | WooCommerce | Zoho Inventory | Directions |
|--------|-------------|----------------|------------|
| Catalog / stock | Products | Items | Zoho → Woo **and** Woo → Zoho |
| People | Customers (incl. guests where applicable) | Contacts | Both ways |
| Commerce | Orders | Invoices | Both ways |

Manual sync pages support direction toggle + optional date range (contacts / invoices).

---

## Feature highlights

### 1) OAuth & connection
- Zoho OAuth connect / disconnect from WP admin
- Access + refresh token handling with auto-refresh on `401` / auth errors
- Multi-region token endpoints (`.com` / `.in` / `.eu` / `.com.au`)
- Organization picker via Zoho Inventory organizations API

### 2) Dashboard
- Connection status
- Counts for synced products, contacts / customers, invoices / orders
- Quick links into Zoho Inventory app

### 3) Field mapping (products / items)
Toggle which fields sync, including:
- Name, SKU, price, cost price, stock
- Image, description
- Reorder point, weight, dimensions, tax
- UPC / EAN / ISBN / MPN
- Brand, manufacturer

### 4) Inventory / product sync
- Bulk sync with AJAX progress bar (batch offset)
- Create / update products mapped by `_zoho_item_id` (and SKU matching flows)
- Direction: **Zoho → Woo** or **Woo → Zoho**
- Auto-sync on Woo product create / update / stock change / delete
- Circular-sync guards (webhook update locks + sync locks)

### 5) Contact / customer sync
- Woo customers ↔ Zoho contacts (registered + guest handling)
- Bulk sync both directions with date filters
- Auto-sync on user/customer create / update / delete
- Zoho contact webhooks (create/update + delete endpoints)

### 6) Invoice / order sync
- Woo orders ↔ Zoho invoices
- Bulk sync both directions with date filters
- Auto-sync on checkout / order update / delete (HPOS-aware paths)
- Zoho invoice webhooks (create/update + delete)

### 7) Zoho → Woo webhooks (REST)
Secret-protected REST routes under `zoho-inventory/v1`:

| Endpoint | Purpose |
|----------|---------|
| `/webhook-items` | Item create/update |
| `/webhook-items-delete` | Item delete |
| `/webhook-contacts` | Contact create/update |
| `/webhook-contacts-delete` | Contact delete |
| `/webhook-invoices` | Invoice create/update |
| `/webhook-invoices-delete` | Invoice delete |

### 8) Logs & admin UX
- Sync logs (created / updated / pending SKUs + reasons)
- Detailed webhook / sync activity logging
- Admin pages: Dashboard, Field Mapping, Inventory Sync, Contact Sync, Invoice Sync, Logs

---

## Admin pages

- **Zoho Sync → Dashboard**
- **Field Mapping**
- **Inventory Sync**
- **Contact Sync**
- **Invoice Sync**
- **Logs**

---

## Tech stack

| Layer | Technology |
|-------|------------|
| Platform | **WordPress** + **WooCommerce** |
| Integration | **Zoho Inventory API** (OAuth 2.0) |
| Realtime | WP REST webhooks + WooCommerce hooks |
| UI | WP Admin + AJAX progress (`js/zoho-sync.js`) |
| Data links | Post/user/order meta (`_zoho_item_id`, `_zoho_contact_id`, `_zoho_invoice_id`, org id) |

---

## Install (high level)

1. Copy plugin folder into `wp-content/plugins/`
2. Activate **Zoho Inventory WooCommerce Sync**
3. Open **Zoho Sync** in WP admin → connect OAuth → select organization
4. Configure field mapping + webhook secrets in Zoho Inventory automation
5. Run inventory / contact / invoice sync as needed; enable auto-sync toggles

> Portfolio / case-study snapshot. Do not commit live OAuth client secrets or tokens.
