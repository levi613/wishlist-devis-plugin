# Design Document: Devis Request Management

## Overview

Enhance the existing **Wishlist Devis Plugin** so the quote-request (`devis`) form collects full customer and address details, persists every request into a custom WordPress database table viewable from an admin screen, and assigns each customer a stable 4-digit reference number (keyed by email, incremented by 1 for each new email) that is shown both in the admin list and inside the outgoing email.

> **Implementation note:** `devis-functions.php` has now been read in full, and this design reflects the **real** existing implementation. The current AJAX handler is `wishlist_devis_send_email()`, hooked on `wp_ajax_send_devis` / `wp_ajax_nopriv_send_devis`. It decodes the JSON body `{ action, name, email, products: [{ id, name, img, quantity }] }`, builds a `.docx` quote via `wishlist_devis_generate_word($products, $data)`, and emails it (HTML body + plain-text fallback) to two hardcoded recipients using `wp_mail`. It responds with `wp_send_json(['message' => ...], $statusCode)` — a flat `message` plus optional HTTP status code, **not** a `{success, errors}` envelope. The design below **extends this existing handler in place** rather than introducing a new endpoint, and preserves the existing response shape, recipients, and `.docx`/Excel-pricing flow. Sections affected by the real code (handler name, response format, recipients, Word/email client-info block, field mapping) have been reconciled accordingly.

---

## Main Algorithm/Workflow

```mermaid
sequenceDiagram
    participant U as Visitor (browser)
    participant JS as wishlist-devis-plugin.js
    participant AJAX as wishlist_devis_send_email (action=send_devis)
    participant V as Validation/Sanitization
    participant REF as Reference Service
    participant DB as wp_wishlist_devis_requests
    participant MAIL as wp_mail + PHPWord

    U->>JS: Fills form, clicks "Envoyer"
    JS->>JS: Toggle company fields by customer_type
    JS->>JS: Client-side validation
    JS->>AJAX: POST JSON {customer fields, address, products[]}
    AJAX->>V: validate_submission(payload)
    alt invalid
        V-->>AJAX: errors
        AJAX-->>JS: wp_send_json({message, errors}, 400)
        JS-->>U: Show message / field errors
    else valid
        AJAX->>REF: get_or_create_reference(email)
        REF->>DB: SELECT reference WHERE email (locked)
        alt email known
            DB-->>REF: existing reference
        else new email
            REF->>DB: SELECT MAX(reference); insert next (zero-padded 4 digits)
            DB-->>REF: new reference
        end
        REF-->>AJAX: reference (e.g. "0007")
        AJAX->>AJAX: map full_name -> data['name'] (compat)
        AJAX->>DB: save_request(reference, customer, address, products, created_at)
        AJAX->>MAIL: existing email/docx flow (reference in message + .docx)
        MAIL-->>AJAX: sent (2 hardcoded recipients)
        AJAX-->>JS: wp_send_json({message incl. réf.}, 200)
        JS-->>U: Success message (réf. shown in message)
    end
```

---

## Architecture

The enhancement adds three logical components on top of the existing plugin without changing its delivery model (a single PHP plugin with a shortcode-rendered front-end form and an `admin-ajax.php` handler):

- **Submission pipeline** — the existing `wishlist_devis_send_email()` AJAX handler (hooked on `send_devis`) is **extended in place** to validate, sanitize, assign a reference, persist, then run its existing email/`.docx` flow. No new endpoint is introduced.
- **Reference service** — allocates and reuses 4-digit references keyed by email, backed by a dedicated table with a uniqueness constraint.
- **Admin viewer** — a `manage_options`-gated admin menu page that lists all persisted requests, reference-first.

The enriched front-end form is **not** a new surface: `wishlist_devis_shortcode()` (`[wishlist_devis]`) is modified in place so its existing `#devis-form` container renders the new fields **in place of** the current "Nom et prénom" (`#devis-name`) + "Email" (`#devis-email`) markup, keeping the same location, trigger button, and success/error elements (see Example Usage → "Updated shortcode form markup").

The high-level request flow is shown in the Main Algorithm/Workflow sequence diagram above. Data lives in two new custom tables (see Data Models); all other infrastructure (PHPWord `.docx` generation, `wp_mail`, jQuery front-end) is reused as-is.

