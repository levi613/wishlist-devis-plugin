<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Type de client.
 * 'particulier'   => particulier (nom de société + SIRET masqués/vides)
 * 'professionnel' => professionnel (nom de société + SIRET attendus)
 */
if (!defined('WD_CUSTOMER_TYPE_INDIVIDUAL')) {
    define('WD_CUSTOMER_TYPE_INDIVIDUAL', 'particulier');
}
if (!defined('WD_CUSTOMER_TYPE_PROFESSIONAL')) {
    define('WD_CUSTOMER_TYPE_PROFESSIONAL', 'professionnel');
}

// Statuts de devis
if (!defined('WD_DB_VERSION')) {
    define('WD_DB_VERSION', '1.2');
}

/**
 * Retourne la liste ordonnée des statuts de devis disponibles.
 *
 * @return array<string,string> Tableau slug => libellé français.
 */
function wishlist_devis_get_statuses()
{
    return array(
        'a_envoyer'           => 'À envoyer',
        'envoye'              => 'Envoyé',
        'en_attente_reponse'  => 'En attente de réponse',
        'en_attente_paiement' => 'En attente de paiement',
        'commande_passee'     => 'Commande passée',
        'annule'              => 'Annulé',
    );
}

/**
 * Retourne le libellé français d'un slug de statut.
 *
 * @param string $slug Slug du statut.
 * @return string Libellé, ou le slug original si inconnu.
 */
function wishlist_devis_status_label($slug)
{
    $statuses = wishlist_devis_get_statuses();
    return isset($statuses[$slug]) ? $statuses[$slug] : esc_html($slug);
}

/**
 * Crée (ou réconcilie) les tables personnalisées du plugin sur activation.
 *
 * Crée deux tables :
 *  - {prefix}wishlist_devis_requests   : une ligne par demande de devis.
 *  - {prefix}wishlist_devis_references : mapping stable email -> référence 4 chiffres.
 *
 * Idempotent : un nouvel appel sur des tables existantes laisse dbDelta()
 * réconcilier le schéma sans supprimer ni modifier les lignes existantes.
 *
 * @return void
 */
function wishlist_devis_install_tables()
{
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();

    $requests_table   = $wpdb->prefix . 'wishlist_devis_requests';
    $references_table = $wpdb->prefix . 'wishlist_devis_references';

    // Table 1 : demandes de devis (une ligne par soumission).
    $sql_requests = "CREATE TABLE {$requests_table} (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        reference     CHAR(4)         NOT NULL,
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
        products      LONGTEXT        NOT NULL,
        status        VARCHAR(40)     NOT NULL DEFAULT 'a_envoyer',
        created_at    DATETIME        NOT NULL,
        PRIMARY KEY  (id),
        KEY email (email),
        KEY reference (reference)
    ) {$charset_collate};";

    // Table 2 : mapping stable email -> référence (source de vérité de la numérotation).
    $sql_references = "CREATE TABLE {$references_table} (
        email      VARCHAR(190) NOT NULL,
        reference  CHAR(4)      NOT NULL,
        created_at DATETIME     NOT NULL,
        PRIMARY KEY  (email),
        UNIQUE KEY reference (reference)
    ) {$charset_collate};";

    dbDelta($sql_requests);
    dbDelta($sql_references);
}

/**
 * Nombre maximal de tentatives d'allocation d'une référence en cas de
 * collision sur la clé unique (allocations concurrentes).
 */
if (!defined('WD_REFERENCE_MAX_ATTEMPTS')) {
    define('WD_REFERENCE_MAX_ATTEMPTS', 5);
}

/**
 * Récupère la référence existante d'un email, ou en alloue une nouvelle.
 *
 * Comportement :
 *  - Chemin rapide : si l'email possède déjà une référence, elle est renvoyée
 *    telle quelle sans insertion.
 *  - Sinon, dans une transaction : relecture verrouillée (SELECT ... FOR UPDATE),
 *    puis SELECT MAX(CAST(reference AS UNSIGNED)), calcul de next = max + 1
 *    (ou 1 si la table est vide), formatage sur 4 chiffres avec zéros de tête,
 *    puis insertion. En cas de collision sur la clé unique, nouvelle tentative
 *    (dans la limite de WD_REFERENCE_MAX_ATTEMPTS).
 *  - Si next dépasse 9999, aucune insertion : renvoie le sentinelle d'erreur ''
 *    et journalise via error_log().
 *
 * La première référence émise sur une table vide est "0001".
 *
 * @param string $email Email assaini, en minuscules, non vide.
 * @return string Référence 4 chiffres (/^\d{4}$/), ou '' en cas d'échec.
 */
function wishlist_devis_get_or_create_reference($email)
{
    global $wpdb;

    $references_table = $wpdb->prefix . 'wishlist_devis_references';

    // Chemin rapide : l'email a déjà une référence.
    $existing = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT reference FROM {$references_table} WHERE email = %s",
            $email
        )
    );
    if ($existing !== null) {
        return $existing;
    }

    $attempts = 0;
    while ($attempts < WD_REFERENCE_MAX_ATTEMPTS) {
        $wpdb->query('START TRANSACTION');

        // Relecture verrouillée : une autre requête a pu insérer entre-temps.
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT reference FROM {$references_table} WHERE email = %s FOR UPDATE",
                $email
            )
        );
        if ($existing !== null) {
            $wpdb->query('COMMIT');
            return $existing;
        }

        // Plus grande référence actuelle (NULL si table vide).
        $max_ref = $wpdb->get_var(
            "SELECT MAX(CAST(reference AS UNSIGNED)) FROM {$references_table}"
        );
        if ($max_ref === null) {
            $next_num = 1; // première référence jamais émise
        } else {
            $next_num = (int) $max_ref + 1;
        }

        // Espace de numérotation épuisé (format 4 chiffres).
        if ($next_num > 9999) {
            $wpdb->query('ROLLBACK');
            error_log(
                'wishlist_devis_get_or_create_reference: espace de référence épuisé '
                    . '(limite 9999) pour l\'email ' . $email
            );
            return '';
        }

        $reference = str_pad((string) $next_num, 4, '0', STR_PAD_LEFT);

        $inserted = $wpdb->insert(
            $references_table,
            array(
                'email'      => $email,
                'reference'  => $reference,
                'created_at' => current_time('mysql', true),
            ),
            array('%s', '%s', '%s')
        );

        if ($inserted) {
            $wpdb->query('COMMIT');
            return $reference;
        }

        // Collision sur la clé unique (reference ou email) : on annule et on retente.
        $wpdb->query('ROLLBACK');
        $attempts++;
    }

    error_log(
        'wishlist_devis_get_or_create_reference: allocation impossible après '
            . WD_REFERENCE_MAX_ATTEMPTS . ' tentatives pour l\'email ' . $email
    );
    return '';
}

/**
 * Valide et assainit la charge utile (payload) d'une soumission de devis.
 *
 * Fonction pure vis-à-vis de la base de données : aucune écriture n'est
 * effectuée. Elle renvoie une structure ValidationResult :
 *
 *   array{
 *       valid:  bool,
 *       errors: array<string,string>,  // champ => message français
 *       data:   array|null             // payload assaini si valide, sinon null
 *   }
 *
 * Règles de validité :
 *  - customer_type ∈ { particulier, professionnel } ;
 *  - full_name : chaîne non vide après trim ;
 *  - email : non vide et valide selon is_email() ;
 *  - products : tableau non vide dont chaque produit a un name non vide
 *    et une quantity >= 1 ;
 *  - si professionnel : company_name et siret tous deux non vides.
 *
 * Assainissement appliqué lorsque la soumission est valide :
 *  - email        : sanitize_email() puis strtolower() ;
 *  - champs texte : sanitize_text_field() ;
 *  - customer_type: restreint à l'une des constantes autorisées ;
 *  - siret        : normalisé en chiffres uniquement (conservé en chaîne) ;
 *  - pour un particulier : company_name et siret forcés à '' quelle que
 *    soit la valeur soumise ;
 *  - chaque produit : name via sanitize_text_field(), quantity via intval()
 *    avec un minimum de 1.
 *
 * @param array $payload Corps de requête JSON décodé (clés manquantes/en trop possibles).
 * @return array ValidationResult.
 */
function wishlist_devis_validate_submission($payload)
{
    $errors = array();

    if (!is_array($payload)) {
        $payload = array();
    }

    // --- customer_type ---
    $customer_type_raw = isset($payload['customer_type']) ? $payload['customer_type'] : '';
    $allowed_types     = array(WD_CUSTOMER_TYPE_INDIVIDUAL, WD_CUSTOMER_TYPE_PROFESSIONAL);
    if (!is_string($customer_type_raw) || !in_array($customer_type_raw, $allowed_types, true)) {
        $customer_type_raw = WD_CUSTOMER_TYPE_INDIVIDUAL;
    }

    // --- full_name ---
    $full_name_raw = isset($payload['full_name']) ? $payload['full_name'] : '';

    // --- email ---
    $email_raw = isset($payload['email']) ? $payload['email'] : '';
    $email_trimmed = is_string($email_raw) ? trim($email_raw) : '';
    if ($email_trimmed !== '' && !is_email($email_trimmed)) {
        $errors['email'] = 'Le format de l\'adresse email est invalide.';
    }

    // --- produits ---
    $products_raw = isset($payload['products']) ? $payload['products'] : null;
    if (!is_array($products_raw) || count($products_raw) === 0) {
        $errors['products'] = 'La liste des produits ne peut pas être vide.';
    } else {
        // Invariant : tous les produits avant l'indice courant sont valides ;
        // dès qu'un produit invalide est rencontré, on signale l'erreur et on
        // arrête la validation produit par produit.
        foreach ($products_raw as $product) {
            $name_ok = is_array($product)
                && isset($product['name'])
                && is_string($product['name'])
                && trim($product['name']) !== '';

            $quantity = (is_array($product) && isset($product['quantity']))
                ? intval($product['quantity'])
                : 0;
            $quantity_ok = $quantity >= 1;

            if (!$name_ok || !$quantity_ok) {
                $errors['products'] = 'Chaque produit doit avoir un nom et une quantité d\'au moins 1.';
                break;
            }
        }
    }

    // --- champs société (professionnel uniquement) ---
    $company_name_raw = isset($payload['company_name']) ? $payload['company_name'] : '';
    $siret_raw        = isset($payload['siret']) ? $payload['siret'] : '';
    if (is_string($customer_type_raw) && $customer_type_raw === WD_CUSTOMER_TYPE_PROFESSIONAL) {
        // Aucun champ n'est obligatoire, même pour un professionnel.
    }

    // En cas d'erreur : pas d'assainissement, data === null.
    if (!empty($errors)) {
        return array(
            'valid'  => false,
            'errors' => $errors,
            'data'   => null,
        );
    }

    // --- assainissement (soumission valide) ---
    $customer_type = $customer_type_raw; // déjà restreint aux constantes autorisées

    $sanitized_products = array();
    foreach ($products_raw as $product) {
        $sanitized_quantity = intval($product['quantity']);
        if ($sanitized_quantity < 1) {
            $sanitized_quantity = 1;
        }

        $sanitized_product = array(
            'name'     => sanitize_text_field($product['name']),
            'quantity' => $sanitized_quantity,
        );

        // Conserver les champs additionnels connus en les assainissant.
        if (isset($product['id'])) {
            $sanitized_product['id'] = sanitize_text_field($product['id']);
        }
        if (isset($product['img'])) {
            $sanitized_product['img'] = sanitize_text_field($product['img']);
        }

        $sanitized_products[] = $sanitized_product;
    }

    if ($customer_type === WD_CUSTOMER_TYPE_PROFESSIONAL) {
        $company_name = sanitize_text_field($company_name_raw);
        $siret        = preg_replace('/\D+/', '', $siret_raw);
    } else {
        // Particulier : société et SIRET forcés à vide quelle que soit l'entrée.
        $company_name = '';
        $siret        = '';
    }

    $data = array(
        'customer_type' => $customer_type,
        'company_name'  => $company_name,
        'siret'         => $siret,
        'full_name'     => sanitize_text_field($full_name_raw),
        'email'         => strtolower(sanitize_email($email_raw)),
        'phone'         => sanitize_text_field(isset($payload['phone']) ? $payload['phone'] : ''),
        'country'       => sanitize_text_field(isset($payload['country']) ? $payload['country'] : ''),
        'postal_code'   => sanitize_text_field(isset($payload['postal_code']) ? $payload['postal_code'] : ''),
        'city'          => sanitize_text_field(isset($payload['city']) ? $payload['city'] : ''),
        'address'       => sanitize_text_field(isset($payload['address']) ? $payload['address'] : ''),
        'products'      => $sanitized_products,
    );

    return array(
        'valid'  => true,
        'errors' => array(),
        'data'   => $data,
    );
}

