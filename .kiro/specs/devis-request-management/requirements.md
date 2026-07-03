# Requirements Document

## Introduction

This feature enhances the existing **Wishlist Devis Plugin** (a WordPress plugin) so that quote (`devis`) requests collect full customer and address details, persist every request into custom database tables, expose a `manage_options`-gated admin screen listing all requests, and assign each customer a stable 4-digit reference number keyed by email. The enriched form replaces the current two-field (name + email) markup inside the existing `[wishlist_devis]` shortcode, and the existing AJAX handler (`wishlist_devis_send_email`, action `send_devis`) is extended in place — preserving the current flat `wp_send_json(['message' => ...], $status)` response shape, the two hardcoded recipients, and the `.docx`/Excel-pricing email flow.

This requirements document is derived from the approved design document and traces directly to its components, key functions, and nine correctness properties.

## Glossary

- **Plugin**: The Wishlist Devis Plugin WordPress plugin being enhanced.
- **Devis_Form**: The front-end quote-request form rendered by the `wishlist_devis_shortcode()` function inside the `[wishlist_devis]` shortcode container (`#devis-form`).
- **Submission_Handler**: The existing AJAX handler `wishlist_devis_send_email()`, hooked on `wp_ajax_send_devis` and `wp_ajax_nopriv_send_devis`, extended in place to validate, assign a reference, persist, and run the existing email flow.
- **Validator**: The `wishlist_devis_validate_submission()` function that validates and sanitizes the decoded payload.
- **Reference_Service**: The `wishlist_devis_get_or_create_reference()` function that allocates and reuses 4-digit references keyed by email.
- **Request_Store**: The `wishlist_devis_save_request()` function and the `{prefix}wishlist_devis_requests` table that persist quote requests.
- **Reference_Store**: The `{prefix}wishlist_devis_references` table that maps each unique email to a stable 4-digit reference.
- **Admin_Viewer**: The `manage_options`-gated admin menu page rendered by `wishlist_devis_render_admin_page()`.
- **Installer**: The `wishlist_devis_install_tables()` function that creates the custom tables on activation.
- **Email_Flow**: The existing inline email + `.docx` generation flow (`wishlist_devis_generate_word()` plus the two `wp_mail` calls to two hardcoded recipients).
- **Customer_Type**: One of `particulier` (individual) or `professionnel` (professional).
- **Reference**: A 4-character, zero-padded numeric string matching `^\d{4}$`, e.g. `"0001"`, `"0042"`.
- **DevisPayload**: The decoded JSON request body carrying customer type, company name, SIRET, full name, email, phone, address fields, and a non-empty products array.
- **Product**: A single wishlist line item with `id`, `name`, `img`, and `quantity >= 1`.

## Requirements

### Requirement 1: Enriched front-end quote form

**User Story:** As a visitor requesting a quote, I want a detailed form with my customer type, company information, full name, address, email, and phone, so that the supplier receives all the information needed to prepare an accurate quote.

#### Acceptance Criteria

1. THE Devis_Form SHALL render, inside the existing `#devis-form` container, a customer-type selector offering exactly the two options `particulier` and `professionnel`, a company name field, a SIRET field, a full name field, a country field, a postal code field, a city field, a street address field, an email field, and a phone field.
2. THE Devis_Form SHALL replace the previous name (`#devis-name`) and email-only markup while reusing the existing trigger button, cancel button, send button, and success and error elements.
3. WHEN a customer-type radio is in the checked state, THE Devis_Form SHALL display that radio with the orange accent styling.
4. WHILE the selected Customer_Type is `particulier`, THE Devis_Form SHALL hide the company name field and the SIRET field and clear their values.
5. WHILE the selected Customer_Type is `professionnel`, THE Devis_Form SHALL display the company name field and the SIRET field.
6. WHEN the visitor submits the form, THE Devis_Form SHALL post a DevisPayload containing the customer type, company name, SIRET, full name, email, phone, country, postal code, city, street address, and the products array to the `send_devis` action.

### Requirement 2: Submission validation and sanitization