## Components and Interfaces

```php
<?php
/**
 * Customer type enumeration.
 * 'particulier'   => individual (company_name + siret hidden/empty)
 * 'professionnel' => professional (company_name + siret expected)
 */
const WD_CUSTOMER_TYPE_INDIVIDUAL   = 'particulier';
const WD_CUSTOMER_TYPE_PROFESSIONAL = 'professionnel';

/**
 * Shape of a single wishlist product line (received from JS, unchanged).
 *
 * @typedef Product = array{
 *     id:       string,
 *     name:     string,
 *     img:      string,   // URL or 'N/A'
 *     quantity: int       // >= 1
 * }
 */

/**
 * Shape of the raw AJAX payload submitted by the front-end form.
 *
 * @typedef DevisPayload = array{
 *     customer_type: string,   // WD_CUSTOMER_TYPE_*
 *     company_name:  string,   // required iff professionnel
 *     siret:         string,   // required iff professionnel
 *     full_name:     string,   // required
 *     email:         string,   // required, valid email
 *     phone:         string,
 *     country:       string,
 *     postal_code:   string,
 *     city:          string,
 *     address:       string,   // street address
 *     products:      Product[] // non-empty
 * }
 */

/**
 * Persisted quote request record (one row of wp_wishlist_devis_requests).
 *
 * @typedef DevisRequest = array{
 *     id:            int,       // PK, auto-increment
 *     reference:     string,    // 4-digit zero-padded, e.g. "0042"
 *     customer_type: string,
 *     company_name:  string,
 *     siret:         string,
 *     full_name:     string,
 *     email:         string,
 *     phone:         string,
 *     country:       string,
 *     postal_code:   string,
 *     city:          string,
 *     address:       string,
 *     products:      string,    // JSON-encoded Product[]
 *     created_at:    string     // MySQL DATETIME (UTC)
 * }
 */

/**
 * Result of validation.
 *
 * @typedef ValidationResult = array{
 *     valid:  bool,
 *     errors: array<string,string>,  // field => message
 *     data:   DevisPayload|null      // sanitized payload when valid
 * }
 */
```

## Data Models

The two `@typedef` shapes above (`DevisRequest`, `DevisPayload`, `Product`) map onto two new custom MySQL tables created on activation.

### Database Schema

```sql
-- Table 1: quote requests (one row per submission)
CREATE TABLE {$wpdb->prefix}wishlist_devis_requests (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference     CHAR(4)         NOT NULL,        -- "0001".."9999"
    customer_type VARCHAR(20)     NOT NULL,
    company_name  VARCHAR(255)    NOT NULL DEFAULT '',
    siret         VARCHAR(20)     NOT NULL DEFAULT '',
    full_name     VARCHAR(255)    NOT NULL,
    email         VARCHAR(190)    NOT NULL,
    phone         VARCHAR(40)     NOT NULL DEFAULT '',
    country       VARCHAR(100)    NOT NULL DEFAULT '',
    postal_code   VARCHAR(20)     NOT NULL DEFAULT '',
    city          VARCHAR(120)    NOT NULL DEFAULT '',
    address       VARCHAR(255)    NOT NULL DEFAULT '',
    products      LONGTEXT        NOT NULL,         -- JSON Product[]
    created_at    DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY email (email),
    KEY reference (reference)
) {$charset_collate};

-- Table 2: stable email -> reference mapping (source of truth for numbering)
CREATE TABLE {$wpdb->prefix}wishlist_devis_references (
    email     VARCHAR(190) NOT NULL,
    reference CHAR(4)      NOT NULL,
    created_at DATETIME    NOT NULL,
    PRIMARY KEY (email),
    UNIQUE KEY reference (reference)
) {$charset_collate};
```

**Rationale for two tables:** the `references` table guarantees one reference per unique email independent of how many requests that email submits, and its `UNIQUE KEY reference` enforces no-duplicate references at the storage layer. The `requests` table denormalizes the reference onto each row for fast admin listing and historical accuracy.

---

## Key Functions with Formal Specifications

### Function 1: `wishlist_devis_install_tables()`

```php
function wishlist_devis_install_tables(): void
```

**Preconditions:**
- Runs in WordPress context with `$wpdb` available and `dbDelta()` loadable from `wp-admin/includes/upgrade.php`.
- Called from the plugin activation hook.

