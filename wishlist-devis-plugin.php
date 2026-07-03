<?php

/**
 * Plugin Name: Wishlist Devis Plugin
 * Description: Ajoute un bouton "Demander un devis" sur la page Wishlist pour envoyer une demande par email avec un devis en pièce jointe.
 * Version: 1.1
 * Author: Levi Belhamou
 */

if (!defined('ABSPATH')) {
    exit; // Sécurité
}

// Inclure les fichiers nécessaires
include_once plugin_dir_path(__FILE__) . 'devis-functions.php';

// Activer le plugin
function wishlist_devis_activate()
{
    add_option('wishlist_devis_admin_email', get_option('admin_email'));
    wishlist_devis_install_tables();
    update_option('wishlist_devis_db_version', WD_DB_VERSION);
}
register_activation_hook(__FILE__, 'wishlist_devis_activate');

// Mise à jour du schéma BDD si nécessaire (ex: ajout colonne status)
function wishlist_devis_maybe_update_schema()
{
    if (get_option('wishlist_devis_db_version') !== WD_DB_VERSION) {
        wishlist_devis_install_tables();
        update_option('wishlist_devis_db_version', WD_DB_VERSION);
    }
}
add_action('plugins_loaded', 'wishlist_devis_maybe_update_schema');

// Traitement des actions POST admin (PRG pattern)
add_action('admin_init', 'wishlist_devis_handle_admin_post');

// Charger le CSS admin
function wishlist_devis_enqueue_admin_styles($hook)
{
    if ($hook !== 'toplevel_page_wishlist-devis-requests') {
        return;
    }
    wp_enqueue_style('wishlist-devis-admin-style', plugin_dir_url(__FILE__) . 'wishlist-devis-admin.css', array(), '1.0');
}
add_action('admin_enqueue_scripts', 'wishlist_devis_enqueue_admin_styles');

