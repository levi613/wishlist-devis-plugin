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
}
register_activation_hook(__FILE__, 'wishlist_devis_activate');

// Ajouter les styles CSS
function wishlist_devis_enqueue_styles()
{
    wp_enqueue_style('wishlist-devis-style', plugin_dir_url(__FILE__) . 'wishlist-devis-plugin.css', array(), '1.1');
}
add_action('wp_enqueue_scripts', 'wishlist_devis_enqueue_styles');

// Ajouter un shortcode pour afficher le bouton
function wishlist_devis_shortcode()
{
    ob_start(); ?>

    <div class="wishlist-devis-container">
        <button id="request-devis-btn" class="wishlist-devis-btn">Demander un devis</button>

        <div id="devis-form" class="wishlist-devis-form">
            <div class="form-group">
                <label for="devis-name">Nom et prénom</label>
                <input type="text" id="devis-name" placeholder="Votre nom" required>
                <div class="error-message" id="name-error"></div>
            </div>

            <div class="form-group">
                <label for="devis-email">Email</label>
                <input type="email" id="devis-email" placeholder="Votre email" required>
                <div class="error-message" id="email-error"></div>
            </div>

            <div class="button-group">
                <button id="cancel-devis" class="wishlist-devis-cancel-btn">Annuler</button>
                <button id="send-devis" class="wishlist-devis-send-btn">Envoyer</button>
            </div>

            <div id="devis-form-success" class="success-message"></div>
            <div id="devis-form-error" class="error-message"></div>
        </div>
    </div>

<?php return ob_get_clean();
}
add_shortcode('wishlist_devis', 'wishlist_devis_shortcode');

// Charger le fichier JavaScript
function wishlist_devis_enqueue_scripts()
{
    wp_enqueue_script('wishlist-devis-script', plugin_dir_url(__FILE__) . 'wishlist-devis-plugin.js', array('jquery'), '1.1', true);
    wp_localize_script('wishlist-devis-script', 'wishlistDevisAjax', array('ajaxurl' => admin_url('admin-ajax.php')));
}
add_action('wp_enqueue_scripts', 'wishlist_devis_enqueue_scripts');