**User Story:** As a site operator, I want every submission validated and sanitized before anything is stored or emailed, so that only well-formed, safe data enters the system.

#### Acceptance Criteria

1. WHEN the Submission_Handler receives a payload, THE Validator SHALL classify the submission as valid only if the Customer_Type is one of `particulier` or `professionnel`, the full name is a non-empty trimmed string, the email is non-empty and passes `is_email()`, and the products array is non-empty with each product having a non-empty name and a quantity of at least 1.
2. WHERE the Customer_Type is `professionnel`, THE Validator SHALL classify the submission as valid only if the company name and the SIRET are both non-empty.
3. WHERE the Customer_Type is `particulier`, THE Validator SHALL force the company name and the SIRET to empty strings in the sanitized data regardless of the submitted values.
4. WHEN a submission is valid, THE Validator SHALL return sanitized data in which the email is passed through `sanitize_email()` then lowercased, text fields are passed through `sanitize_text_field()`, the Customer_Type is whitelisted to an allowed constant, and the SIRET is normalized to digits only.
5. IF a submission fails validation, THEN THE Validator SHALL return a non-empty map of failing field names to French messages and SHALL return null sanitized data.
6. THE Validator SHALL perform validation and sanitization without modifying the database.

### Requirement 3: Reference number allocation and reuse

**User Story:** As a supplier, I want each customer to keep a single stable 4-digit reference number tied to their email, so that I can recognize returning customers and track their requests consistently.

#### Acceptance Criteria

1. WHEN the Reference_Service is called with a sanitized non-empty email, THE Reference_Service SHALL return a Reference matching the pattern `^\d{4}$`.
2. WHEN the Reference_Service is called with an email that already has a Reference, THE Reference_Service SHALL return the same Reference and SHALL NOT insert a new Reference_Store row.
3. WHEN the Reference_Service is called with an email that has no Reference, THE Reference_Service SHALL insert a Reference_Store row whose Reference equals the previous maximum Reference plus one, formatted as a zero-padded 4-digit string.
4. WHEN the Reference_Service issues the first Reference in an empty Reference_Store, THE Reference_Service SHALL return `"0001"`.
5. WHERE two distinct emails have each been assigned a Reference, THE Reference_Service SHALL ensure their assigned References differ.
6. WHERE one email is first seen before another email, THE Reference_Service SHALL assign the earlier email a numerically smaller Reference than the later email.
7. WHILE two new emails are allocated concurrently, THE Reference_Service SHALL allocate distinct sequential References by running the read-maximum-then-insert sequence under a lock and retrying on a uniqueness collision.
8. IF the next Reference number would exceed 9999, THEN THE Reference_Service SHALL raise an error and SHALL NOT insert a Reference_Store row.

### Requirement 4: Persisting quote requests

**User Story:** As a supplier, I want every valid quote request stored in the database, so that no request is lost and all requests can be reviewed later.

#### Acceptance Criteria

1. WHEN a validated submission is saved, THE Request_Store SHALL insert exactly one new row into the `{prefix}wishlist_devis_requests` table.
2. WHEN a request row is inserted, THE Request_Store SHALL store the products as a JSON-encoded string and SHALL set `created_at` to the current UTC MySQL datetime.
3. WHEN a request row is inserted, THE Request_Store SHALL store the Reference that the Reference_Service assigned for the submission email, so that the same email always yields the same Reference on every saved request.
4. WHEN an insert succeeds, THE Request_Store SHALL return the new row's auto-increment id greater than 0.
5. IF the insert fails, THEN THE Request_Store SHALL return 0 and the Submission_Handler SHALL respond with HTTP status 500 and SHALL NOT send an email.

### Requirement 5: Submission orchestration and response

**User Story:** As a visitor, I want clear feedback after submitting, including my reference number on success, so that I know whether my request was received.

#### Acceptance Criteria