/**
 * Persiste une demande de devis validée dans {prefix}wishlist_devis_requests.
 *
 * Insère exactement une ligne via $wpdb->insert() avec des spécificateurs de
 * format explicites. Les produits sont stockés au format JSON via
 * wp_json_encode(), et created_at est positionné à la date/heure MySQL UTC
 * courante (current_time('mysql', true)). La référence passée en argument
 * (obtenue depuis wishlist_devis_get_or_create_reference()) est stockée telle
 * quelle, de sorte qu'un même email donne toujours la même référence.
 *
 * Les données fournies sont déjà assainies par le validateur : l'invariant
 * professionnel/particulier (company_name/siret non vides pour un
 * professionnel, vides pour un particulier) est simplement persisté tel quel,
 * sans nouvelle transformation. Des valeurs par défaut (chaîne vide) sont
 * utilisées pour les champs optionnels manquants.
 *
 * @param array  $data      Payload assaini (DevisPayload) issu du validateur.
 * @param string $reference Référence 4 chiffres (/^\d{4}$/) déjà allouée.
 * @return int Id auto-incrémenté de la ligne insérée (> 0), ou 0 en cas d'échec.
 */
function wishlist_devis_save_request($data, $reference)
{
    global $wpdb;

    $requests_table = $wpdb->prefix . 'wishlist_devis_requests';

    if (!is_array($data)) {
        $data = array();
    }

    $products = isset($data['products']) ? $data['products'] : array();

    $row = array(
        'reference'     => $reference,
        'customer_type' => isset($data['customer_type']) ? $data['customer_type'] : '',
        'company_name'  => isset($data['company_name']) ? $data['company_name'] : '',
        'siret'         => isset($data['siret']) ? $data['siret'] : '',
        'full_name'     => isset($data['full_name']) ? $data['full_name'] : '',
        'email'         => isset($data['email']) ? $data['email'] : '',
        'phone'         => isset($data['phone']) ? $data['phone'] : '',
        'country'       => isset($data['country']) ? $data['country'] : '',
        'postal_code'   => isset($data['postal_code']) ? $data['postal_code'] : '',
        'city'          => isset($data['city']) ? $data['city'] : '',
        'address'       => isset($data['address']) ? $data['address'] : '',
        'products'      => wp_json_encode($products),
        'status'        => 'a_envoyer',
        'created_at'    => current_time('mysql', true),
    );

    $formats = array(
        '%s', // reference
        '%s', // customer_type
        '%s', // company_name
        '%s', // siret
        '%s', // full_name
        '%s', // email
        '%s', // phone
        '%s', // country
        '%s', // postal_code
        '%s', // city
        '%s', // address
        '%s', // products
        '%s', // status
        '%s', // created_at
    );

    $inserted = $wpdb->insert($requests_table, $row, $formats);

    if ($inserted === false) {
        return 0;
    }

    return (int) $wpdb->insert_id;
}

// Ajouter l'action AJAX
add_action('wp_ajax_send_devis', 'wishlist_devis_send_email');
add_action('wp_ajax_nopriv_send_devis', 'wishlist_devis_send_email');

function wishlist_devis_send_email()
{
    // mettre date au fuseau de Paris
    date_default_timezone_set('Europe/Paris');

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = array();
    }

    // Validation + sanitisation : remplace l'ancien garde bogué utilisant `&&`.
    $result = wishlist_devis_validate_submission($payload);
    if (empty($result['valid'])) {
        wp_send_json(array(
            'message' => 'Veuillez corriger les champs indiqués.',
            'errors'  => $result['errors'],
        ), 400);
        return;
    }

    // Données assainies issues du validateur.
    $data = $result['data'];

    // Allocation de la référence (réutilisée si l'email est déjà connu).
    // Si email vide, on alloue une clé technique unique pour ne pas bloquer la demande.
    $reference_email_key = $data['email'];
    if ($reference_email_key === '') {
        $reference_email_key = 'anon+' . current_time('timestamp', true) . '+' . wp_generate_password(8, false, false) . '@noemail.local';
    }

    $reference = wishlist_devis_get_or_create_reference($reference_email_key);
    if ($reference === '') {
        wp_send_json(array('message' => 'Numéro de référence indisponible.'), 500);
        return;
    }

    // Propager la référence dans $data pour que le générateur .docx
    // (wishlist_devis_generate_word) puisse l'afficher dans le bloc client.
    $data['reference'] = $reference;

    // Mapping de compatibilité : le code existant (sujet, email, .docx) lit
    // $data['name'], mais le nouveau formulaire envoie full_name.
    $data['name'] = $data['full_name'];

    // Persistance de la demande.
    $rowId = wishlist_devis_save_request($data, $reference);
    if ($rowId === 0) {
        wp_send_json(array('message' => "Échec de l'enregistrement de la demande."), 500);
        return;
    }

    $configured_admins = get_option('wishlist_devis_admin_email');
    $admin_recipients  = array();

    if (is_string($configured_admins) && trim($configured_admins) !== '') {
        $raw_admins = preg_split('/[\s,;]+/', $configured_admins);
        foreach ($raw_admins as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && is_email($candidate)) {
                $admin_recipients[] = strtolower($candidate);
            }
        }
    }

    // Fallback explicite : les 2 admins attendus reçoivent aussi les demandes.
    $admin_recipients[] = 'jbastierdevillatte@gmail.com';
    $admin_recipients[] = 'levibelhamou@gmail.com';

    $admin_recipients = array_values(array_unique($admin_recipients));
    if (empty($admin_recipients)) {
        wp_send_json(array('message' => "Aucun email administrateur n'est configuré."), 500);
        return;
    }


    $subject = "Nouvelle demande de devis - " . $data['name'];

    // Libellé français du type de client et indicateur professionnel.
    $is_professional     = ($data['customer_type'] === WD_CUSTOMER_TYPE_PROFESSIONAL);
    $customer_type_label = $is_professional ? 'Professionnel' : 'Particulier';

    // Construire un email HTML professionnel
    $html_message = '
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
        <title>Nouvelle demande de devis</title>
        <style>
            body { 
                font-family: Arial, sans-serif; 
                line-height: 1.6; 
                color: #333333; 
                max-width: 650px; 
                margin: 0 auto;
            }
            .header { 
                background-color: #2B579A; 
                padding: 20px; 
                color: white; 
                text-align: center;
            }
            .content { 
                padding: 20px; 
                background-color: #F9F9F9;
            }
            .client-info {
                background-color: #ffffff;
                border-left: 3px solid #2B579A;
                padding: 15px;
                margin-bottom: 20px;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin: 20px 0;
            }
            th {
                background-color: #2B579A;
                color: white;
                text-align: left;
                padding: 10px;
            }
            td {
                padding: 8px 10px;
                border-bottom: 1px solid #ddd;
            }
            tr:nth-child(even) {
                background-color: #f2f2f2;
            }
            .footer {
                font-size: 12px;
                text-align: center;
                margin-top: 30px;
                padding-top: 10px;
                border-top: 1px solid #eeeeee;
                color: #777777;
            }
        </style>
    </head>
    <body>
        <div class="header">
            <h2>Nouvelle demande de devis</h2>
        </div>
        <div class="content">
            <p>Bonjour,</p>
            <p>Une nouvelle demande de devis a été reçue via votre site web.</p>
            
            <div class="client-info">
                <h3>Informations client :</h3>
                <p><strong>Référence :</strong> ' . esc_html($reference) . '</p>
                <p><strong>Type de client :</strong> ' . esc_html($customer_type_label) . '</p>'
        . ($is_professional ? '
                <p><strong>Société :</strong> ' . esc_html($data['company_name']) . '</p>
                <p><strong>SIRET :</strong> ' . esc_html($data['siret']) . '</p>' : '') . '
                <p><strong>Nom :</strong> ' . esc_html($data['full_name']) . '</p>
                <p><strong>Email :</strong> ' . esc_html($data['email']) . '</p>
                <p><strong>Téléphone :</strong> ' . esc_html($data['phone']) . '</p>
                <p><strong>Adresse :</strong> ' . esc_html($data['address']) . '</p>
                <p><strong>Code postal :</strong> ' . esc_html($data['postal_code']) . '</p>
                <p><strong>Ville :</strong> ' . esc_html($data['city']) . '</p>
                <p><strong>Pays :</strong> ' . esc_html($data['country']) . '</p>
                <p><strong>Date de la demande :</strong> ' . date('d/m/Y à H:i') . '</p>
            </div>
            
            <h3>Produits demandés :</h3>
            <table>
                <tr>
                    <th>Référence</th>
                    <th>Quantité</th>
                </tr>';


    // Ajouter chaque produit
    foreach ($data['products'] as $product) {
        $quantity = isset($product['quantity']) ? intval($product['quantity']) : 1;
        $html_message .= '
                <tr>
                    <td>' . $product['name'] . '</td>
                    <td>' . $quantity . '</td>
                </tr>';
    }

    $html_message .= '
            </table>
            
            <p>Le devis au format Excel est joint à cet email.</p>
            
            <p>Cordialement,<br>Le système automatique de devis</p>
        </div>
        <div class="footer">
            <p>Ce message a été généré automatiquement par le site <a href="https://www.jbadev.com/">https://www.jbadev.com/</a></p>
            <p>&copy; ' . date('Y') . ' JB AdeV. Tous droits réservés.</p>
        </div>
    </body>
    </html>';

    // Version texte brut comme fallback pour les clients email qui ne supportent pas l'HTML
    $text_message = "Nouvelle demande de devis\n\n";
    $text_message .= "Informations client :\n";
    $text_message .= "Référence : " . $reference . "\n";
    $text_message .= "Type de client : " . $customer_type_label . "\n";
    if ($is_professional) {
        $text_message .= "Société : " . $data['company_name'] . "\n";
        $text_message .= "SIRET : " . $data['siret'] . "\n";
    }
    $text_message .= "Nom : " . $data['full_name'] . "\n";
    $text_message .= "Email : " . $data['email'] . "\n";
    $text_message .= "Téléphone : " . $data['phone'] . "\n";
    $text_message .= "Adresse : " . $data['address'] . "\n";
    $text_message .= "Code postal : " . $data['postal_code'] . "\n";
    $text_message .= "Ville : " . $data['city'] . "\n";
    $text_message .= "Pays : " . $data['country'] . "\n";
    $text_message .= "Date de la demande : " . date('d/m/Y à H:i') . "\n\n";
    $text_message .= "Produits demandés :\n";

    foreach ($data['products'] as $product) {
        $quantity = isset($product['quantity']) ? intval($product['quantity']) : 1;
        $text_message .= "- " . $product['name'] . " (Quantité: " . $quantity . ")\n";
    }

    $text_message .= "\nLe devis au format Excel est joint à cet email.\n";
    $text_message .= "Cordialement,\nLe système automatique de devis";

    // Génération du devis en Excel (.xlsx)
    $data['created_at'] = current_time('mysql');
    $file_path = wishlist_devis_generate_excel($data['products'], $data, $rowId);
    if ($file_path === '' || !file_exists($file_path)) {
        wp_send_json(array('message' => "Demande enregistrée (réf. $reference), mais le fichier Excel n'a pas pu être généré."), 500);
        return;
    }

    // Envoi de l'email avec pièce jointe
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: JB AdeV - Site web <wordpress@jbadev.com>'
    ];

    if (!empty($data['email'])) {
        $headers[] = 'Reply-To: ' . $data['email'];
    }

    $attachments = [$file_path];

    // Envoi en un seul mail à tous les admins avec la pièce jointe.
    $mail_sent = wp_mail($admin_recipients, $subject, $html_message, $headers, $attachments);

    if ($mail_sent) {
        wp_send_json(['message' => "Votre demande (réf. $reference) a bien été envoyée."]);
    } else {
        wp_send_json(['message' => "Demande enregistrée (réf. $reference), mais l'email n'a pas pu être envoyé."], 500);
    }
}