**Postconditions:**
- Both `{prefix}wishlist_devis_requests` and `{prefix}wishlist_devis_references` exist with the schema above.
- Idempotent: running again on existing tables makes no destructive change (dbDelta reconciles).
- No existing rows are deleted or altered in value.

**Loop Invariants:** N/A.

---

### Function 2: `wishlist_devis_get_or_create_reference($email)`

```php
function wishlist_devis_get_or_create_reference(string $email): string
```

**Preconditions:**
- `$email` is a non-empty, already-sanitized, lowercased email string.
- The `references` table exists.

**Postconditions:**
- Returns a 4-character, zero-padded numeric string (`/^\d{4}$/`).
- If `$email` already had a reference, the **same** reference is returned and no new row is inserted.
- If `$email` is new, a row is inserted with `reference = format(previous_max + 1)` and that value is returned.
- The first reference ever issued is `"0001"`.
- Across all rows, references are unique (guaranteed by `UNIQUE KEY reference` and the locked read-then-write).
- The mapping is monotonic: the Nth distinct email ever seen receives a reference numerically greater than every email seen before it.

**Concurrency / atomicity:**
- The read-max-then-insert sequence runs inside a transaction using `SELECT ... FOR UPDATE` (or a `GET_LOCK` named lock) so two simultaneous new emails cannot receive the same number. On a unique-key collision the function retries.

**Loop Invariants (retry loop):**
- Before each attempt, `attempts < MAX_ATTEMPTS`.
- After a failed attempt, the locally computed candidate number is strictly greater than on the previous attempt (because `MAX(reference)` advanced), so the loop makes progress toward a free slot.

**Edge case:** after `"9999"` the counter is exhausted; the function throws/returns an error sentinel and logs — documented as a 10,000-distinct-email limit for the 4-digit format.

---

### Function 3: `wishlist_devis_validate_submission($payload)`

```php
function wishlist_devis_validate_submission(array $payload): array // ValidationResult
```

**Preconditions:**
- `$payload` is the decoded JSON request body (may contain missing/extra keys).

**Postconditions:**
- Returns `ValidationResult`.
- `valid === true` **iff** all of the following hold:
  - `customer_type ∈ {particulier, professionnel}`.
  - `full_name` is a non-empty trimmed string.
  - `email` is non-empty and passes `is_email()`.
  - `products` is a non-empty array, each item having a non-empty `name` and `quantity >= 1`.
  - If `customer_type === professionnel`: `company_name` and `siret` are non-empty.
- When `valid === false`, `errors` is non-empty and maps each failing field to a French message; `data === null`.
- When `valid === true`, `data` contains the fully sanitized payload (see sanitization below) and `errors` is empty.
- No side effects (pure with respect to the database).

**Sanitization applied when building `data`:**
- `email`        → `sanitize_email()` then `strtolower()`.
- text fields    → `sanitize_text_field()`.
- `customer_type`→ whitelisted to the two allowed constants.
- `siret`        → digits-only normalization (kept as string).
- For `particulier`, `company_name` and `siret` are forced to `''` regardless of input.

**Loop Invariant (product validation loop):**
- All products inspected before index `i` are valid; encountering an invalid product sets an error and stops further per-product validation.

---

### Function 4: `wishlist_devis_save_request($data, $reference)`

```php
function wishlist_devis_save_request(array $data, string $reference): int // inserted row id
```

**Preconditions:**
- `$data` is a validated, sanitized `DevisPayload`.
- `$reference` matches `/^\d{4}$/` and was obtained from `wishlist_devis_get_or_create_reference($data['email'])`.

**Postconditions:**
- Exactly one new row is inserted into `{prefix}wishlist_devis_requests`.
- The stored row's `email`/`reference` pair is consistent with the `references` table (same email ⇒ same reference on every saved request).
- `products` is stored as `wp_json_encode($data['products'])`.
- `created_at` is set to `current_time('mysql', true)` (UTC).
- Returns the new row's auto-increment id (`> 0`); returns `0` on insert failure.

**Loop Invariants:** N/A.

---

### Function 5: `wishlist_devis_send_email()` (existing AJAX handler, extended in place)

