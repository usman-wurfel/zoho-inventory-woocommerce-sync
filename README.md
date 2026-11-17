# Zoho Inventory ↔ WooCommerce Sync

Custom WordPress plugin that keeps **Zoho Inventory** and **WooCommerce** in sync — products/stock, customers/contacts, and orders/invoices — in **both directions**, in near real time.

---

## Why this is useful

Most stores either:

- enter the same product / stock / order data **twice** (Zoho + Woo), or
- rely on Zoho’s native Woo connector, which is mostly **one-way / limited**, or
- duct-tape Zapier/Flow jobs that break when payloads change

This plugin closes that gap for a WooCommerce shop that also runs operations in Zoho Inventory:

| Pain | What the plugin does |
|------|----------------------|
| Stock wrong on website → overselling | Zoho item stock changes push to Woo via **webhooks**; Woo stock changes push back via **auto-sync** |
| Catalog maintained in Zoho, store looks stale | Bulk **Zoho → Woo** product sync + field mapping (price, image, SKU, barcodes…) |
| Orders live in Woo, finance lives in Zoho | Woo orders sync to **Zoho invoices**; Zoho invoice updates can flow back |
| Customers duplicated / mismatched | Woo customers ↔ Zoho contacts (create / update / delete both ways) |
| Manual CSV hell at scale | AJAX **batch sync** with progress UI + date filters |
| Silent failures | Detailed **sync + webhook logs** (created / updated / pending + reason) |
| Circular update loops | Webhook locks + sync locks so Zoho→Woo and Woo→Zoho don’t fight each other |

**Bottom line:** one source of operational truth across storefront + inventory ERP — fewer mistakes, less admin time, faster order→invoice flow.

---

## Architecture (simple)

```
WooCommerce (store)  ←→  Plugin (WP Admin + REST)  ←→  Zoho Inventory API
        │                         │
   auto-hooks              OAuth + webhooks
 (product/order/user)     /wp-json/zoho-inventory/v1/*
```

- **Woo → Zoho:** product / order / customer hooks + scheduled single-entity sync
- **Zoho → Woo:** Zoho Inventory automation webhooks (items, contacts, invoices)
- **Manual:** admin pages for bulk inventory / contact / invoice sync either direction

---

## What syncs

| Entity | WooCommerce | Zoho Inventory | Directions |
|--------|-------------|----------------|------------|
| Catalog / stock | Products | Items | Zoho → Woo **and** Woo → Zoho |
| People | Customers | Contacts | Both ways |
| Commerce | Orders | Invoices | Both ways |

---

## Feature highlights

### 1) OAuth connection
- Connect / disconnect Zoho from WP admin
- Access + refresh tokens with auto-refresh on auth failures
- Multi-region Zoho accounts (`.com` / `.in` / `.eu` / `.com.au`)
- Organization selection via Inventory organizations API

### 2) Dashboard
- Connection status
- Synced product / contact / invoice counts
- Deep links into Zoho Inventory records

### 3) Field mapping (why catalogs stay consistent)
Toggle per-field sync for items ↔ products:
- Name, SKU, selling price, cost price, stock
- Image, description
- Reorder point, weight, dimensions, tax
- UPC / EAN / ISBN / MPN
- Brand, manufacturer

### 4) Inventory sync
- Bulk sync with AJAX progress (batched offsets)
- Direction toggle: Zoho → Woo or Woo → Zoho
- Mapping via `_zoho_item_id` (+ SKU matching flows)
- Auto-sync on Woo product create / update / stock change / delete
- Circular-sync protection (webhook + sync locks)

### 5) Contact sync
- Woo customers ↔ Zoho contacts (registered + guest handling)
- Bulk both directions + date range
- Auto-sync on user/customer create / update / delete
- Zoho contact webhooks (create/update + delete)

### 6) Invoice / order sync
- Woo orders ↔ Zoho invoices
- Bulk both directions + date range
- Auto-sync on checkout / order update / delete (HPOS-aware)
- Zoho invoice webhooks (create/update + delete)

### 7) Zoho → Woo webhooks (REST)
Secret-protected routes under `zoho-inventory/v1`:

| Endpoint | Purpose |
|----------|---------|
| `/webhook-items` | Item create/update |
| `/webhook-items-delete` | Item delete |
| `/webhook-contacts` | Contact create/update |
| `/webhook-contacts-delete` | Contact delete |
| `/webhook-invoices` | Invoice create/update |
| `/webhook-invoices-delete` | Invoice delete |

### 8) Logs
- Sync logs: created / updated / pending SKUs + failure reasons
- Webhook activity timestamps + payloads for debugging
- Admin: Dashboard · Field Mapping · Inventory · Contacts · Invoices · Logs

---

## Tech stack

| Layer | Technology |
|-------|------------|
| Platform | **WordPress** + **WooCommerce** |
| Integration | **Zoho Inventory API** (OAuth 2.0) |
| Realtime | WP REST webhooks + WooCommerce hooks |
| UI | WP Admin + AJAX progress (`js/zoho-sync.js`) |
| Links | Meta keys `_zoho_item_id`, `_zoho_contact_id`, `_zoho_invoice_id`, org id |

---

## Install (high level)

1. Copy plugin into `wp-content/plugins/` and activate
2. **Zoho Sync** → connect OAuth → pick organization
3. Set field mapping + Zoho webhook secrets / endpoints
4. Run inventory / contact / invoice sync; enable auto-sync toggles