// Fonction pour générer le devis au format Word (.docx)
function wishlist_devis_generate_word($products, $data)
{
    // Vérifier si PHPWord est installé, sinon l'inclure
    if (!class_exists('PhpOffice\PhpWord\PhpWord')) {
        require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';
    }

    // Créer un nouveau document PHPWord
    $phpWord = new \PhpOffice\PhpWord\PhpWord();

    // Ajouter des styles au document - Version améliorée
    $fontStyle = ['name' => 'Arial', 'size' => 11];
    $headerStyle = ['name' => 'Arial', 'size' => 22, 'bold' => true, 'color' => '2B579A'];
    $subHeaderStyle = ['name' => 'Arial', 'size' => 14, 'bold' => true, 'color' => '2B579A'];
    $tableHeaderStyle = ['name' => 'Arial', 'size' => 11, 'bold' => true, 'color' => 'FFFFFF'];
    $redTextStyle = ['name' => 'Arial', 'size' => 12, 'bold' => true, 'color' => 'FF0000'];
    $smallRedTextStyle = ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => 'FF0000'];
    $madeInFranceStyle = ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => '2B579A'];

    $tableStyle = [
        'borderSize' => 8,
        'borderColor' => '2B579A',
        'cellMargin' => 120,
        'alignment' => \PhpOffice\PhpWord\SimpleType\JcTable::CENTER
    ];
    $cellStyle = ['valign' => 'center'];
    $cellBoldStyle = ['valign' => 'center', 'bgColor' => '2B579A'];

    // Définir les propriétés du document
    $properties = $phpWord->getDocInfo();
    $properties->setCreator('JB AdeV');
    $properties->setCompany('JB AdeV');
    $properties->setTitle('Devis Professionnel');
    $properties->setDescription('Devis généré pour ' . $data['name']);
    $properties->setCategory('Devis');

    // Ajouter une section au document avec des marges optimisées
    $section = $phpWord->addSection([
        'marginTop' => 800,
        'marginRight' => 1000,
        'marginBottom' => 1000,
        'marginLeft' => 1000,
    ]);

    // Ajouter un en-tête professionnel avec le logo
    $header = $section->addHeader();
    $headerTable = $header->addTable();
    $headerTable->addRow();

    // Logo à gauche
    $logoCell = $headerTable->addCell(3000);
    $logo_path = plugin_dir_path(__FILE__) . 'assets/logo.png';

    // Si le logo n'existe pas, le télécharger
    if (!file_exists($logo_path)) {
        $logo_url = 'https://www.jbadev.com/wp-content/uploads/2025/03/images.png';
        $logo_data = file_get_contents($logo_url);
        if ($logo_data) {
            // Créer le dossier assets s'il n'existe pas
            if (!file_exists(plugin_dir_path(__FILE__) . 'assets')) {
                mkdir(plugin_dir_path(__FILE__) . 'assets', 0755, true);
            }
            file_put_contents($logo_path, $logo_data);
        }
    }

    if (file_exists($logo_path)) {
        $logoCell->addImage($logo_path, ['width' => 120]);
    }

    // Informations entreprise à droite - Version améliorée
    $companyCell = $headerTable->addCell(7000);
    $companyCell->addText('JB AdeV, créations depuis 1993', ['bold' => true, 'size' => 16, 'color' => '2B579A']);
    $companyCell->addText('éditeur exclusif des œuvres originales de Jean-Baptiste Astier de Villatte', ['size' => 11, 'italic' => true]);
    $companyCell->addTextBreak(1);
    $companyCell->addText('RCS: 342 131 943 00082 | n° de TVA FR 41342131943', ['size' => 10, 'color' => '666666']);
    $companyCell->addText('15 bis rue du Capitaine le Drezen, 29730 TREFFIAGAT FRANCE', ['size' => 10, 'color' => '666666']);
    $companyCell->addText('Tél: +33 7 86 30 26 76 | Email: jbastierdevillatte@gmail.com', ['size' => 10, 'color' => '666666']);

    // Ajouter un séparateur élégant après l'en-tête
    $section->addTextBreak(1);
    $section->addLine(['weight' => 2, 'width' => 450, 'height' => 0, 'color' => '2B579A']);
    $section->addTextBreak(1);

    // Titre du document avec numéro de devis et mention "À confirmer"
    $date = new DateTime();
    $formatter = new IntlDateFormatter('fr_FR', IntlDateFormatter::LONG, IntlDateFormatter::NONE);

    // Créer un tableau pour aligner proforma et "À confirmer"
    $titleTable = $section->addTable();
    $titleTable->addRow();
    $titleTable->addCell(5000)->addText('proforma n°: ' . date('Y m'), $headerStyle, ['alignment' => 'left']);
    $titleTable->addCell(5000)->addText('À CONFIRMER', $redTextStyle, ['alignment' => 'right']);

    // Ligne contenant la date (centrée) et le coq + "MADE IN FRANCE"
    $topTable = $section->addTable(['alignment' => 'center']);
    $topTable->addRow();

    // Cellule pour la date
    $dateCell = $topTable->addCell(7000);
    $dateCell->addText(
        'date: ' . $formatter->format($date),
        ['name' => 'Arial', 'size' => 12, 'bold' => true],
        ['alignment' => 'center']
    );

    // Cellule pour l'image du coq + texte dessous
    $coqCell = $topTable->addCell(3000, ['valign' => 'center']);

    // Ajouter l'image du coq
    $coq_path = plugin_dir_path(__FILE__) . 'assets/coq.jpg';
    if (file_exists($coq_path)) {
        $coqCell->addImage($coq_path, ['width' => 50, 'alignment' => 'center']);
    }

    // Ajouter le texte "MADE IN FRANCE" sous le coq
    $coqCell->addText(
        'MADE IN FRANCE',
        $madeInFranceStyle,
        ['alignment' => 'center']
    );

    $section->addTextBreak(1);

    // Ajouter les informations du client dans un cadre professionnel
    $clientInfoTable = $section->addTable(['borderSize' => 2, 'borderColor' => '2B579A', 'cellMargin' => 80]);
    $clientInfoTable->addRow();
    $clientCell = $clientInfoTable->addCell(9000, ['bgColor' => 'F8F9FA']);
    $clientCell->addText('INFORMATIONS CLIENT', ['name' => 'Arial', 'size' => 13, 'bold' => true, 'color' => '2B579A']);
    // $clientCell->addTextBreak(1);
    $wd_label_style         = ['name' => 'Arial', 'size' => 12];
    $wd_bold_style          = ['name' => 'Arial', 'size' => 12, 'bold' => true];
    $wd_customer_type       = isset($data['customer_type']) ? $data['customer_type'] : '';
    $wd_customer_type_label = ($wd_customer_type === WD_CUSTOMER_TYPE_PROFESSIONAL) ? 'Professionnel' : 'Particulier';

    if (isset($data['reference']) && $data['reference'] !== '') {
        $clientCell->addText('Référence: ' . $data['reference'], $wd_bold_style);
    }
    $clientCell->addText('Type de client: ' . $wd_customer_type_label, $wd_label_style);
    if ($wd_customer_type === WD_CUSTOMER_TYPE_PROFESSIONAL) {
        $clientCell->addText('Société: ' . (isset($data['company_name']) ? $data['company_name'] : ''), $wd_label_style);
        $clientCell->addText('SIRET: ' . (isset($data['siret']) ? $data['siret'] : ''), $wd_label_style);
    }
    $wd_full_name = isset($data['full_name']) ? $data['full_name'] : (isset($data['name']) ? $data['name'] : '');
    $clientCell->addText('Nom: ' . $wd_full_name, $wd_bold_style);
    $clientCell->addText('Email: ' . $data['email'], $wd_label_style);
    $clientCell->addText('Téléphone: ' . (isset($data['phone']) ? $data['phone'] : ''), $wd_label_style);
    $clientCell->addText('Adresse: ' . (isset($data['address']) ? $data['address'] : ''), $wd_label_style);
    $clientCell->addText('Code postal: ' . (isset($data['postal_code']) ? $data['postal_code'] : ''), $wd_label_style);
    $clientCell->addText('Ville: ' . (isset($data['city']) ? $data['city'] : ''), $wd_label_style);
    $clientCell->addText('Pays: ' . (isset($data['country']) ? $data['country'] : ''), $wd_label_style);
    $section->addTextBreak(2);

    // Récupérer les données du fichier Excel
    $excelData = get_excel_data();

    // Créer une table pour les produits avec un style amélioré
    $table = $section->addTable($tableStyle);

    // En-têtes de la table avec fond coloré
    $table->addRow(600); // Hauteur de ligne fixe pour l'en-tête
    $table->addCell(2000, $cellBoldStyle)->addText('Créateur', $tableHeaderStyle);
    $table->addCell(2000, $cellBoldStyle)->addText('Référence', $tableHeaderStyle);
    $table->addCell(2800, $cellBoldStyle)->addText('Désignation', $tableHeaderStyle);
    $table->addCell(1100, $cellBoldStyle)->addText('Qu.', $tableHeaderStyle);
    $table->addCell(1000, $cellBoldStyle)->addText('tarif unitaire', $tableHeaderStyle);
    $table->addCell(1200, $cellBoldStyle)->addText('Total', $tableHeaderStyle);
    $table->addCell(1800, $cellBoldStyle)->addText('Image', $tableHeaderStyle);

    // Parcourir les produits (code existant maintenu)
    $totalHT = 0;
    $rowCount = 0;

    $all_references = [];
    $all_references_quantities = [];
    $all_references_images = [];
    $all_references_is_single = [];

    // Première passe: identifier les références qui apparaissent seules dans un produit
    foreach ($products as $product) {
        $product_name = trim($product['name']);
        $product_image = isset($product['img']) ? $product['img'] : 'N/A';

        $temp_references = [];

        if (strpos($product_name, '&') !== false) {
            $temp_references = array_map('trim', explode('&', $product_name));
        } else {
            $pattern = '/([A-Za-z]{1,2}(?:\s+\d+){2,4})/';
            if (preg_match_all($pattern, $product_name, $matches)) {
                $temp_references = $matches[0];
            } else {
                $temp_references = [$product_name];
            }
        }

        if (count($temp_references) === 1) {
            $ref = trim($temp_references[0]);

            if (!empty($ref) && strlen($ref) > 0) {
                if (preg_match('/^([A-Za-z]{1,2})/', $ref, $matches)) {
                    $prefix = $matches[1];
                    $standardized_ref = strtoupper($prefix) . substr($ref, strlen($prefix));
                } else {
                    $standardized_ref = $ref;
                }
            } else {
                $standardized_ref = $ref;
            }

            $ref_components = preg_split('/\s+/', $standardized_ref);
            $standard_key = implode('_', $ref_components);

            $all_references_is_single[$standard_key] = true;
            $all_references_images[$standard_key] = $product_image;
        }
    }

    // Deuxième passe: traiter toutes les références et gérer les doublons
    foreach ($products as $product) {
        $quantity = isset($product['quantity']) ? intval($product['quantity']) : 1;
        $product_name = trim($product['name']);
        $product_image = isset($product['img']) ? $product['img'] : 'N/A';

        $product_name = trim($product['name']);
        $temp_references = [];
        $unique_references = [];
        $reference_quantities = [];

        if (strpos($product_name, '&') !== false) {
            $temp_references = array_map('trim', explode('&', $product_name));
        } else {
            $pattern = '/([A-Za-z](?:\s+\d+){2,4})/';

            if (preg_match_all($pattern, $product_name, $matches)) {
                $temp_references = $matches[0];
            } else {
                $temp_references = [$product_name];
            }
        }

        foreach ($temp_references as $ref) {
            $ref = trim($ref);

            if (!empty($ref) && strlen($ref) > 0) {
                if (preg_match('/^([A-Za-z]{1,2})/', $ref, $matches)) {
                    $prefix = $matches[1];
                    $standardized_ref = strtoupper($prefix) . substr($ref, strlen($prefix));
                } else {
                    $standardized_ref = $ref;
                }
            } else {
                $standardized_ref = $ref;
            }

            $ref_components = preg_split('/\s+/', $standardized_ref);
            $standard_key = implode('_', $ref_components);

            if (isset($all_references[$standard_key])) {
                $all_references_quantities[$standard_key] += $quantity;
            } else {
                $all_references[$standard_key] = $standardized_ref;
                $all_references_quantities[$standard_key] = $quantity;

                if (!isset($all_references_images[$standard_key])) {
                    $all_references_images[$standard_key] = $product_image;
                }

                $unique_references[$standard_key] = $standardized_ref;
                $reference_quantities[$standard_key] = $quantity;
            }

            if (isset($reference_quantities[$standard_key])) {
                $reference_quantities[$standard_key] += $quantity;
            } else {
                $unique_references[$standard_key] = $standardized_ref;
                $reference_quantities[$standard_key] = $quantity;
            }
        }
    }

    // Génération du tableau avec les références uniques
    $rowCount = 0;
    $totalHT = 0;
    foreach ($all_references as $key => $ref) {
        $ref_quantity = $all_references_quantities[$key];
        $ref_image = $all_references_images[$key];

        $ref_components = preg_split('/\s+/', $ref);

        if (preg_match('/^([A-Za-z]{1,2})/', $ref_components[0], $matches)) {
            $letter = strtoupper($matches[1]);
        } else {
            $letter = strtoupper(substr($ref_components[0], 0, 1));
        }

        $num1 = isset($ref_components[1]) ? $ref_components[1] : '';
        $num2 = isset($ref_components[2]) ? $ref_components[2] : '';
        $num3 = isset($ref_components[3]) ? $ref_components[3] : '';

        $price = '';
        $designation = '';

        foreach ($excelData as $row) {
            if (
                $row['letter'] == $letter &&
                (empty($num1) || $row['num1'] == $num1) &&
                (empty($num2) || $row['num2'] == $num2) &&
                (empty($num3) || $row['num3'] == $num3)
            ) {

                $price = $row['price'];
                $designation = $row['designation'] . ' | ' . $row['collection'] . ' | ' . $row['finition'] . ' | dimensions: ' . $row['dimension'];
                break;
            }
        }

        $rowCount++;
        $cellRowStyle = $cellStyle;
        if ($rowCount % 2 == 0) {
            $cellRowStyle = array_merge($cellStyle, ['bgColor' => 'F8F9FA']);
        }

        $price = str_replace(',', '.', $price);
        $price = floatval($price);
        if ($price === 0) {
            $price = 0;
        }

        $table->addRow();
        $table->addCell(400, $cellRowStyle)->addText('JB AdeV', $fontStyle);
        $table->addCell(1600, $cellRowStyle)->addText($ref, $fontStyle);
        $table->addCell(2800, $cellRowStyle)->addText($designation, $fontStyle);
        $table->addCell(1100, $cellRowStyle)->addText($ref_quantity, $fontStyle);
        $table->addCell(1000, $cellRowStyle)->addText($price . ' €', $fontStyle);

        $lineTotal = $price * $ref_quantity;
        $table->addCell(1200, $cellRowStyle)->addText(number_format($lineTotal, 2, ',', ' ') . ' €', $fontStyle);

        $cell = $table->addCell(1800, $cellRowStyle);
        if (!empty($ref_image) && $ref_image !== 'N/A') {
            $img_path = convert_url_to_path($ref_image);
            if ($img_path) {
                try {
                    $cell->addImage($img_path, ['width' => 80]);
                } catch (Exception $e) {
                    $cell->addText('Image non disponible', $fontStyle);
                }
            } else {
                $cell->addText('Image non disponible', $fontStyle);
            }
        } else {
            $cell->addText('Image non disponible', $fontStyle);
        }

        $totalHT += (float)$price * $ref_quantity;
    }

    // Tableau des totaux amélioré avec nouvelles lignes demandées
    $section->addTextBreak(1);

    $totalTable = $section->addTable([
        'borderSize' => 2,
        'borderColor' => '2B579A',
        'cellMargin' => 100,
        'alignment' => \PhpOffice\PhpWord\SimpleType\JcTable::END
    ]);

    // Total HT
    $totalTable->addRow();
    $totalTable->addCell(3000, ['borderBottomSize' => 1, 'borderBottomColor' => '2B579A', 'bgColor' => 'F8F9FA'])
        ->addText('Total HT', ['bold' => true, 'size' => 12]);
    $totalTable->addCell(2000, ['borderBottomSize' => 1, 'borderBottomColor' => '2B579A', 'bgColor' => 'F8F9FA'])
        ->addText(number_format($totalHT, 2, ',', ' ') . ' €', ['bold' => true, 'size' => 12]);

    // Emballage 5%
    $emballage = $totalHT * 0.05;
    $totalTable->addRow();
    $totalTable->addCell(3000, ['borderBottomSize' => 1, 'borderBottomColor' => '2B579A'])
        ->addText('Emballage 5%', $fontStyle);
    $totalTable->addCell(2000, ['borderBottomSize' => 1, 'borderBottomColor' => '2B579A'])
        ->addText(number_format($emballage, 2, ',', ' ') . ' €', $fontStyle);

    // Nouveau total HT avec emballage
    $totalHTAvecEmballage = $totalHT + $emballage;
    $totalTable->addRow();
    $totalTable->addCell(3000, ['borderBottomSize' => 1, 'borderBottomColor' => '2B579A', 'bgColor' => 'F8F9FA'])
        ->addText('Sous-total HT', ['bold' => true, 'size' => 12]);
    $totalTable->addCell(2000, ['borderBottomSize' => 1, 'borderBottomColor' => '2B579A', 'bgColor' => 'F8F9FA'])
        ->addText(number_format($totalHTAvecEmballage, 2, ',', ' ') . ' €', ['bold' => true, 'size' => 12]);

    // TVA
    $tva = $totalHTAvecEmballage * 0.2;
    $totalTable->addRow();
    $totalTable->addCell(3000, ['borderBottomSize' => 1, 'borderBottomColor' => '2B579A'])
        ->addText('TVA (20%)', $fontStyle);
    $totalTable->addCell(2000, ['borderBottomSize' => 1, 'borderBottomColor' => '2B579A'])
        ->addText(number_format($tva, 2, ',', ' ') . ' €', $fontStyle);

    // Total TTC
    $totalTTC = $totalHTAvecEmballage * 1.2;
    $totalTable->addRow();
    $totalTable->addCell(3000, ['borderBottomSize' => 2, 'borderBottomColor' => '2B579A', 'bgColor' => '2B579A'])
        ->addText('Total TTC', ['bold' => true, 'size' => 13, 'color' => 'FFFFFF']);
    $totalTable->addCell(2000, ['borderBottomSize' => 2, 'borderBottomColor' => '2B579A', 'bgColor' => '2B579A'])
        ->addText(number_format($totalTTC, 2, ',', ' ') . ' €', ['bold' => true, 'size' => 13, 'color' => 'FFFFFF']);

    // Acomptes versés
    $totalTable->addRow();
    $totalTable->addCell(3000, ['borderBottomSize' => 1, 'borderBottomColor' => '2B579A'])
        ->addText('Acomptes versés', $fontStyle);
    $totalTable->addCell(2000, ['borderBottomSize' => 1, 'borderBottomColor' => '2B579A'])
        ->addText('0 €', $fontStyle);

    $section->addTextBreak(1);

    // Acompte de confirmation en rouge
    $acompte50 = $totalTTC * 0.5;
    $section->addText('Suivant l\'usage, acompte de confirmation de 50%, en votre aimable règlement: ' .
        number_format($acompte50, 2, ',', ' ') . ' €', $redTextStyle, ['alignment' => 'center']);

    $section->addTextBreak(2);

    // Ajouter les informations bancaires et conditions
    $conditionsTable = $section->addTable(['borderSize' => 2, 'borderColor' => '2B579A', 'cellMargin' => 100]);
    $conditionsTable->addRow();
    $conditionsCell = $conditionsTable->addCell(10000, ['bgColor' => 'FFFACD']);

    $conditionsCell->addText('CONDITIONS DE VENTE:', ['bold' => true, 'size' => 12, 'color' => '2B579A']);
    $conditionsCell->addText('acompte de confirmation à la commande: 50%', ['size' => 11]);
    $conditionsCell->addText('emballage en sus', ['size' => 11]);
    $conditionsCell->addText('transport à la charge du client destinataire', ['size' => 11]);
    $conditionsCell->addText('solde avant livraison par virement swift express', ['size' => 11]);

    $conditionsCell->addTextBreak(1);

    $conditionsCell->addText('RIB:', ['bold' => true, 'size' => 12, 'color' => '2B579A']);
    $conditionsCell->addText('bénéficiaire: Jean-Baptiste Astier de Villatte', ['size' => 11]);
    $conditionsCell->addText('banque: Crédit Agricole du Finistère', ['size' => 11]);
    $conditionsCell->addText('Agence du Guilvinec (00040)', ['size' => 11]);
    $conditionsCell->addText('code IBAN: FR76 1290 6000 4057 4655 8576 747', ['size' => 11, 'bold' => true]);
    $conditionsCell->addText('code BIC: AGRIFRPP829', ['size' => 11]);
    $conditionsCell->addText('code banque: 12906', ['size' => 11]);
    $conditionsCell->addText('code guichet: 00040', ['size' => 11]);
    $conditionsCell->addText('n° de compte: 57465585767', ['size' => 11]);
    $conditionsCell->addText('clé RIB: 47', ['size' => 11]);

    $conditionsCell->addTextBreak(1);
    $conditionsCell->addText(
        'En cas de retard de paiement, seront exigibles, conformément à l\'article L 441-6 du code de commerce, une indemnité calculée sur la base de trois fois le taux de l\'intérêt légal en vigueur ainsi qu\'une indemnité forfaitaire pour frais de recouvrement de 40 euros.',
        ['size' => 10, 'italic' => true]
    );

    // Générer le fichier
    $objWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');

    $upload_dir = wp_upload_dir();
    $file_name = 'Devis_JBAdeV_' . date('Ymd_His') . '.docx';
    $file_path = $upload_dir['path'] . '/' . $file_name;

    $objWriter->save($file_path);

    return $file_path;
}