```php
function wishlist_devis_send_email(): void // echoes JSON via wp_send_json(), then dies
```

**This is the existing handler — not a new endpoint.** It stays hooked on `wp_ajax_send_devis` and `wp_ajax_nopriv_send_devis`. The current body (decode JSON → build HTML/text email → `wishlist_devis_generate_word()` → two `wp_mail` calls → `wp_send_json(['message' => ...], code)`) is **augmented**, in this order: **validate → get_or_create_reference → compat-map `full_name` → save_request → existing email/`.docx` flow**.

**Preconditions:**
- Hooked on `wp_ajax_send_devis` and `wp_ajax_nopriv_send_devis` (unchanged).
- Request body is JSON carrying a `DevisPayload`.

**Postconditions:**
- The weak existing guard `if (!$data && empty($data['products']))` (see note below) is replaced/augmented by a call to `wishlist_devis_validate_submission()`.
- On validation failure: responds `wp_send_json(['message' => <French summary>, 'errors' => <field map>], 400)`; nothing is persisted; no email is sent. The `message` key is always present; `errors` is an **optional additional** key (it does not replace `message`), preserving the current front-end contract.
- On success, in order: (1) a reference exists for the email, (2) `$data['name']` is populated from `full_name` for backward compatibility (see Function 6 / field-mapping note), (3) one request row is persisted, (4) the existing email/`.docx` flow runs and sends to the two hardcoded recipients, (5) responds `wp_send_json(['message' => "Votre demande (réf. 0007) a bien été envoyée."])` — the 4-digit reference is embedded inside the `message` string.
- If email sending fails after a successful save, the request remains persisted and the response is `wp_send_json(['message' => "Demande enregistrée (réf. ....), mais l'email n'a pas pu être envoyé."], 500)` (data is never lost; response shape unchanged).

**Response shape (unchanged from current code):** every response is `wp_send_json(['message' => string, /* optional */ 'errors' => array], $httpStatus)`. The design intentionally keeps the flat `message` field rather than switching to `wp_send_json_success()/wp_send_json_error()`, because the current front-end JS reads `message` and a different envelope would break it.

**Loop Invariants:** N/A.

> **Note — existing buggy guard.** The current code starts with `if (!$data && empty($data['products'])) { wp_send_json(['message' => 'Aucun produit dans la wishlist'], 400); }`. Because it uses `&&` (logical AND), it only triggers when **both** `$data` is falsy **and** `$data['products']` is empty — so for any decoded payload it almost never fires, and it does not `return`/`die` explicitly afterward. The new `wishlist_devis_validate_submission()` call **replaces/augments** this guard with correct validation (empty products, missing required fields, etc.) and proper early-exit on failure.

---

### Function 6: existing email + `.docx` flow (inline in `wishlist_devis_send_email`, plus `wishlist_devis_generate_word`)

In the real code the email is **not** a separate helper — the HTML body, plain-text fallback, and the two `wp_mail` calls are inline inside `wishlist_devis_send_email()`, and the attachment is produced by `wishlist_devis_generate_word($products, $data)`. This section specifies how that existing flow is extended; the function signatures involved are:

```php
// existing generator, reused — extend its "INFORMATIONS CLIENT" block only
function wishlist_devis_generate_word(array $products, array $data): string // returns .docx path
```

**Preconditions:**
- A readable `.docx` is generated by the existing `wishlist_devis_generate_word()` (PHPWord) flow.
- Recipients are the **two hardcoded addresses** currently in the code:
  `$admin_email = 'jbastierdevillatte@gmail.com'` and `$admin_email2 = 'levibelhamou@gmail.com'`, each sent via its own `wp_mail` call. (The `get_option('wishlist_devis_admin_email')` line is commented out in the current code; this design keeps the current hardcoded behavior and does **not** silently switch to the option.)

**Postconditions:**
- The subject still uses `$data['name']` (`"Nouvelle demande de devis - " . $data['name']`), which is populated via the compat mapping from `full_name`.
- The reference number is embedded in the response `message` (and may also be surfaced in the email body/subject).
- Returns the boolean(s) from the two `wp_mail()` calls (the existing code keys success off the first call).

