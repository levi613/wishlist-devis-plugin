# Implementation Plan: Devis Request Management

## Overview

This plan extends the existing **Wishlist Devis Plugin** in place. It builds bottom-up:
database installer → reference service → validator → request store → handler
orchestration → email/`.docx` enrichment → admin viewer → enriched front-end
(form markup + JS + CSS). Each step builds on the previous one and ends wired into
the existing `send_devis` AJAX flow, so no orphaned code is left behind.

Implementation language is **PHP** (PHPUnit + `giorgiosironi/eris` for property tests),
matching the design and the existing plugin code.

### Global constraints (apply to every task)

- **`full_name → name` compatibility mapping:** the new form submits `full_name`, but
  the legacy subject, email bodies, and `wishlist_devis_generate_word()` read
  `$data['name']`. The handler MUST set `$data['name'] = $data['full_name']` once,
  after validation and before save/email. Do not rename the legacy `name` reads.
- **Preserve the existing response shape:** every response stays flat
  `wp_send_json(['message' => string, /* optional */ 'errors' => array], $httpStatus)`.
  Never switch to `wp_send_json_success()/wp_send_json_error()`. `errors` is only ever
  an optional additional key alongside `message`.
- **Two hardcoded recipients unchanged:** keep the two `wp_mail` calls to
  `jbastierdevillatte@gmail.com` and `levibelhamou@gmail.com`. Do not switch to the
  commented-out `get_option('wishlist_devis_admin_email')`.
- **Excel-pricing flow untouched:** the product table, `get_excel_data` lookup, totals
  (HT / emballage / TVA / TTC / acompte), RIB/conditions block, and image handling
  remain exactly as they are today. Only the `INFORMATIONS CLIENT` block is extended.

## Tasks

- [x] 1. Create database tables and test scaffolding
  - [x] 1.1 Implement the table installer and wire the activation hook
    - Add `wishlist_devis_install_tables()` to `devis-functions.php` creating
      `{prefix}wishlist_devis_requests` and `{prefix}wishlist_devis_references` via
      `dbDelta()` with the documented schema (CHAR(4) `reference`, `UNIQUE KEY reference`
      on the references table, JSON `products` LONGTEXT, `created_at` DATETIME).
    - Define the `WD_CUSTOMER_TYPE_INDIVIDUAL` / `WD_CUSTOMER_TYPE_PROFESSIONAL` constants.
    - In `wishlist-devis-plugin.php`, call `wishlist_devis_install_tables()` from the
      existing `register_activation_hook` callback alongside `wishlist_devis_activate()`.
    - Ensure idempotency (re-running reconciles schema without altering existing rows).
    - _Requirements: 8.1, 8.2, 8.3_

  - [ ]* 1.2 Configure the PHPUnit + Eris test harness
    - Add `phpunit.xml`, a test bootstrap that exposes `$wpdb`/the WP test DB, and confirm
      `giorgiosironi/eris` is available in `composer.json` (require-dev) for property tests.
    - Provide a helper to truncate the two custom tables between tests.
    - _Requirements: 8.1_

- [x] 2. Implement the reference service
  - [x] 2.1 Implement `wishlist_devis_get_or_create_reference($email)`
    - Add the function to `devis-functions.php`: fast-path return of an existing reference,
      otherwise locked read-MAX-then-insert (`SELECT ... FOR UPDATE` / named lock) inside a
      transaction, zero-pad to 4 digits, retry on unique collision (bounded attempts), and
      raise/return an error sentinel when the next number would exceed 9999.
    - First reference issued against an empty store MUST be `"0001"`.
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7, 3.8_

  - [ ]* 2.2 Write property test for reference format
    - **Property 1: Reference format** — for every email, the result matches `^\d{4}$`.
    - **Validates: Requirements 3.1**

  - [ ]* 2.3 Write property test for reference stability
    - **Property 2: Reference stability (idempotence per email)** — repeated calls for the
      same email always return the same reference.
    - **Validates: Requirements 3.2, 4.3**

  - [ ]* 2.4 Write property test for cross-email uniqueness
    - **Property 3: Reference uniqueness across emails** — distinct emails get distinct references.
    - **Validates: Requirements 3.5, 8.3**

  - [ ]* 2.5 Write property test for monotonic numbering
    - **Property 4: Monotonic numbering** — earlier-seen email gets a numerically smaller
      reference, and the first issued reference equals `"0001"`.
    - **Validates: Requirements 3.3, 3.4, 3.6**

  - [ ]* 2.6 Write property test for no-gap reuse
    - **Property 5: No gaps from reuse** — resubmitting a known email does not advance the counter.
    - **Validates: Requirements 3.2**

  - [ ]* 2.7 Write unit tests for zero-pad boundaries and exhaustion
    - Cover `1 → "0001"`, `42 → "0042"`, `9999 → "9999"`, and the `> 9999` error path
      (no row inserted).
    - _Requirements: 3.4, 3.8_