/**
 * Génère le devis au format Excel (.xlsx) à partir du modèle modele-devis.xlsx.
 *
 * Structure du modèle :
 *   I1  : Référence client (4 chiffres)
 *   E1  : Nom de société (professionnels uniquement)
 *   E3-E9 : Infos client (nom, adresse, CP+ville, pays, téléphone, email, SIRET)
 *   C10 : Numéro de devis (ex : 2026 05 0001) — compteur mensuel remis à zéro
 *   C11 : Date en français long (ex : 26 mai 2026)
 *   À partir de la ligne 14 : produits
 *     - A : Image du produit
 *     - B-F (fusionnées) : 3 lignes : "Référence (Collection)", "Désignation", "Finition et dimension"
 *     - G : Quantité
 *     - H : Prix unitaire
 *     - I : Total
 *   2 lignes sous le dernier produit, colonne I : formule =SUM(I14:I{last})
 *
 * @param array  $products  Tableau de produits (issu de la BDD ou du formulaire).
 * @param array  $data      Données client assainies.
 * @param int    $devis_id  Id BDD du devis (pour le numéro séquentiel mensuel).
 * @return string Chemin absolu du fichier généré, ou '' en cas d'échec.
 */
function wishlist_devis_generate_excel($products, $data, $devis_id = 0)
{
    if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
        require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';
    }

    date_default_timezone_set('Europe/Paris');

    // Charger le modèle
    $template_path = plugin_dir_path(__FILE__) . 'modele-devis.xlsx';
    if (!file_exists($template_path)) {
        error_log('wishlist_devis_generate_excel: modele-devis.xlsx introuvable.');
        return '';
    }

    try {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($template_path);
    } catch (Exception $e) {
        error_log('wishlist_devis_generate_excel: impossible de charger le modèle — ' . $e->getMessage());
        return '';
    }

    $sheet = $spreadsheet->getActiveSheet();

    // ---- Infos client ----
    $reference       = isset($data['reference']) ? $data['reference'] : '';
    $is_professional = (isset($data['customer_type']) && $data['customer_type'] === WD_CUSTOMER_TYPE_PROFESSIONAL);
    $company_name    = $is_professional ? (isset($data['company_name']) ? $data['company_name'] : '') : '';
    $full_name       = isset($data['full_name']) ? $data['full_name'] : (isset($data['name']) ? $data['name'] : '');
    $created_at_str  = isset($data['created_at']) ? $data['created_at'] : current_time('mysql');

    $sheet->setCellValue('I1', $reference);
    $sheet->setCellValue('E1', $company_name);
    $sheet->setCellValue('E3', $full_name);
    $sheet->setCellValue('E4', isset($data['address']) ? $data['address'] : '');
    $postal_city = trim(
        (isset($data['postal_code']) ? $data['postal_code'] : '') . ' ' .
            (isset($data['city']) ? $data['city'] : '')
    );
    $sheet->setCellValue('E5', $postal_city);
    $sheet->setCellValue('E6', isset($data['country']) ? $data['country'] : '');
    $sheet->setCellValue('E7', isset($data['phone']) ? $data['phone'] : '');
    $sheet->setCellValue('E8', isset($data['email']) ? $data['email'] : '');
    if ($is_professional && !empty($data['siret'])) {
        $sheet->setCellValue('E9', 'SIRET : ' . $data['siret']);
    }

    // ---- Numéro de devis (C10) : AAAA MM NNNN — compteur mensuel ----
    global $wpdb;
    $requests_table = $wpdb->prefix . 'wishlist_devis_requests';
    $date_ts        = strtotime($created_at_str);
    $year_month     = date('Y-m', $date_ts);

    if ($devis_id > 0) {
        $monthly_seq = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$requests_table}
             WHERE DATE_FORMAT(created_at, '%%Y-%%m') = %s AND id <= %d",
            $year_month,
            $devis_id
        ));
        if ($monthly_seq < 1) {
            $monthly_seq = 1;
        }
    } else {
        $monthly_seq = 1;
    }

    $devis_num = date('Y', $date_ts) . ' ' . date('m', $date_ts) . ' ' . str_pad($monthly_seq, 4, '0', STR_PAD_LEFT);
    $sheet->setCellValue('C10', $devis_num);

    // ---- Date longue en français (C11) ----
    if (class_exists('IntlDateFormatter')) {
        $fmt      = new IntlDateFormatter('fr_FR', IntlDateFormatter::LONG, IntlDateFormatter::NONE);
        $date_str = $fmt->format($date_ts);
    } else {
        $months_fr = [
            '',
            'janvier',
            'février',
            'mars',
            'avril',
            'mai',
            'juin',
            'juillet',
            'août',
            'septembre',
            'octobre',
            'novembre',
            'décembre'
        ];
        $date_str  = (int) date('d', $date_ts) . ' ' . $months_fr[(int) date('n', $date_ts)] . ' ' . date('Y', $date_ts);
    }
    $sheet->setCellValue('C11', $date_str);

    // ---- Lecture du catalogue produits ----
    $excelData = get_excel_data();

    // ---- Traitement des références (même logique que le générateur Word) ----
    $all_references            = [];
    $all_references_quantities = [];
    $all_references_images     = [];

    // Première passe : détecter les images des références isolées
    foreach ($products as $product) {
        $product_name  = trim($product['name']);
        $product_image = isset($product['img']) ? $product['img'] : 'N/A';
        $temp_refs     = [];

        if (strpos($product_name, '&') !== false) {
            $temp_refs = array_map('trim', explode('&', $product_name));
        } else {
            $pattern = '/([A-Za-z]{1,2}(?:\s+\d+){2,4})/';
            if (preg_match_all($pattern, $product_name, $matches)) {
                $temp_refs = $matches[0];
            } else {
                $temp_refs = [$product_name];
            }
        }

        if (count($temp_refs) === 1) {
            $ref = trim($temp_refs[0]);
            if (preg_match('/^([A-Za-z]{1,2})/', $ref, $m)) {
                $std_ref = strtoupper($m[1]) . substr($ref, strlen($m[1]));
            } else {
                $std_ref = $ref;
            }
            $std_key                         = implode('_', preg_split('/\s+/', $std_ref));
            $all_references_images[$std_key] = $product_image;
        }
    }

    // Deuxième passe : agréger les quantités
    foreach ($products as $product) {
        $quantity      = isset($product['quantity']) ? intval($product['quantity']) : 1;
        $product_name  = trim($product['name']);
        $product_image = isset($product['img']) ? $product['img'] : 'N/A';
        $temp_refs     = [];

        if (strpos($product_name, '&') !== false) {
            $temp_refs = array_map('trim', explode('&', $product_name));
        } else {
            $pattern = '/([A-Za-z](?:\s+\d+){2,4})/';
            if (preg_match_all($pattern, $product_name, $matches)) {
                $temp_refs = $matches[0];
            } else {
                $temp_refs = [$product_name];
            }
        }

        foreach ($temp_refs as $ref) {
            $ref = trim($ref);
            if (preg_match('/^([A-Za-z]{1,2})/', $ref, $m)) {
                $std_ref = strtoupper($m[1]) . substr($ref, strlen($m[1]));
            } else {
                $std_ref = $ref;
            }
            $std_key = implode('_', preg_split('/\s+/', $std_ref));

            if (isset($all_references[$std_key])) {
                $all_references_quantities[$std_key] += $quantity;
            } else {
                $all_references[$std_key]            = $std_ref;
                $all_references_quantities[$std_key] = $quantity;
                if (!isset($all_references_images[$std_key])) {
                    $all_references_images[$std_key] = $product_image;
                }
            }
        }
    }

    // ---- Construire la liste finale des lignes produits ----
    $product_rows = [];
    foreach ($all_references as $key => $ref) {
        $ref_components = preg_split('/\s+/', $ref);
        if (preg_match('/^([A-Za-z]{1,2})/', $ref_components[0], $m)) {
            $letter = strtoupper($m[1]);
        } else {
            $letter = strtoupper(substr($ref_components[0], 0, 1));
        }
        $num1 = isset($ref_components[1]) ? $ref_components[1] : '';
        $num2 = isset($ref_components[2]) ? $ref_components[2] : '';
        $num3 = isset($ref_components[3]) ? $ref_components[3] : '';

        $price       = '';
        $designation = '';
        $collection  = '';
        $finition    = '';
        $dimension   = '';

        foreach ($excelData as $catalog_row) {
            if (
                $catalog_row['letter'] == $letter &&
                (empty($num1) || $catalog_row['num1'] == $num1) &&
                (empty($num2) || $catalog_row['num2'] == $num2) &&
                (empty($num3) || $catalog_row['num3'] == $num3)
            ) {
                $price       = $catalog_row['price'];
                $designation = $catalog_row['designation'];
                $collection  = $catalog_row['collection'];
                $finition    = $catalog_row['finition'];
                $dimension   = $catalog_row['dimension'];
                break;
            }
        }

        $product_rows[] = [
            'ref'         => $ref,
            'designation' => $designation,
            'collection'  => $collection,
            'finition'    => $finition,
            'dimension'   => $dimension,
            'quantity'    => $all_references_quantities[$key],
            'unit_price'  => floatval(str_replace(',', '.', $price)),
            'image_url'   => isset($all_references_images[$key]) ? $all_references_images[$key] : 'N/A',
        ];
    }

    if (empty($product_rows)) {
        $product_rows[] = [
            'ref' => '',
            'designation' => '',
            'collection' => '',
            'finition' => '',
            'dimension' => '',
            'quantity' => 1,
            'unit_price' => 0,
            'image_url' => 'N/A',
        ];
    }

    $num_products = count($product_rows);

    // ---- Lire le format monétaire depuis la cellule I14 du modèle ----
    $currency_format = $sheet->getStyle('I14')->getNumberFormat()->getFormatCode();
    if (empty($currency_format) || $currency_format === 'General' || $currency_format === '@') {
        $currency_format = '# ##0,00\ "€"';
    }

    // ---- Insérer les lignes supplémentaires avant la ligne 15 ----
    if ($num_products > 1) {
        $sheet->insertNewRowBefore(15, $num_products - 1);
        for ($i = 0; $i < $num_products - 1; $i++) {
            $new_row = 15 + $i;
            $sheet->duplicateStyle($sheet->getStyle('A14:I14'), 'A' . $new_row . ':I' . $new_row);
            // Fusionner les cellules B-F pour chaque nouvelle ligne
            $sheet->mergeCells('B' . $new_row . ':F' . $new_row);
            $row_height = $sheet->getRowDimension(14)->getRowHeight();
            if ($row_height > 0) {
                $sheet->getRowDimension($new_row)->setRowHeight($row_height);
            }
        }
    }

    // Élargir la colonne H pour éviter les #####
    $sheet->getColumnDimension('H')->setWidth(15);
    $sheet->getColumnDimension('I')->setWidth(15);

    // ---- Remplir les lignes produits ----
    foreach ($product_rows as $idx => $prod) {
        $row_num = 14 + $idx;

        // Fusionner B-F pour la ligne 14 aussi (au cas où le modèle ne le ferait pas)
        if ($row_num == 14) {
            $sheet->mergeCells('B14:F14');
        }

        // Colonne A : Image du produit (95% de la largeur, hauteur proportionnelle)
        if (!empty($prod['image_url']) && $prod['image_url'] !== 'N/A') {
            $img_path = convert_url_to_path($prod['image_url']);
            if ($img_path && file_exists($img_path)) {
                try {
                    // Récupérer la largeur de la cellule A
                    $col_width_chars = $sheet->getColumnDimension('A')->getWidth();
                    if ($col_width_chars <= 0) {
                        $col_width_chars = 10; // Largeur par défaut
                    }

                    // Conversion approximative : 1 char ≈ 7 pixels
                    $cell_width_px = $col_width_chars * 7;

                    // 95% de la largeur de la cellule
                    $img_width = $cell_width_px * 0.95;

                    // Offset horizontal pour centrer l'image
                    $offset_x = ($cell_width_px - $img_width) / 2;

                    $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
                    $drawing->setName('Produit');
                    $drawing->setDescription($prod['ref']);
                    $drawing->setPath($img_path);
                    $drawing->setWidth((int)$img_width);  // Largeur fixe, hauteur proportionnelle
                    $drawing->setOffsetX((int)$offset_x); // Centrer horizontalement
                    $drawing->setOffsetY(2);              // Petit offset vertical
                    $drawing->setCoordinates('A' . $row_num);
                    $drawing->setWorksheet($sheet);
                } catch (Exception $e) {
                    // Image indisponible — on continue sans elle
                }
            }
        }

        // Colonnes B-F (fusionnées) : texte multi-lignes
        $collection_text = !empty($prod['collection']) ? ' (Collection ' . $prod['collection'] . ')' : '';
        $finition_dim    = trim($prod['finition'] . ' ' . $prod['dimension']);

        $multi_line_text = $prod['ref'] . $collection_text . "\n"
            . $prod['designation'] . "\n"
            . $finition_dim;

        $sheet->setCellValue('B' . $row_num, $multi_line_text);
        $sheet->getStyle('B' . $row_num)->getAlignment()->setWrapText(true);

        // Centrer la colonne A (image)
        $sheet->getStyle('A' . $row_num)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A' . $row_num)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

        // Colonnes G, H, I : Quantité, Prix unitaire, Total
        $sheet->setCellValue('G' . $row_num, $prod['quantity']);

        // Centrer la colonne G (quantité)
        $sheet->getStyle('G' . $row_num)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('G' . $row_num)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

        if ($prod['unit_price'] > 0) {
            $sheet->setCellValue('H' . $row_num, $prod['unit_price']);
            $sheet->getStyle('H' . $row_num)->getNumberFormat()->setFormatCode($currency_format);
            $sheet->getStyle('H' . $row_num)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

            $sheet->setCellValue('I' . $row_num, $prod['unit_price'] * $prod['quantity']);
            $sheet->getStyle('I' . $row_num)->getNumberFormat()->setFormatCode($currency_format);
            $sheet->getStyle('I' . $row_num)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        }
    }

    // ---- Total HT — formule 2 lignes sous le dernier produit, colonne I ----
    $last_product_row = 13 + $num_products; // = 14 + ($num_products - 1)
    $total_ht_row     = $last_product_row + 2;

    $sheet->setCellValue('I' . $total_ht_row, '=SUM(I14:I' . $last_product_row . ')');
    $sheet->getStyle('I' . $total_ht_row)->getNumberFormat()->setFormatCode($currency_format);

    $sheet->getStyle('I' . $total_ht_row)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

    // ---- Sauvegarder le fichier ----
    $upload_dir = wp_upload_dir();
    $file_name  = 'Devis_JBAdeV_' . ($devis_id > 0 ? str_pad($devis_id, 6, '0', STR_PAD_LEFT) : date('Ymd_His')) . '.xlsx';
    $file_path  = $upload_dir['path'] . '/' . $file_name;

    try {
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($file_path);
    } catch (Exception $e) {
        error_log('wishlist_devis_generate_excel: impossible de sauvegarder — ' . $e->getMessage());
        return '';
    }

    return $file_path;
}