**Client-info blocks to extend (both kept consistent):**
- **Word `INFORMATIONS CLIENT` table block** — currently only `Nom: $data['name']` + `Email: $data['email']`. Extend to include: reference number, `customer_type` (Particulier/Professionnel), `company_name` + `siret` (only when professionnel), `full_name`, `email`, `phone`, and the full address (`country`, `postal_code`, `city`, `address`).
- **HTML + plain-text email client-info block** — currently only `Nom` + `Email` + date. Extend to include the same fields as the Word block above (reference, customer type, company/siret when professionnel, full name, email, phone, full address).

**Untouched:** the product table, the Excel-pricing lookup (`get_excel_data`), the totals (HT / emballage / TVA / TTC / acompte), the RIB / conditions block, and the image handling remain exactly as they are today.

**Loop Invariants:** N/A.

---

### Function 7: `wishlist_devis_render_admin_page()`

```php
function wishlist_devis_render_admin_page(): void // echoes admin HTML
```

**Preconditions:**
- Registered via `add_menu_page`/`add_submenu_page`; current user has `manage_options`.

**Postconditions:**
- Renders a table of all rows from `{prefix}wishlist_devis_requests`, newest first.
- Each row displays at least: `reference`, `created_at`, `customer_type`, `full_name`/`company_name`, `email`, `phone`, address, and product count/summary.
- Output is escaped (`esc_html` / `esc_attr`); reference column is clearly shown.

**Loop Invariant (render loop):** every emitted `<tr>` corresponds to exactly one fetched request row, in descending `created_at` order.

---

## Algorithmic Pseudocode

### Reference assignment (core correctness algorithm)

```pascal
ALGORITHM getOrCreateReference(email)
INPUT:  email  -- sanitized, lowercased, non-empty
OUTPUT: reference  -- string matching /^\d{4}$/

BEGIN
    ASSERT email <> ""

    -- Fast path: email already has a reference
    existing <- DB.selectOne("SELECT reference FROM references WHERE email = ?", email)
    IF existing <> NULL THEN
        RETURN existing.reference
    END IF

    attempts <- 0
    WHILE attempts < MAX_ATTEMPTS DO
        ASSERT attempts < MAX_ATTEMPTS          -- loop bound invariant

        DB.beginTransaction()

        -- Re-check inside the transaction (another request may have just inserted it)
        existing <- DB.selectOne(
            "SELECT reference FROM references WHERE email = ? FOR UPDATE", email)
        IF existing <> NULL THEN
            DB.commit()
            RETURN existing.reference
        END IF

        maxRef <- DB.selectScalar("SELECT MAX(CAST(reference AS UNSIGNED)) FROM references")
        IF maxRef = NULL THEN
            nextNum <- 1                         -- first reference ever
        ELSE
            nextNum <- maxRef + 1
        END IF

        IF nextNum > 9999 THEN
            DB.rollback()
            RAISE Error("Reference space exhausted (4-digit limit)")
        END IF

        reference <- zeroPad(nextNum, 4)         -- 1 -> "0001", 42 -> "0042"

        ok <- DB.insert("references", {email, reference, created_at: nowUtc()})
        IF ok THEN
            DB.commit()
            ASSERT reference matches /^\d{4}$/
            RETURN reference
        ELSE
            -- UNIQUE collision on reference or email: roll back and retry
            DB.rollback()
            attempts <- attempts + 1
        END IF
    END WHILE

    RAISE Error("Could not allocate reference after MAX_ATTEMPTS")
END
```

**Preconditions:** `email` is sanitized and non-empty; `references` table exists.
**Postconditions:** returns a unique 4-digit reference; identical email always yields its first-assigned reference; distinct new emails yield strictly increasing numbers.
**Loop Invariants:** `attempts` strictly increases and is bounded by `MAX_ATTEMPTS`; on each retry `MAX(reference)` is re-read so the candidate number never decreases.

### Submission handling (inside the existing `wishlist_devis_send_email`)

