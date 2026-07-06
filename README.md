# ReceiptScanner (MediaWiki extension)

OCRs uploaded receipts via the `receipt-scanner` sidecar, pre-fills a
PageForms form for user review, and stores the result in Cargo. This
is the MediaWiki half of the ReceiptScanner project; the Python
sidecar lives in the [ReceiptScannerApp repository](https://github.com/amethyst-ck/ReceiptScannerApp).

## Requirements

- MediaWiki >= 1.43
- PageForms and Cargo
- A reachable `receipt-scanner` sidecar
- The MediaWiki job runner healthy (CanastaBase ships `mw_job_runner.sh`)
- Optional: PdfHandler for PDF page-1 thumbnails in the form preview.
  Ghostscript + poppler ship in the Canasta image; the operator
  the app's operator settings load PdfHandler.
  See the [ReceiptScannerApp README](https://github.com/amethyst-ck/ReceiptScannerApp) for the full setup.

## Install

This extension is the MediaWiki half of the **ReceiptScanner application**.
It expects some scaffolding around it: PageForms and Cargo as hard
dependencies, an OCR service speaking the `receipt-scanner` sidecar's
`/parse` protocol for uploads to be parsed, and wiki content — forms,
entry templates storing to the `Expenses`/`Income` Cargo tables, and
category vocabularies — for the review flow and ledger to work with.

The application ships a complete set of that scaffolding plus operator
settings, and the turnkey path is deploying the whole thing onto a fresh
Canasta instance with [Wicker](https://github.com/amethyst-ck/Wicker); see the
[ReceiptScannerApp README](https://github.com/amethyst-ck/ReceiptScannerApp). But the extension is equally a
foundation to build on: its special pages, queue machinery, namespaces, and
parser functions work against whatever forms, templates, and parsing
backend you supply in place of the shipped ones.

To integrate it into an existing MediaWiki by hand, stand up the scaffolding
yourself:

1. Copy `extensions/ReceiptScanner/` into the wiki's `extensions/` directory
   as `ReceiptScanner/`.
2. `wfLoadExtension( 'ReceiptScanner' );` in `LocalSettings.php` (PageForms and
   Cargo must already be loaded).
3. `php maintenance/update.php` to create the `receipt_scanner_queue` table.
4. Deploy the `receipt-scanner` sidecar (see
   the [sidecar README](https://github.com/amethyst-ck/ReceiptScannerApp/tree/main/sidecars/receipt-scanner))
   and set `$wgReceiptScannerSidecarUrl` to its base URL — without a reachable
   sidecar, uploads queue but never parse.
5. `$wgRestrictDisplayTitle = false;` — required so each Expense / Income page
   can show a human-readable display title (date, party, amount) instead of
   its 9-digit page name.
6. For HEIC receipts, set `$wgMediaHandlers['image/heic']` and
   `$wgMediaHandlers['image/heif']` to
   `MediaWiki\Extension\ReceiptScanner\HeicHandler::class` and add `heic` to
   `$wgFileExtensions` — mirrors the operator settings in
   [ReceiptScannerApp](https://github.com/amethyst-ck/ReceiptScannerApp).
7. Install the starter wiki content (forms, templates, categories, help); see
   the [ReceiptScannerApp content directory](https://github.com/amethyst-ck/ReceiptScannerApp/tree/main/content).

## Special pages

| Page | Purpose |
|---|---|
| `Special:UploadReceipt` | Single + bulk upload; enqueues each file. |
| `Special:ReceiptReview` | Triage queue (Pending / Processing / Ready / Failed). Ready rows: Toggle, Reprocess, Review-in-form, Dismiss. Failed rows: Retry. (Consumed is an internal terminal state, not displayed.) |
| `Special:Ledger` | Combined Expenses + Income view with filters, per-category / per-month rollups, CSV export, printable summary view, and bulk category/assignee/party edits. |
| `Special:UnlinkedFiles` | Files in the wiki not referenced by any Expense or Income page; one-click re-enqueue. |

One unlisted helper is wired up by the Clone button on the
Expense / Income forms (see [`{{#receiptscanner_form_actions:}}`](#parser-functions)
below); not navigable from `Special:SpecialPages`:

| Page | Purpose |
|---|---|
| `Special:CloneReceiptEntry/<source>` | Reads the source Expense/Income page's template fields and redirects to `Special:FormEdit/<Form>` with everything (except `queue_id`) pre-populated. Used to split a receipt across multiple line items. |

Reprocessing a receipt (re-running the sidecar parse) is only
available before the entry is first saved — i.e. while it's a
Ready row on `Special:ReceiptReview`. After a human reviews and
saves an Expense / Income page, the page's stored values are the
source of truth; the form deliberately offers no "reprocess" path
because doing so would silently overwrite manual edits.

## Namespaces

The extension registers two custom namespaces and their talk pages at
extension-registration time:

| Constant | Default index | Default label |
|---|---|---|
| `NS_RECEIPTSCANNER_EXPENSE` | 3000 | `Expense` |
| `NS_RECEIPTSCANNER_EXPENSE_TALK` | 3001 | `Expense_talk` |
| `NS_RECEIPTSCANNER_INCOME` | 3002 | `Income` |
| `NS_RECEIPTSCANNER_INCOME_TALK` | 3003 | `Income_talk` |

Both content namespaces are added to `$wgContentNamespaces`.

To shift the index range, set `$wgReceiptScannerNamespaceIndex`
**before** `wfLoadExtension( 'ReceiptScanner' )` in
`LocalSettings.php`. To rename a namespace, set the entry in
`$wgExtraNamespaces` before extension load.

## Parser functions

| Function | Purpose |
|---|---|
| `{{#receiptscanner_categories: kind \| separator }}` | Flat category vocabulary for `kind` (`expense` or `income`). |
| `{{#receiptscanner_users: separator }}` | Login names of non-system, non-bot users (for assignee picker). |
| `{{#receiptscanner_currency_symbol: code }}` | ISO 4217 → display symbol (`USD` → `$`). Unknown codes pass through. |
| `{{#receiptscanner_format_amount: amount \| code }}` | Accounting-style display amount: symbol, thousands separators, two decimals (`1234.5`, `USD` → `$1,234.50`; negatives in parentheses). `code` defaults to the system currency; non-numeric `amount` passes through. |
| `{{#receiptscanner_system_currency: }}` | Wiki's accounting currency from `$wgReceiptScannerSystemCurrency`. |
| `{{#receiptscanner_truncate: str \| max \| suffix }}` | Multibyte-safe length cap, used in `DISPLAYTITLE`. |
| `{{#receiptscanner_dashboard: }}` | The 6-tile launcher grid (Upload / New expense / New income / Review / Ledger / Unlinked files) embedded by `{{Receipt dashboard}}` on the Main_Page. |
| `{{#receiptscanner_form_actions: }}` | Clone button rendered next to the file field on `Form:Expense` / `Form:Income` when editing an existing entry. Returns the empty string on create-mode forms (no source to clone from). |
| `{{#receiptscanner_file_url: filename }}` | "Click through to view" URL for a receipt file. Returns `Special:FilePath/<filename>` for browser-renderable formats; for HEIC / HEIF, appends `?width=1500` so the link routes through the thumbnailer and the new tab gets a JPEG instead of a download. |

## Configuration

| Setting | Default | Purpose |
|---|---|---|
| `$wgReceiptScannerSidecarUrl` | `http://receipt-scanner:8000` | Sidecar base URL. |
| `$wgReceiptScannerSidecarTimeout` | `15` | HTTP timeout (seconds). |
| `$wgReceiptScannerSidecarSecret` | `""` | Shared HMAC secret. When non-empty the extension signs each `/parse` request body with HMAC-SHA256 and the sidecar rejects mismatching requests with 401. The sidecar must see the same value in its `RECEIPT_SCANNER_SHARED_SECRET` env var. Leave empty when the docker network boundary is the only control. |
| `$wgReceiptScannerSystemCurrency` | `USD` | ISO 4217 code of the wiki's accounting currency; rollups and ledger totals use it. |
| `$wgReceiptScannerNamespaceIndex` | `3000` | Base index for the Expense / Income namespaces (see Namespaces above). |
| `$wgReceiptScannerExpenseCategoryPage` | `Project:Expense categories` | Wiki page defining the expense category vocabulary. |
| `$wgReceiptScannerIncomeCategoryPage` | `Project:Income categories` | Wiki page defining the income category vocabulary. |

## Recommended companion extensions

Optional, but each one adds polish that pairs naturally with the
extension. None are required; the extension works without them.

### [DisplayTitle](https://www.mediawiki.org/wiki/Extension:Display_Title) (bundled with Canasta)

Lets `{{DISPLAYTITLE:…}}` show a date / party / amount instead of the
random 9-digit page name on every Expense / Income page (Templates
already emit the magic word). Needs two core knobs set:

```php
$wgAllowDisplayTitle = true;
$wgRestrictDisplayTitle = false;
```

`$wgRestrictDisplayTitle = false;` is already required by ReceiptScanner
(see the Install section); `$wgAllowDisplayTitle` defaults to true in MW
core but DisplayTitle re-checks it.

### [TitleIcon](https://www.mediawiki.org/wiki/Extension:TitleIcon) (bundled with Canasta)

Adds a small icon next to the heading on Expense / Income pages. The
starter content already ships
`{{#titleicon_unicode:🧾}}` on `Category:Expenses` and
`{{#titleicon_unicode:💵}}` on `Category:Income` — TitleIcon
propagates each icon to every page in the corresponding category
automatically. Change the emoji on the category pages to retheme.

No `$wg` config needed.

### [CreateUserPage](https://www.mediawiki.org/wiki/Extension:Create_User_Page)

Auto-creates `User:<name>` on first login. Pair with the
`{{User receipts}}` template (also in the starter content) to give
each user a personalised expense / income summary on their own page:

```php
wfLoadExtension( 'CreateUserPage' );
$wgCreateUserPage_PageContent = '{{User receipts}}';
```

Not bundled with Canasta — `git clone
https://github.com/wikimedia/mediawiki-extensions-CreateUserPage` into
the wiki's `extensions/` (or `user-extensions/` on Canasta).

## Tests

PHPUnit tests live under `tests/phpunit/{unit,integration}/`. Both
suites run in CI (see `.github/workflows/ci.yml`).

The **unit** suite runs against a MediaWiki checkout with dev
dependencies (`composer install` in the MediaWiki root):

```
vendor/bin/phpunit -c extensions/ReceiptScanner/tests/phpunit/phpunit.xml.dist --testdox --testsuite unit
```

The **integration** suite (`@group Database`) needs a real MediaWiki
install + database; run it with MediaWiki's standard runner:

```
php tests/phpunit/phpunit.php extensions/ReceiptScanner/tests/phpunit/integration/
```

The extension-local `phpunit.xml.dist` + `bootstrap.php` provide a
convenient way to run the unit suite in isolation.