/**
 * Récupère et parse les données d'un Google Sheet.
 *
 * @param string $sheet_url URL du Google Sheet.
 * @param array  $column_mapping Mapping des colonnes (optionnel).
 * @return array Données du catalogue.
 */
function fetch_google_sheet_data($sheet_url, $column_mapping = null)
{
    $data = [];

    // Mapping par défaut (produits généraux : F, G, H, I...)
    if ($column_mapping === null) {
        $column_mapping = [
            'letter' => 5,  // Colonne F (index 5)
            'num1'   => 6,  // Colonne G (index 6)
            'num2'   => 7,  // Colonne H (index 7)
            'num3'   => 8,  // Colonne I (index 8)
            'price'  => 10, // Colonne K (index 10)
            'desc'   => 12, // Colonne M (index 12)
            'coll'   => 13, // Colonne N (index 13)
            'fini'   => 14, // Colonne O (index 14)
            'dim'    => 15, // Colonne P (index 15)
        ];
    }

    // Convertir l'URL Google Sheets en URL de téléchargement CSV
    // Extraire le gid si présent dans l'URL
    $gid = '';
    if (preg_match('/[?&]gid=(\d+)/', $sheet_url, $matches)) {
        $gid = '&gid=' . $matches[1];
    } elseif (preg_match('/#gid=(\d+)/', $sheet_url, $matches)) {
        $gid = '&gid=' . $matches[1];
    }

    // Construire l'URL de téléchargement CSV
    $download_url = preg_replace('/\/edit.*$/', '/export?format=csv' . $gid, $sheet_url);

    // Télécharger le contenu
    $response = wp_remote_get($download_url);

    if (is_wp_error($response)) {
        error_log('fetch_google_sheet_data: échec du téléchargement pour ' . $sheet_url . ' (download_url: ' . $download_url . ')');
        return $data;
    }

    $csvContent = wp_remote_retrieve_body($response);

    // Log pour déboguer
    error_log('fetch_google_sheet_data: téléchargement réussi depuis ' . $download_url . ' (' . strlen($csvContent) . ' octets)');

    // Analyser le CSV
    $rows = explode("\n", $csvContent);
    $row_count = 0;
    foreach ($rows as $index => $row) {
        // Ignorer les lignes vides
        if (empty(trim($row))) {
            continue;
        }

        $columns = str_getcsv($row);

        // Vérifier que nous avons suffisamment de colonnes
        $min_columns = max(array_values($column_mapping)) + 1;
        if (count($columns) >= $min_columns) {
            // Récupérer le prix et nettoyer le symbole €
            $raw_price = isset($columns[$column_mapping['price']]) ? trim($columns[$column_mapping['price']]) : '';
            $clean_price = preg_replace('/[^\d,.]/', '', $raw_price); // Ne garder que chiffres, virgules et points

            // Gérer finition et dimension (peuvent être combinées dans dim2)
            $finition = isset($columns[$column_mapping['fini']]) ? trim($columns[$column_mapping['fini']]) : '';
            $dimension = isset($columns[$column_mapping['dim']]) ? trim($columns[$column_mapping['dim']]) : '';

            // Si dim2 est défini, combiner finition et dimensions (K, L et M)
            if (isset($column_mapping['dim2'])) {
                $dim2 = isset($columns[$column_mapping['dim2']]) ? trim($columns[$column_mapping['dim2']]) : '';

                // Combiner K, L et M pour finition/dimension
                $parts = array_filter([$finition, $dimension, $dim2], 'strlen');
                $combined = implode(' ', $parts);
                $finition = $combined;
                $dimension = '';
            }

            $letter = isset($columns[$column_mapping['letter']]) ? trim($columns[$column_mapping['letter']]) : '';
            $designation = isset($columns[$column_mapping['desc']]) ? trim($columns[$column_mapping['desc']]) : '';

            // Ignorer les lignes d'en-tête ou les lignes vides (sans référence ni désignation)
            if (empty($letter) && empty($designation)) {
                continue;
            }

            $data[] = [
                'letter'      => $letter,
                'num1'        => isset($columns[$column_mapping['num1']]) ? trim($columns[$column_mapping['num1']]) : '',
                'num2'        => isset($columns[$column_mapping['num2']]) ? trim($columns[$column_mapping['num2']]) : '',
                'num3'        => isset($columns[$column_mapping['num3']]) ? trim($columns[$column_mapping['num3']]) : '',
                'price'       => $clean_price,
                'designation' => $designation,
                'collection'  => isset($columns[$column_mapping['coll']]) ? trim($columns[$column_mapping['coll']]) : '',
                'finition'    => $finition,
                'dimension'   => $dimension
            ];
            $row_count++;
        }
    }

    error_log('fetch_google_sheet_data: ' . $row_count . ' produits récupérés');
    return $data;
}