```pascal
ALGORITHM wishlistDevisSendEmail(rawBody)   -- existing handler, extended in place
INPUT:  rawBody  -- raw HTTP request body (php://input)
OUTPUT: JSON response via wp_send_json(['message'=>..., 'errors'?=>...], status)

BEGIN
    payload <- jsonDecode(rawBody)

    -- Replaces the buggy `if (!$data && empty($data['products']))` guard
    result <- validateSubmission(payload)
    IF NOT result.valid THEN
        wp_send_json({ message: "Veuillez corriger les champs indiqués.",
                       errors:  result.errors }, 400)   -- message ALWAYS present; errors optional
        RETURN
    END IF

    data <- result.data

    TRY
        reference <- getOrCreateReference(data.email)
    CATCH e
        wp_send_json({ message: "Numéro de référence indisponible." }, 500)
        RETURN
    END TRY

    -- Compatibility mapping: existing docx/subject/email logic reads $data['name'],
    -- but the new form sends full_name. Populate name so legacy code keeps working.
    data.name <- data.full_name

    rowId <- saveRequest(data, reference)
    IF rowId = 0 THEN
        wp_send_json({ message: "Échec de l'enregistrement de la demande." }, 500)
        RETURN
    END IF

    -- Existing email/.docx flow (unchanged shape): build HTML + text bodies
    -- (now with the extended INFORMATIONS CLIENT block), generate the .docx via
    -- wishlist_devis_generate_word(data.products, data), then two wp_mail calls
    -- to the two hardcoded recipients.
    docxPath <- wishlist_devis_generate_word(data.products, data)
    emailed  <- existingEmailFlow(reference, data, docxPath)   -- 2 hardcoded recipients

    IF emailed THEN
        wp_send_json({ message: "Votre demande (réf. " + reference + ") a bien été envoyée." })
    ELSE
        wp_send_json({ message: "Demande enregistrée (réf. " + reference +
                                "), mais l'email n'a pas pu être envoyé." }, 500)
    END IF
END
```

**Preconditions:** AJAX endpoint reachable; `rawBody` present.
**Postconditions:** invalid input persists nothing and sends no email; valid input yields a reference, the `full_name → name` compat mapping, one persisted row, and one email attempt to the two hardcoded recipients, in that order. Every response keeps the `wp_send_json(['message' => ...], code)` shape.

### Field-mapping / compatibility note

The new front-end form submits `full_name`, but the existing `.docx` generator (`wishlist_devis_generate_word`), the email subject (`"Nouvelle demande de devis - " . $data['name']`), and the email bodies all read `$data['name']`. To avoid breaking that legacy logic, the handler sets `$data['name'] = $data['full_name']` immediately after validation/sanitization (alternatively, `wishlist_devis_generate_word` is updated to read `full_name`). This single compatibility mapping happens once in `wishlist_devis_send_email()` before the save and the email/`.docx` flow run.

### Front-end field toggle (JS)

```pascal
ALGORITHM onCustomerTypeChange(selectedType)
INPUT:  selectedType in { "particulier", "professionnel" }
OUTPUT: (DOM mutated)

BEGIN
    IF selectedType = "particulier" THEN
        hide(companyNameGroup)
        hide(siretGroup)
        clearValue(companyNameInput)
        clearValue(siretInput)
    ELSE
        show(companyNameGroup)
        show(siretGroup)
    END IF
END
```

---

## Example Usage

### Activation: create tables

```php
register_activation_hook(__FILE__, function () {
    wishlist_devis_activate();        // existing: stores admin email option
    wishlist_devis_install_tables();  // new: create requests + references tables
});
```

### Register the admin menu

```php
add_action('admin_menu', function () {
    add_menu_page(
        'Demandes de devis',          // page title
        'Demandes de devis',          // menu label
        'manage_options',             // capability
        'wishlist-devis-requests',    // slug
        'wishlist_devis_render_admin_page',
        'dashicons-media-document'
    );
});
```

### AJAX handler wiring (existing `send_devis` callback, kept as-is)

```php
// Unchanged from the current code — the existing handler is EXTENDED in place,
// not replaced by a new callback.
add_action('wp_ajax_send_devis',        'wishlist_devis_send_email');
add_action('wp_ajax_nopriv_send_devis', 'wishlist_devis_send_email');
```

### Reference reuse behavior

```php
// First time alice@example.com submits:
$ref1 = wishlist_devis_get_or_create_reference('alice@example.com'); // "0001"

// A different email submits next:
$ref2 = wishlist_devis_get_or_create_reference('bob@example.com');   // "0002"

// alice@example.com submits again later:
$ref3 = wishlist_devis_get_or_create_reference('alice@example.com'); // "0001" (reused)
```