1. WHEN the Submission_Handler processes a request, THE Submission_Handler SHALL execute validation, then reference allocation, then the full-name-to-name compatibility mapping, then persistence, then the existing Email_Flow, in that order.
2. IF validation fails, THEN THE Submission_Handler SHALL respond with `wp_send_json` carrying a `message` key and an additional `errors` key with HTTP status 400, and SHALL persist nothing and send no email.
3. WHEN a submission succeeds, THE Submission_Handler SHALL respond with `wp_send_json` carrying a `message` key that embeds the 4-digit Reference.
4. IF reference allocation fails, THEN THE Submission_Handler SHALL respond with `wp_send_json` carrying a `message` key with HTTP status 500 and SHALL persist nothing.
5. IF the email send fails after a successful save, THEN THE Submission_Handler SHALL keep the persisted request and SHALL respond with `wp_send_json` carrying a `message` key that includes the Reference with HTTP status 500.
6. THE Submission_Handler SHALL keep every response in the flat `wp_send_json(['message' => ...], $status)` shape with `errors` as an optional additional key.

### Requirement 6: Email and document enrichment with reference propagation

**User Story:** As a supplier, I want the outgoing email and `.docx` quote to include the customer's full details and reference number, so that the quote document is complete and traceable.

#### Acceptance Criteria

1. WHEN a submission succeeds, THE Submission_Handler SHALL map the full name to the legacy `name` field before the Email_Flow runs, so that the existing subject and document logic continue to work.
2. WHEN the Email_Flow runs, THE Email_Flow SHALL send the quote email and `.docx` attachment to the two hardcoded recipients currently configured in the Plugin.
3. WHEN the Email_Flow builds the client-information blocks in both the `.docx` document and the email body, THE Email_Flow SHALL include the Reference, Customer_Type, full name, email, phone, country, postal code, city, and street address.
4. WHERE the Customer_Type is `professionnel`, THE Email_Flow SHALL include the company name and SIRET in the client-information blocks.
5. WHEN a submission succeeds, THE Submission_Handler SHALL ensure the Reference returned to the client, the Reference stored on the request row, and the Reference embedded in the email are identical.
6. THE Email_Flow SHALL leave the existing product table, Excel-pricing lookup, totals, RIB and conditions block, and image handling unchanged.

### Requirement 7: Admin viewer for quote requests

**User Story:** As a site administrator, I want a dedicated admin page listing all quote requests with their reference numbers, so that I can review and follow up on customer requests.

#### Acceptance Criteria

1. THE Admin_Viewer SHALL be registered as an admin menu page gated by the `manage_options` capability.
2. WHEN an administrator with `manage_options` opens the Admin_Viewer, THE Admin_Viewer SHALL render all rows from the `{prefix}wishlist_devis_requests` table ordered newest first by `created_at`.
3. WHEN the Admin_Viewer renders a request, THE Admin_Viewer SHALL display the Reference, creation date, Customer_Type, full name or company name, email, phone, address, and a product count or summary.
4. WHEN the Admin_Viewer outputs request data, THE Admin_Viewer SHALL escape all dynamic values using `esc_html` or `esc_attr`.

### Requirement 8: Database table installation

**User Story:** As a site administrator, I want the required database tables created automatically when the plugin is activated, so that the feature works without manual database setup.

#### Acceptance Criteria

1. WHEN the Plugin is activated, THE Installer SHALL create the `{prefix}wishlist_devis_requests` table and the `{prefix}wishlist_devis_references` table with the documented schema.
2. WHEN the Installer runs against existing tables, THE Installer SHALL reconcile the schema without deleting or altering existing row values.
3. THE Reference_Store SHALL enforce a uniqueness constraint on the Reference column so that no two rows share the same Reference.

### Requirement 9: Professional and individual data invariant

**User Story:** As a supplier, I want stored records to consistently reflect whether each customer is a professional or an individual, so that company information is present exactly when it applies.

#### Acceptance Criteria

1. WHERE a persisted request has Customer_Type `professionnel`, THE Request_Store SHALL ensure the stored company name and SIRET are non-empty.
2. WHERE a persisted request has Customer_Type `particulier`, THE Request_Store SHALL ensure the stored company name and SIRET are empty.