// Fonction pour récupérer les données du fichier Excel
function get_excel_data()
{
    // Mapping par défaut (produits généraux)
    $default_mapping = [
        'letter' => 5,  // Colonne F
        'num1'   => 6,  // Colonne G
        'num2'   => 7,  // Colonne H
        'num3'   => 8,  // Colonne I
        'price'  => 10, // Colonne K
        'desc'   => 12, // Colonne M
        'coll'   => 13, // Colonne N
        'fini'   => 14, // Colonne O
        'dim'    => 15, // Colonne P
    ];

    // Mapping pour les produits M (structure différente)
    $m_mapping = [
        'letter' => 4,  // Colonne E
        'num1'   => 5,  // Colonne F
        'num2'   => 6,  // Colonne G
        'num3'   => 7,  // Colonne H
        'desc'   => 8,  // Colonne I - Désignation
        'coll'   => 9,  // Colonne J - Collection
        'fini'   => 10, // Colonne K - Finition/Dimension (à combiner avec L et M)
        'dim'    => 11, // Colonne L - Finition/Dimension 2
        'dim2'   => 12, // Colonne M - Finition/Dimension 3
        'price'  => 13, // Colonne N - Prix
    ];

    // Configuration des sheets avec leurs mappings respectifs
    $sheets = [
        [
            'url'     => 'https://docs.google.com/spreadsheets/d/1OfjkbSpPK3CcvsMbWGabFrdW0cOnxHE9/edit?usp=sharing&ouid=103467285749476133477&rtpof=true&sd=true',
            'mapping' => $default_mapping,
        ],
        [
            'url'     => 'https://docs.google.com/spreadsheets/d/1XQqzpYgFaACAuekK5XZlix5kAJMsYWu80glyGfftV7I/edit?gid=0#gid=0',
            'mapping' => $m_mapping,
        ],
    ];

    $excelData = [];

    // Récupérer et combiner les données des deux sources
    foreach ($sheets as $sheet) {
        $data = fetch_google_sheet_data($sheet['url'], $sheet['mapping']);
        $excelData = array_merge($excelData, $data);
    }

    return $excelData;
}