- [x] 3. Implement submission validation and sanitization
  - [x] 3.1 Implement `wishlist_devis_validate_submission($payload)`
    - Add the function to `devis-functions.php` returning the `ValidationResult` shape
      (`valid`, `errors` field→French message map, `data`).
    - Validity rules: `customer_type ∈ {particulier, professionnel}`, non-empty trimmed
      `full_name`, non-empty `email` passing `is_email()`, non-empty `products` each with
      non-empty `name` and `quantity >= 1`; for `professionnel`, `company_name` and `siret`
      both non-empty.
    - Sanitization on success: `sanitize_email()` + `strtolower()` for email,
      `sanitize_text_field()` for text fields, whitelist `customer_type`, digits-only `siret`,
      and force `company_name`/`siret` to `''` for `particulier`.
    - Pure with respect to the database (no writes); return `data === null` on failure.
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6_

  - [ ]* 3.2 Write unit tests for the validator
    - Table-driven cases per required field, `particulier` vs `professionnel` branching,
      email validity, product-list rules, and the `particulier` company/siret force-empty rule.
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5_

- [x] 4. Implement the request store
  - [x] 4.1 Implement `wishlist_devis_save_request($data, $reference)`
    - Add the function to `devis-functions.php`: insert exactly one row into
      `{prefix}wishlist_devis_requests`, store `products` via `wp_json_encode()`, set
      `created_at` to `current_time('mysql', true)` (UTC), store the passed `$reference`,
      return the new auto-increment id (`> 0`), or `0` on insert failure.
    - Preserve the professional/individual invariant in the stored row (company/siret
      non-empty for `professionnel`, empty for `particulier`) using the sanitized `$data`.
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 9.1, 9.2_

  - [ ]* 4.2 Write property test for the professional/individual invariant
    - **Property 7: Professional invariant** — every persisted `professionnel` row has
      non-empty `company_name` and `siret`; every `particulier` row has them empty.
    - **Validates: Requirements 2.3, 9.1, 9.2**

  - [ ]* 4.3 Write property test for persistence completeness
    - **Property 9: Persistence completeness** — each successfully validated submission
      produces exactly one new `wishlist_devis_requests` row.
    - **Validates: Requirements 4.1, 4.2, 4.4**

  - [ ]* 4.4 Write unit tests for the request store
    - Assert a single inserted row, JSON-encoded products, UTC `created_at`, and the `0`
      return on insert failure.
    - _Requirements: 4.2, 4.4, 4.5_

- [x] 5. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 6. Extend the submission handler orchestration
  - [x] 6.1 Extend `wishlist_devis_send_email()` in place
    - In `devis-functions.php`, replace the buggy `if (!$data && empty($data['products']))`
      guard with `wishlist_devis_validate_submission()` and an early exit on failure.
    - Orchestrate in order: validate → `wishlist_devis_get_or_create_reference()` →
      `$data['name'] = $data['full_name']` compat map → `wishlist_devis_save_request()` →
      existing email/`.docx` flow.
    - Responses (flat `message` shape): validation failure →
      `(['message'=>..., 'errors'=>...], 400)`; reference failure → `(['message'=>...], 500)`,
      persist nothing; save failure → `(['message'=>...], 500)`, no email; success →
      `message` embedding `réf. <reference>`; email failure after save → `message` with
      reference, status `500`, request kept.
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5, 5.6_

  - [ ]* 6.2 Write property test for the validation gate
    - **Property 6: Validation gate** — a submission is persisted iff it passes validation;
      invalid submissions create zero rows and send zero emails.
    - **Validates: Requirements 2.1, 2.2, 2.5, 2.6, 5.2**

  - [ ]* 6.3 Write integration tests for the handler responses
    - Using the WP test harness, POST payloads to `send_devis` and assert the flat
      `wp_send_json` shape and HTTP statuses for invalid / reference-failure / save-failure /
      success / email-failure paths.
    - _Requirements: 5.2, 5.3, 5.4, 5.5, 5.6_