### Updated shortcode form markup (replaces the current two-field form)

> **Replacement, not addition.** The enriched form below is rendered by the **same** `wishlist_devis_shortcode()` (`[wishlist_devis]`), inside the **same** `.wishlist-devis-container` / `#devis-form` markup, and is still revealed by the existing **"Demander un devis"** button (`#request-devis-btn`). It therefore appears in the exact same location on the wishlist page. The current simple form — which today outputs only "Nom et prénom" (`#devis-name`) and "Email" (`#devis-email`) — is **fully replaced** by these fields (the old two-field markup is removed, not appended to). The old `#devis-name` field is **superseded by `#devis-full-name`**. The action buttons (`#cancel-devis`, `#send-devis`) and the success/error UI elements (`#devis-form-success`, `#devis-form-error`) are **reused unchanged**.

```php
// Rendered inside the SAME #devis-form container as today, replacing the old
// #devis-name / #devis-email-only markup.

// Customer type radios (orange when checked via CSS :checked rule)
echo '<label class="wd-radio"><input type="radio" name="devis-customer-type"
        value="particulier" checked> Particulier</label>';
echo '<label class="wd-radio"><input type="radio" name="devis-customer-type"
        value="professionnel"> Professionnel</label>';

// Company fields (wrapped so JS can hide them for "particulier")
echo '<div id="devis-company-fields">';
echo '  <input type="text" id="devis-company-name" placeholder="Nom de société">';
echo '  <input type="text" id="devis-siret" placeholder="Numéro de SIRET">';
echo '</div>';

echo '<input type="text"  id="devis-full-name"   placeholder="Prénom et nom" required>'; // supersedes old #devis-name
echo '<input type="text"  id="devis-country"     placeholder="Pays">';
echo '<input type="text"  id="devis-postal-code" placeholder="Code postal">';
echo '<input type="text"  id="devis-city"        placeholder="Ville">';
echo '<input type="text"  id="devis-address"     placeholder="Adresse">';
echo '<input type="email" id="devis-email"       placeholder="Email" required>';
echo '<input type="tel"   id="devis-phone"       placeholder="Téléphone">';

// REUSED unchanged from the current form:
//   <button id="cancel-devis"> / <button id="send-devis">
//   <div id="devis-form-success"> / <div id="devis-form-error">
```

> **Front-end JS update (`wishlist-devis-plugin.js`).** The current field-collection logic reads only `#devis-name` and `#devis-email` and posts `{ action, name, email, products }`. It MUST be updated to read the new fields (`customer-type` radios, `#devis-company-name`, `#devis-siret`, `#devis-full-name`, `#devis-email`, `#devis-phone`, `#devis-country`, `#devis-postal-code`, `#devis-city`, `#devis-address`) and post the enriched `DevisPayload`. The existing product-collection loop, the `#devis-form-success` / `#devis-form-error` rendering, and the show/hide wiring on `#request-devis-btn` / `#cancel-devis` are reused; only the field set being read and submitted changes (plus the `onCustomerTypeChange` toggle defined above).

### Orange radio styling (CSS)

```css
/* Orange accent when a customer-type radio is checked */
.wd-radio input[type="radio"] { accent-color: #ff7a00; }
.wd-radio input[type="radio"]:checked + span,
.wd-radio:has(input:checked) { color: #ff7a00; font-weight: 600; }
```

---

## Correctness Properties

These universally-quantified statements drive the property-based tests defined in the tasks phase.

### Property 1: Reference format
For every email `e`, `getOrCreateReference(e)` returns a string matching `^\d{4}$`.

**Validates: Requirements 3.1**

### Property 2: Reference stability (idempotence per email)
For every email `e` and any number of calls, `getOrCreateReference(e) = getOrCreateReference(e)` — the same email always maps to the same reference.

**Validates: Requirements 3.2, 4.3**

### Property 3: Reference uniqueness across emails
For every pair of distinct emails `e1 != e2`, their assigned references differ.

**Validates: Requirements 3.5, 8.3**

### Property 4: Monotonic numbering
If email `e1` is first seen before email `e2`, then `int(ref(e1)) < int(ref(e2))`, and the very first issued reference equals `"0001"`.

**Validates: Requirements 3.3, 3.4, 3.6**