// Fonction pour convertir une URL d'image en chemin local
function convert_url_to_path($url)
{
    // Vérifier si l'URL est relative (déjà sur le serveur)
    if (strpos($url, 'http') !== 0) {
        // URL relative, ajouter le chemin du site
        $url = home_url($url);
    }

    // Vérifier si l'URL est sur le même domaine
    $site_url = parse_url(site_url());
    $image_url = parse_url($url);

    if ($site_url['host'] === $image_url['host']) {
        // Convertir l'URL en chemin local
        $path = str_replace(
            [site_url(), '/'],
            [ABSPATH, DIRECTORY_SEPARATOR],
            $url
        );

        if (file_exists($path)) {
            return $path;
        }
    }

    // Si l'image est externe ou introuvable, essayer de la télécharger
    $upload_dir = wp_upload_dir();
    $temp_filename = md5($url) . '.jpg';
    $local_path = $upload_dir['path'] . '/' . $temp_filename;

    $response = wp_remote_get($url);
    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
        $image_data = wp_remote_retrieve_body($response);
        file_put_contents($local_path, $image_data);
        return $local_path;
    }

    return false;
}

/**
 * Traite les actions POST/GET de la page d'administration des devis.
 * Appelé par admin_init avant tout affichage. Effectue les modifications
 * puis redirige pour éviter la re-soumission du formulaire (PRG pattern).
 *
 * @return void
 */