- [x] 7. Enrich the email and `.docx` client-information blocks
  - [x] 7.1 Extend the `INFORMATIONS CLIENT` blocks with full customer details
    - In `devis-functions.php`, extend the Word `INFORMATIONS CLIENT` table block in
      `wishlist_devis_generate_word()` and the HTML + plain-text email client-info block to
      include: reference, `customer_type`, `full_name`, `email`, `phone`, `country`,
      `postal_code`, `city`, `address`, and `company_name` + `siret` only when `professionnel`.
    - Keep the subject using `$data['name']` (populated via the compat mapping) and leave the
      product table, Excel-pricing lookup, totals, RIB/conditions block, image handling, and
      the two hardcoded recipients unchanged.
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.6_

  - [ ]* 7.2 Write property test for reference propagation
    - **Property 8: Reference propagation** — the reference returned to the client, stored on
      the row, and embedded in the email are identical.
    - **Validates: Requirements 5.3, 6.5**

  - [ ]* 7.3 Write integration test for recipients and reference in email
    - Capture sent mail; assert both hardcoded recipients receive it and the reference appears
      in the email body/`.docx` client block.
    - _Requirements: 6.2, 6.5_

- [x] 8. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 9. Implement the admin viewer
  - [x] 9.1 Implement `wishlist_devis_render_admin_page()` and register the menu
    - Add the render function to `devis-functions.php` listing all
      `{prefix}wishlist_devis_requests` rows newest-first by `created_at`, showing reference,
      creation date, customer type, full name/company name, email, phone, address, and a
      product count/summary, escaping every dynamic value with `esc_html`/`esc_attr`.
    - In `wishlist-devis-plugin.php`, register the `manage_options`-gated `add_menu_page`
      pointing at the render function.
    - _Requirements: 7.1, 7.2, 7.3, 7.4_

  - [ ]* 9.2 Write integration test for the admin viewer
    - Seed rows and assert newest-first ordering, the reference column, and escaped output.
    - _Requirements: 7.2, 7.3, 7.4_

- [x] 10. Replace the front-end form markup
  - [x] 10.1 Update the shortcode form in `wishlist-devis-plugin.php`
    - Inside the existing `#devis-form` container, replace the old `#devis-name`/`#devis-email`
      markup with: the `particulier`/`professionnel` customer-type radios, wrapped company
      fields (`#devis-company-name`, `#devis-siret`), `#devis-full-name` (supersedes
      `#devis-name`), `#devis-country`, `#devis-postal-code`, `#devis-city`, `#devis-address`,
      `#devis-email`, `#devis-phone`.
    - Reuse the existing trigger (`#request-devis-btn`), `#cancel-devis`, `#send-devis`, and
      `#devis-form-success` / `#devis-form-error` elements unchanged.
    - _Requirements: 1.1, 1.2_

- [x] 11. Update the front-end JavaScript
  - [x] 11.1 Collect new fields, toggle company fields, and post the enriched payload
    - In `wishlist-devis-plugin.js`, read all new fields and post the `DevisPayload`
      (`customer_type`, `company_name`, `siret`, `full_name`, `email`, `phone`, `country`,
      `postal_code`, `city`, `address`, `products`) to the `send_devis` action.
    - Implement `onCustomerTypeChange`: for `particulier` hide and clear the company name and
      SIRET fields; for `professionnel` show them.
    - Reuse the existing product-collection loop and success/error rendering.
    - _Requirements: 1.4, 1.5, 1.6_

- [x] 12. Update the front-end CSS
  - [x] 12.1 Style checked radios and hidden company fields
    - In `wishlist-devis-plugin.css`, add the orange accent styling for the checked
      customer-type radio and the rule that hides the company-fields wrapper for `particulier`.
    - _Requirements: 1.3, 1.4_

- [x] 13. Final checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional (tests) and can be skipped for a faster MVP.
- Each task references specific requirement sub-clauses for traceability.
- Property tests use PHPUnit + `giorgiosironi/eris`; each of the 9 correctness properties
  is its own sub-task placed next to the code it validates.
- The four global constraints (full_name→name mapping, flat response shape, two hardcoded
  recipients, untouched Excel-pricing flow) apply across all implementation tasks.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "1.2", "11.1", "12.1"] },
    { "id": 1, "tasks": ["2.1"] },
    { "id": 2, "tasks": ["3.1", "2.2", "2.3", "2.4", "2.5", "2.6", "2.7"] },
    { "id": 3, "tasks": ["4.1", "3.2"] },
    { "id": 4, "tasks": ["6.1", "4.2", "4.3", "4.4"] },
    { "id": 5, "tasks": ["7.1", "6.2", "6.3"] },
    { "id": 6, "tasks": ["9.1", "7.2", "7.3"] },
    { "id": 7, "tasks": ["10.1", "9.2"] }
  ]
}
```