### Property 5: No gaps from reuse
Re-submitting with an already-known email does not advance the counter (no new reference is consumed).

**Validates: Requirements 3.2**

### Property 6: Validation gate
A submission is persisted **iff** it passes `validateSubmission`; invalid submissions create zero rows and send zero emails.

**Validates: Requirements 2.1, 2.2, 2.5, 2.6, 5.2**

### Property 7: Professional invariant
Every persisted row with `customer_type = professionnel` has non-empty `company_name` and `siret`; every row with `customer_type = particulier` has empty `company_name` and `siret`.

**Validates: Requirements 2.3, 9.1, 9.2**

### Property 8: Reference propagation
For every successful submission, the reference returned to the client, the reference stored on the request row, and the reference embedded in the email are identical.

**Validates: Requirements 5.3, 6.5**

### Property 9: Persistence completeness
Every successfully validated submission results in exactly one new row in `wishlist_devis_requests`.

**Validates: Requirements 4.1, 4.2, 4.4**

## Error Handling

| Scenario | Condition | Response | Recovery |
|----------|-----------|----------|----------|
| Invalid input | `validateSubmission` fails | `wp_send_json(['message'=>"Veuillez corriger les champs indiqués.", 'errors'=>...], 400)`; nothing persisted, no email | Front-end shows the `message` (and per-field `errors` when present); user corrects and resubmits |
| Empty wishlist | `products` empty | Validation error on `products` → `wp_send_json(['message'=>..., 'errors'=>...], 400)` | User adds items before requesting |
| Reference space exhausted | next number `> 9999` | `getOrCreateReference` raises; handler responds `wp_send_json(['message'=>French msg], 500)` | Operational: widen reference format / archive (documented limit) |
| DB insert failure | `save_request` returns `0` | `wp_send_json(['message'=>"Échec de l'enregistrement de la demande."], 500)`; no email sent | User retries; error logged via `error_log` |
| Email send failure | `wp_mail` returns false after a successful save | `wp_send_json(['message'=>"Demande enregistrée (réf. ....), mais l'email n'a pas pu être envoyé."], 500)` including the reference | Request is preserved in admin list; admin can follow up manually |
| Concurrent new emails | two new emails race for the next number | unique-key collision triggers bounded retry with re-read max | Retry resolves to distinct sequential references |

> All error and success responses keep the existing flat `wp_send_json(['message' => ...], $status)` shape; `errors` is only ever an **optional additional** key alongside `message`, never a replacement. This preserves compatibility with the current front-end JS that reads `message`.

## Testing Strategy

### Unit Testing Approach
- `validateSubmission`: table-driven cases for each required field, the `particulier` vs `professionnel` branching, email validity, and product-list rules.
- reference formatting (`zeroPad`): boundary values `1`, `42`, `9999`, `10000`.
- `save_request`: asserts a single row inserted with JSON-encoded products and UTC `created_at`.

### Property-Based Testing Approach
Encode Correctness Properties 1-5 as properties over randomized sequences of emails (with repeats) fed to the reference service against a test database, asserting format, stability, cross-email uniqueness, monotonicity, and no-counter-advance-on-reuse. Properties 6-9 are exercised by generating random valid/invalid payloads and asserting persistence/email side effects.

**Property Test Library:** PHP — PHPUnit with `giorgiosironi/eris` for property-based generators (or QuickCheck-style data providers if Eris is unavailable).

### Integration Testing Approach
- End-to-end AJAX test using the WordPress test harness (`WP_UnitTestCase`): POST a payload to the `send_devis` action, assert HTTP JSON shape, one new row, and the reference present in both the row and the captured email.
- Admin page render test: seed rows, assert the reference column and newest-first ordering.

---

## Dependencies

- **WordPress core APIs:** `$wpdb`, `dbDelta`, `register_activation_hook`, `add_menu_page`, `wp_ajax_*` actions, `wp_send_json` (flat `['message'=>...]` shape, optional `errors` key), `is_email`, `sanitize_*`, `current_time`, `wp_json_encode`, `wp_mail`, `esc_html`/`esc_attr`.
- **phpoffice/phpword** (`^1.3`, already in `composer.json`) — existing `.docx` quote generation.
- **jQuery** — already enqueued for the front-end script.
- No new third-party PHP packages are required.
