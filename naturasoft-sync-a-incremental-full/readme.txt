=== Naturasoft Sync A (Incremental, HPOS-safe, Naturasoft item import) ===
Contributors: you
Tags: woocommerce, naturasoft, xml, export, token, batch, hpos
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.8.0
License: GPLv2 or later

Incrementális végpontok (HPOS-kompatibilis):
- csak a még nem exportált rendelések kerülnek ki
- export után CRUD-API-val elmentjük az _nsa_exported metát

Végpontok:
- GET /wp-json/nsync-a/v1/pull-batch-xml?token=TOKEN[&limit=50]
- GET /wp-json/nsync-a/v1/pull-next-xml?token=TOKEN
- GET /wp-json/nsync-a/v1/order-xml?order_id=ID&token=TOKEN
- GET /wp-json/nsync-a/v1/debug-export-flags?limit=10 (admin)

Beállítások:
- Rolling státusz-szűrő, Batch limit, Exportált jelzők törlése gomb.

== Újdonságok ==
= 0.8.0 =
- Új: Naturasoft Termékimport (XLSX) admin oldal
- XLSX / XLS import PhpSpreadsheet-tel
- SKU alapján frissít, új terméket létrehoz
- Árak, készlet, kategória, képek, leírás támogatott
- Képek automatikus letöltése URL-ből