function wishlist_devis_handle_admin_post()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    // On n'agit que si on est sur la bonne page admin.
    $page = isset($_REQUEST['page']) ? sanitize_key($_REQUEST['page']) : '';
    if ($page !== 'wishlist-devis-requests') {
        return;
    }

    global $wpdb;
    $requests_table = $wpdb->prefix . 'wishlist_devis_requests';

    $admin_action = isset($_REQUEST['wd_action']) ? sanitize_key($_REQUEST['wd_action']) : '';

    // --- Téléchargement Excel ---
    if ($admin_action === 'download_excel') {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id > 0 && isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'wd_download_excel_' . $id)) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$requests_table} WHERE id = %d", $id), ARRAY_A);
            if ($row) {
                $products = array();
                if (!empty($row['products'])) {
                    $decoded = json_decode($row['products'], true);
                    if (is_array($decoded)) {
                        $products = $decoded;
                    }
                }
                $data              = $row;
                $data['name']      = $row['full_name'];
                $data['products']  = $products;

                $file_path = wishlist_devis_generate_excel($products, $data, $id);

                if ($file_path && file_exists($file_path)) {
                    $ref       = isset($row['reference']) ? $row['reference'] : $id;
                    $file_name = 'Devis_JBAdeV_ref' . $ref . '_' . date('Ymd') . '.xlsx';
                    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                    header('Content-Disposition: attachment; filename="' . $file_name . '"');
                    header('Content-Length: ' . filesize($file_path));
                    header('Cache-Control: max-age=0');
                    readfile($file_path);
                    @unlink($file_path);
                    exit;
                }
            }
        }
        wp_redirect(admin_url('admin.php?page=wishlist-devis-requests'));
        exit;
    }

    // --- Suppression ---
    if ($admin_action === 'delete') {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id > 0 && isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'wd_delete_' . $id)) {
            $wpdb->delete($requests_table, array('id' => $id), array('%d'));
        }
        wp_redirect(admin_url('admin.php?page=wishlist-devis-requests'));
        exit;
    }

    // --- Mise à jour (édition complète ou changement de statut seul) ---
    if ($admin_action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($id > 0 && isset($_POST['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'wd_update_' . $id)) {

            $allowed_statuses = array_keys(wishlist_devis_get_statuses());

            $update_data    = array();
            $update_formats = array();

            if (isset($_POST['status'])) {
                $new_status = sanitize_key($_POST['status']);
                if (in_array($new_status, $allowed_statuses, true)) {
                    $update_data['status']   = $new_status;
                    $update_formats[]        = '%s';
                }
            }

            // Champs éditables complets (via formulaire d'édition)
            if (isset($_POST['full_edit'])) {
                $text_fields = array('full_name', 'email', 'phone', 'address', 'postal_code', 'city', 'country', 'company_name', 'siret');
                foreach ($text_fields as $field) {
                    if (isset($_POST[$field])) {
                        $update_data[$field]   = sanitize_text_field(wp_unslash($_POST[$field]));
                        $update_formats[]      = '%s';
                    }
                }
                if (isset($_POST['customer_type'])) {
                    $ct = sanitize_key($_POST['customer_type']);
                    if (in_array($ct, array(WD_CUSTOMER_TYPE_INDIVIDUAL, WD_CUSTOMER_TYPE_PROFESSIONAL), true)) {
                        $update_data['customer_type'] = $ct;
                        $update_formats[]             = '%s';
                    }
                }
            }

            if (!empty($update_data)) {
                $wpdb->update($requests_table, $update_data, array('id' => $id), $update_formats, array('%d'));
            }
        }

        $redirect_url = admin_url('admin.php?page=wishlist-devis-requests&action=view&id=' . $id);
        wp_redirect($redirect_url);
        exit;
    }
}

/**
 * Affiche la page d'administration listant toutes les demandes de devis.
 *
 * Liste toutes les lignes de {prefix}wishlist_devis_requests, de la plus
 * récente à la plus ancienne (created_at DESC, id DESC). Chaque valeur
 * dynamique est échappée avec esc_html() / esc_attr().
 *
 * @return void
 */
function wishlist_devis_render_admin_page()
{
    global $wpdb;

    $requests_table = $wpdb->prefix . 'wishlist_devis_requests';

    $admin_view = isset($_GET['action']) ? sanitize_key($_GET['action']) : 'list';
    $edit_id    = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    // -----------------------------------------------------------------------
    // Vue DÉTAIL / ÉDITION
    // -----------------------------------------------------------------------
    if (($admin_view === 'view' || $admin_view === 'edit') && $edit_id > 0) {
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$requests_table} WHERE id = %d", $edit_id),
            ARRAY_A
        );

        if (!$row) {
            echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html('Demande introuvable.') . '</p></div></div>';
            return;
        }

        $statuses         = wishlist_devis_get_statuses();
        $current_status   = isset($row['status']) ? $row['status'] : 'a_envoyer';
        $is_professional  = (isset($row['customer_type']) && $row['customer_type'] === WD_CUSTOMER_TYPE_PROFESSIONAL);
        $is_edit          = ($admin_view === 'edit');
        $list_url         = admin_url('admin.php?page=wishlist-devis-requests');
        $edit_url         = admin_url('admin.php?page=wishlist-devis-requests&action=edit&id=' . $edit_id);
        $delete_url       = wp_nonce_url(
            admin_url('admin.php?page=wishlist-devis-requests&wd_action=delete&id=' . $edit_id),
            'wd_delete_' . $edit_id
        );
        $download_excel_url = wp_nonce_url(
            admin_url('admin.php?page=wishlist-devis-requests&wd_action=download_excel&id=' . $edit_id),
            'wd_download_excel_' . $edit_id
        );

        $products = array();
        if (!empty($row['products'])) {
            $decoded = json_decode($row['products'], true);
            if (is_array($decoded)) {
                $products = $decoded;
            }
        }

        echo '<div class="wrap wd-admin-wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html('Demande de devis — Réf. ' . $row['reference']) . '</h1>';
        echo '<a href="' . esc_url($list_url) . '" class="page-title-action">&larr; Retour à la liste</a>';
        echo '<hr class="wp-header-end">';

        // -- Notices de succès/erreur transmises via query string
        if (isset($_GET['updated']) && $_GET['updated'] === '1') {
            echo '<div class="notice notice-success is-dismissible"><p>Demande mise à jour.</p></div>';
        }

        // -- Formulaire principal (statut + champs si mode édition)
        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=wishlist-devis-requests')) . '">';
        echo '<input type="hidden" name="wd_action" value="update">';
        echo '<input type="hidden" name="id" value="' . esc_attr($edit_id) . '">';
        wp_nonce_field('wd_update_' . $edit_id);
        if ($is_edit) {
            echo '<input type="hidden" name="full_edit" value="1">';
        }

        echo '<div class="wd-detail-grid">';

        // Colonne gauche : informations client
        echo '<div class="wd-detail-card">';
        echo '<h2>Informations client</h2>';

        if ($is_edit) {
            // -- Mode édition : champs modifiables
            $field = function ($label, $name, $value, $type = 'text') use ($row) {
                echo '<div class="wd-field-row">';
                echo '<label for="wd-' . esc_attr($name) . '">' . esc_html($label) . '</label>';
                echo '<input type="' . esc_attr($type) . '" id="wd-' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
                echo '</div>';
            };

            echo '<div class="wd-field-row">';
            echo '<label>Type de client</label>';
            echo '<select name="customer_type">';
            foreach (array(WD_CUSTOMER_TYPE_INDIVIDUAL => 'Particulier', WD_CUSTOMER_TYPE_PROFESSIONAL => 'Professionnel') as $val => $lbl) {
                $sel = (isset($row['customer_type']) && $row['customer_type'] === $val) ? ' selected' : '';
                echo '<option value="' . esc_attr($val) . '"' . $sel . '>' . esc_html($lbl) . '</option>';
            }
            echo '</select>';
            echo '</div>';

            $field('Nom complet', 'full_name', $row['full_name'] ?? '');
            $field('Email', 'email', $row['email'] ?? '', 'email');
            $field('Téléphone', 'phone', $row['phone'] ?? '');
            $field('Société', 'company_name', $row['company_name'] ?? '');
            $field('SIRET', 'siret', $row['siret'] ?? '');
            $field('Adresse', 'address', $row['address'] ?? '');
            $field('Code postal', 'postal_code', $row['postal_code'] ?? '');
            $field('Ville', 'city', $row['city'] ?? '');
            $field('Pays', 'country', $row['country'] ?? '');
        } else {
            // -- Mode lecture
            $info_rows = array(
                'Référence'      => $row['reference'] ?? '',
                'Date'           => isset($row['created_at']) ? date_i18n('d/m/Y à H:i', strtotime($row['created_at'])) : '',
                'Type de client' => $is_professional ? 'Professionnel' : 'Particulier',
            );
            if ($is_professional) {
                $info_rows['Société'] = $row['company_name'] ?? '';
                $info_rows['SIRET']   = $row['siret'] ?? '';
            }
            $info_rows['Nom']         = $row['full_name'] ?? '';
            $info_rows['Email']       = $row['email'] ?? '';
            $info_rows['Téléphone']   = $row['phone'] ?? '';
            $info_rows['Adresse']     = $row['address'] ?? '';
            $info_rows['Code postal'] = $row['postal_code'] ?? '';
            $info_rows['Ville']       = $row['city'] ?? '';
            $info_rows['Pays']        = $row['country'] ?? '';

            foreach ($info_rows as $label => $value) {
                if ($value === '') continue;
                echo '<div class="wd-info-row">';
                echo '<span class="wd-info-label">' . esc_html($label) . '</span>';
                echo '<span class="wd-info-value">' . esc_html($value) . '</span>';
                echo '</div>';
            }
        }
        echo '</div>'; // .wd-detail-card

        // Colonne droite : statut + produits
        echo '<div>';

        // Bloc statut
        echo '<div class="wd-detail-card">';
        echo '<h2>Statut</h2>';
        echo '<select name="status" class="wd-status-select">';
        foreach ($statuses as $slug => $label) {
            $sel = ($current_status === $slug) ? ' selected' : '';
            echo '<option value="' . esc_attr($slug) . '"' . $sel . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</div>'; // .wd-detail-card statut

        // Bloc produits
        echo '<div class="wd-detail-card">';
        echo '<h2>Produits (' . count($products) . ')</h2>';
        if (!empty($products)) {
            echo '<table class="widefat wd-products-table">';
            echo '<thead><tr><th>Produit</th><th>Qté</th></tr></thead><tbody>';
            foreach ($products as $p) {
                $name = isset($p['name']) ? $p['name'] : '';
                $qty  = isset($p['quantity']) ? (int) $p['quantity'] : 1;
                echo '<tr><td>' . esc_html($name) . '</td><td>' . esc_html($qty) . '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>Aucun produit.</p>';
        }
        echo '</div>'; // .wd-detail-card produits

        echo '</div>'; // colonne droite
        echo '</div>'; // .wd-detail-grid

        // Boutons d'action
        echo '<div class="wd-action-bar">';
        if ($is_edit) {
            echo '<button type="submit" class="button button-primary">Enregistrer les modifications</button> ';
            echo '<a href="' . esc_url(admin_url('admin.php?page=wishlist-devis-requests&action=view&id=' . $edit_id)) . '" class="button">Annuler</a>';
        } else {
            echo '<button type="submit" class="button button-primary">Mettre à jour le statut</button> ';
            echo '<a href="' . esc_url($edit_url) . '" class="button">Modifier les informations</a> ';
            echo '<a href="' . esc_url($download_excel_url) . '" class="button button-secondary">&#11015; Télécharger Excel</a> ';
            echo '<a href="' . esc_url($delete_url) . '" class="button button-link-delete wd-delete-link" onclick="return confirm(\'Supprimer définitivement cette demande ?\')">Supprimer</a>';
        }
        echo '</div>';

        echo '</form>';
        echo '</div>'; // .wrap
        return;
    }

    // -----------------------------------------------------------------------
    // Vue LISTE
    // -----------------------------------------------------------------------
    $rows = $wpdb->get_results(
        "SELECT * FROM {$requests_table} ORDER BY created_at DESC, id DESC",
        ARRAY_A
    );

    echo '<div class="wrap wd-admin-wrap">';
    echo '<h1>' . esc_html('Demandes de devis') . '</h1>';

    if (empty($rows)) {
        echo '<p>' . esc_html('Aucune demande.') . '</p>';
        echo '</div>';
        return;
    }

    $statuses = wishlist_devis_get_statuses();

    echo '<table class="widefat striped wd-list-table">';
    echo '<thead><tr>';
    echo '<th>' . esc_html('Réf.') . '</th>';
    echo '<th>' . esc_html('Date') . '</th>';
    echo '<th>' . esc_html('Client') . '</th>';
    echo '<th>' . esc_html('Email') . '</th>';
    echo '<th>' . esc_html('Produits') . '</th>';
    echo '<th>' . esc_html('Statut') . '</th>';
    echo '<th>' . esc_html('Actions') . '</th>';
    echo '</tr></thead>';
    echo '<tbody>';

    foreach ($rows as $row) {
        $id            = (int) ($row['id'] ?? 0);
        $reference     = $row['reference'] ?? '';
        $created_at    = isset($row['created_at']) ? date_i18n('d/m/Y', strtotime($row['created_at'])) : '';
        $customer_type = $row['customer_type'] ?? '';
        $full_name     = $row['full_name'] ?? '';
        $company_name  = $row['company_name'] ?? '';
        $email         = $row['email'] ?? '';
        $current_status = $row['status'] ?? 'a_envoyer';
        $status_label  = wishlist_devis_status_label($current_status);

        $name_summary = $full_name;
        if ($company_name !== '') {
            $name_summary = $full_name !== '' ? $full_name . ' (' . $company_name . ')' : $company_name;
        }

        $products      = array();
        $products_raw  = $row['products'] ?? '';
        if ($products_raw !== '') {
            $decoded = json_decode($products_raw, true);
            if (is_array($decoded)) $products = $decoded;
        }
        $product_count = count($products);

        $view_url          = admin_url('admin.php?page=wishlist-devis-requests&action=view&id=' . $id);
        $download_excel_url = wp_nonce_url(
            admin_url('admin.php?page=wishlist-devis-requests&wd_action=download_excel&id=' . $id),
            'wd_download_excel_' . $id
        );
        $delete_url = wp_nonce_url(
            admin_url('admin.php?page=wishlist-devis-requests&wd_action=delete&id=' . $id),
            'wd_delete_' . $id
        );

        $status_css = 'wd-status wd-status--' . esc_attr($current_status);

        echo '<tr>';
        echo '<td><strong>' . esc_html($reference) . '</strong></td>';
        echo '<td>' . esc_html($created_at) . '</td>';
        echo '<td>' . esc_html($name_summary) . '</td>';
        echo '<td>' . esc_html($email) . '</td>';
        echo '<td>' . esc_html($product_count . ' produit(s)') . '</td>';
        echo '<td><span class="' . $status_css . '">' . esc_html($status_label) . '</span></td>';
        echo '<td class="wd-row-actions">';
        echo '<a href="' . esc_url($view_url) . '" class="button button-small">Voir</a> ';
        echo '<a href="' . esc_url($download_excel_url) . '" class="button button-small">⬇ Excel</a> ';
        echo '<a href="' . esc_url($delete_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'Supprimer cette demande ?\')">Supprimer</a>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
    echo '</div>';
}