// Ajouter les styles CSS + surcharges anti-thème
function wishlist_devis_enqueue_styles()
{
    wp_enqueue_style(
        'wishlist-devis-style',
        plugin_dir_url(__FILE__) . 'wishlist-devis-plugin.css',
        array(),
        filemtime(plugin_dir_path(__FILE__) . 'wishlist-devis-plugin.css')
    );

    // wp_add_inline_style injecte ce CSS immédiatement après la feuille ci-dessus
    // dans le <head>, en dernier dans la cascade → écrase le thème même sans !important.
    // Les !important restants couvrent les thèmes qui utilisent eux-mêmes !important.
    wp_add_inline_style('wishlist-devis-style', '
        /* --- Reset espacement parasites du thème --- */
        .wishlist-devis-container label                        { margin-top: 0 !important; margin-bottom: 4px !important; padding: 0 !important; line-height: 1.3 !important; font-size: 13px !important; font-weight: 600 !important; display: block !important; }
        .wishlist-devis-container input[type="text"],
        .wishlist-devis-container input[type="email"],
        .wishlist-devis-container input[type="tel"]            { margin: 0 !important; padding: 10px 14px !important; width: 100% !important; border: 1px solid #d8dce0 !important; border-radius: 8px !important; font-size: 14px !important; background: #fff !important; box-shadow: none !important; line-height: 1.4 !important; height: auto !important; }
        .wishlist-devis-container .form-group                  { margin: 0 0 12px !important; padding: 0 !important; }

        /* --- Grille colonnes --- */
        .wishlist-devis-container .wd-row                      { display: grid !important; grid-template-columns: 1fr 1fr !important; gap: 12px !important; margin: 0 0 12px !important; }
        .wishlist-devis-container .wd-row.wd-row-3             { grid-template-columns: 1fr 1fr 1fr !important; }
        .wishlist-devis-container .wd-row .form-group          { margin-bottom: 0 !important; }

        /* --- Boutons segmentés type de client --- */
        .wishlist-devis-container .wd-segmented                { display: flex !important; flex-direction: row !important; gap: 10px !important; margin: 0 !important; padding: 0 !important; }
        .wishlist-devis-container .wd-seg                      { flex: 1 !important; position: relative !important; margin: 0 !important; padding: 0 !important; cursor: pointer !important; list-style: none !important; }
        .wishlist-devis-container .wd-seg input[type="radio"]  { display: none !important; }
        .wishlist-devis-container .wd-seg span                 { display: block !important; margin: 0 !important; padding: 10px 14px !important; text-align: center !important; border: 1px solid #d8dce0 !important; border-radius: 8px !important; font-size: 14px !important; font-weight: 600 !important; color: #6b7280 !important; background: #fff !important; cursor: pointer !important; line-height: 1.4 !important; }
        .wishlist-devis-container .wd-seg:hover span           { border-color: #ff7a00 !important; color: #ff7a00 !important; background: #fff !important; }
        .wishlist-devis-container .wd-seg input[type="radio"]:checked ~ span { border-color: #ff7a00 !important; background: #ff7a00 !important; color: #fff !important; box-shadow: 0 2px 8px rgba(255,122,0,0.3) !important; }

        @media (max-width: 540px) {
            .wishlist-devis-container .wd-row,
            .wishlist-devis-container .wd-row.wd-row-3         { grid-template-columns: 1fr !important; }
        }
    ');
}
add_action('wp_enqueue_scripts', 'wishlist_devis_enqueue_styles');

// Ajouter un shortcode pour afficher le bouton
function wishlist_devis_shortcode()
{
    ob_start(); ?>

    <div class="wishlist-devis-container">
        <button id="request-devis-btn" class="wishlist-devis-btn">Demander un devis</button>

        <div id="devis-form" class="wishlist-devis-form">
            <h3 class="wd-form-title">Demande de devis</h3>
            <p class="wd-form-subtitle">Renseignez vos coordonnées, nous vous recontactons avec votre devis.</p>

            <div class="form-group">
                <label class="wd-field-label">Type de client</label>
                <div class="wd-segmented" role="radiogroup" aria-label="Type de client">
                    <label class="wd-seg">
                        <input type="radio" name="devis-customer-type" value="particulier" checked>
                        <span>Particulier</span>
                    </label>
                    <label class="wd-seg">
                        <input type="radio" name="devis-customer-type" value="professionnel">
                        <span>Professionnel</span>
                    </label>
                </div>
            </div>

            <div id="devis-company-fields">
                <div class="wd-row">
                    <div class="form-group">
                        <label for="devis-company-name">Nom de société <span class="wd-required">*</span></label>
                        <input type="text" id="devis-company-name" placeholder="Ex. JB AdeV">
                    </div>

                    <div class="form-group">
                        <label for="devis-siret">Numéro de SIRET <span class="wd-required">*</span></label>
                        <input type="text" id="devis-siret" placeholder="Ex. 342 131 943 00082" inputmode="numeric">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="devis-full-name">Prénom et nom <span class="wd-required">*</span></label>
                <input type="text" id="devis-full-name" placeholder="Votre prénom et nom" required>
            </div>

            <div class="wd-row">
                <div class="form-group">
                    <label for="devis-email">Email <span class="wd-required">*</span></label>
                    <input type="email" id="devis-email" placeholder="vous@exemple.com" required>
                </div>

                <div class="form-group">
                    <label for="devis-phone">Téléphone</label>
                    <input type="tel" id="devis-phone" placeholder="Ex. 06 12 34 56 78">
                </div>
            </div>

            <div class="form-group">
                <label for="devis-address">Adresse</label>
                <input type="text" id="devis-address" placeholder="N° et nom de rue">
            </div>

            <div class="wd-row wd-row-3">
                <div class="form-group">
                    <label for="devis-postal-code">Code postal</label>
                    <input type="text" id="devis-postal-code" placeholder="Ex. 29730">
                </div>

                <div class="form-group">
                    <label for="devis-city">Ville</label>
                    <input type="text" id="devis-city" placeholder="Ex. Treffiagat">
                </div>

                <div class="form-group">
                    <label for="devis-country">Pays</label>
                    <input type="text" id="devis-country" placeholder="Ex. France">
                </div>
            </div>

            <div id="devis-form-error" class="error-message"></div>
            <div id="devis-form-success" class="success-message"></div>

            <div class="button-group">
                <button id="cancel-devis" class="wishlist-devis-cancel-btn">Annuler</button>
                <button id="send-devis" class="wishlist-devis-send-btn">Envoyer la demande</button>
            </div>
        </div>
    </div>

<?php
    $html = ob_get_clean();
    // wpautop injecte des <br> entre <input> et <span> et des <p></p> dans les grilles,
    // ce qui casse les sélecteurs CSS et les layouts grid. On nettoie avant de retourner.
    $html = preg_replace('/<br\s*\/?>/i', '', $html);
    $html = preg_replace('/<p>\s*<\/p>/i', '', $html);
    return $html;
}
add_shortcode('wishlist_devis', 'wishlist_devis_shortcode');

// Enregistrer la page d'administration des demandes de devis
function wishlist_devis_register_admin_menu()
{
    add_menu_page(
        'Demandes de devis',                 // titre de la page
        'Demandes de devis',                 // libellé du menu
        'manage_options',                    // capacité requise
        'wishlist-devis-requests',           // slug
        'wishlist_devis_render_admin_page',  // fonction de rendu
        'dashicons-media-document'           // icône
    );
}
add_action('admin_menu', 'wishlist_devis_register_admin_menu');

// Charger le fichier JavaScript
function wishlist_devis_enqueue_scripts()
{
    wp_enqueue_script('wishlist-devis-script', plugin_dir_url(__FILE__) . 'wishlist-devis-plugin.js', array('jquery'), '1.2', true);
    wp_localize_script('wishlist-devis-script', 'wishlistDevisAjax', array('ajaxurl' => admin_url('admin-ajax.php')));
}
add_action('wp_enqueue_scripts', 'wishlist_devis_enqueue_scripts');
