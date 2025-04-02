<?php
if (!defined('ABSPATH')) {
    exit;
}

// Ajouter l'action AJAX
add_action('wp_ajax_send_devis', 'wishlist_devis_send_email');
add_action('wp_ajax_nopriv_send_devis', 'wishlist_devis_send_email');

function wishlist_devis_send_email()
{
    // mettre date au fuseau de Paris
    date_default_timezone_set('Europe/Paris');

    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data && empty($data['products'])) {
        wp_send_json(['message' => 'Aucun produit dans la wishlist'], 400);
    }

    // $admin_email = get_option('wishlist_devis_admin_email');
    // if (empty($admin_email)) {
    //     $admin_email = get_option('admin_email');
    // }

    $admin_email = 'jbastierdevillatte@gmail.com';
    // $admin_email = 'levibelhamou@gmail.com';
    $admin_email2 = 'levibelhamou@gmail.com';


    $subject = "Nouvelle demande de devis - " . $data['name'];

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
                <p><strong>Nom :</strong> ' . $data['name'] . '</p>
                <p><strong>Email :</strong> ' . $data['email'] . '</p>
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
            
            <p>Le devis Au format Word est joint à cet email.</p>
            
            <p>Pour visualiser tous les détails du devis, merci d\'ouvrir le document Word joint à cet email.</p>
            
            <p>Cordialement,<br>Le système automatique de devis</p>
        </div>
        <div class="footer">
            <p>Ce message a été généré automatiquement par le site <a href="https://www.jbadev.com/">https://www.jbadev.com/</a></p>
            <p>&copy; ' . date('Y') . ' JBADev. Tous droits réservés.</p>
        </div>
    </body>
    </html>';

    // Version texte brut comme fallback pour les clients email qui ne supportent pas l'HTML
    $text_message = "Nouvelle demande de devis\n\n";
    $text_message .= "Informations client :\n";
    $text_message .= "Nom : " . $data['name'] . "\n";
    $text_message .= "Email : " . $data['email'] . "\n";
    $text_message .= "Date de la demande : " . date('d/m/Y à H:i') . "\n\n";
    $text_message .= "Produits demandés :\n";

    foreach ($data['products'] as $product) {
        $quantity = isset($product['quantity']) ? intval($product['quantity']) : 1;
        $text_message .= "- " . $product['name'] . " (Quantité: " . $quantity . ")\n";
    }

    $text_message .= "\nLe devis est joint à cet email.\n";
    $text_message .= "Cordialement,\nLe système automatique de devis";

    // Génération du devis en Word (.docx)
    $file_path = wishlist_devis_generate_word($data['products'], $data);

    // Envoi de l'email avec pièce jointe
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: JBADev <wordpress@jbadev.com>'
    ];

    $attachments = [$file_path];

    // Utilisation de wp_mail avec le message HTML
    $mail_sent = wp_mail($admin_email, $subject, $html_message, $headers, $attachments);
    $mail_sent2 = wp_mail($admin_email2, $subject, $html_message, $headers, $attachments);

    if ($mail_sent) {
        wp_send_json(['message' => 'Votre demande de devis a été envoyée avec succès.']);
    } else {
        wp_send_json(['message' => 'Erreur lors de l\'envoi de l\'email.'], 500);
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

    // Ajouter des styles au document
    $fontStyle = ['name' => 'Arial', 'size' => 11];
    $headerStyle = ['name' => 'Arial', 'size' => 20, 'bold' => true, 'color' => '2B579A'];
    $subHeaderStyle = ['name' => 'Arial', 'size' => 14, 'bold' => true, 'color' => '2B579A'];
    $tableHeaderStyle = ['name' => 'Arial', 'size' => 11, 'bold' => true, 'color' => 'FFFFFF'];
    $tableStyle = [
        'borderSize' => 6,
        'borderColor' => '2B579A',
        'cellMargin' => 80,
        'alignment' => \PhpOffice\PhpWord\SimpleType\JcTable::CENTER
    ];
    $cellStyle = ['valign' => 'center'];
    $cellBoldStyle = ['valign' => 'center', 'bgColor' => '2B579A'];

    // Définir les propriétés du document
    $properties = $phpWord->getDocInfo();
    $properties->setCreator('JBADev');
    $properties->setCompany('JBADev');
    $properties->setTitle('Devis');
    $properties->setDescription('Devis généré pour ' . $data['name']);
    $properties->setCategory('Devis');

    // Ajouter une section au document
    $section = $phpWord->addSection([
        'marginTop' => 1000,
        'marginRight' => 1000,
        'marginBottom' => 1000,
        'marginLeft' => 1000,
    ]);

    // Ajouter un en-tête avec le logo
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
        $logoCell->addImage($logo_path, ['width' => 100]);
    }

    // Informations entreprise à droite
    $companyCell = $headerTable->addCell(7000);
    $companyCell->addText('', ['size' => 14]);
    $companyCell->addText('JBADev', ['bold' => true, 'size' => 14, 'color' => '2B579A']);
    $companyCell->addText('15 bis rue du Capitaine le Drezen, 29730 TREFFIAGAT FRANCE', ['size' => 10]);
    $companyCell->addText('Tél: +33 7 86 30 26 76 | Email: jbastierdevillatte@gmail.com', ['size' => 10]);

    $headerTable->addRow(6);
    $headerTable->addCell(3000)->addText('');

    // Ajouter un pied de page
    // $footer = $section->addFooter();
    // $footer->addText('Devis généré le ' . date('d/m/Y'), ['size' => 8, 'italic' => true], ['alignment' => 'center']);
    // $footer->addText('JBADev - SIRET 123 456 789 00010', ['size' => 8], ['alignment' => 'center']);

    // Ajouter un séparateur après l'en-tête
    // $section->addText('', ['size' => 6]);
    $section->addLine(['weight' => 1, 'width' => 450, 'height' => 0, 'color' => '2B579A']);
    // $section->addText('', ['size' => 6]);

    // Titre du document avec numéro de devis
    $devisNumber = 'DV-' . date('Ymd') . '-' . rand(1000, 9999);
    $section->addText('DEVIS', $headerStyle, ['alignment' => 'center']);
    $section->addText('N° ' . $devisNumber, ['name' => 'Arial', 'size' => 12, 'bold' => true], ['alignment' => 'center']);
    $section->addTextBreak(1);

    // Ajouter les informations du client dans un cadre
    $clientInfoTable = $section->addTable(['borderSize' => 1, 'borderColor' => '2B579A', 'cellMargin' => 80]);
    $clientInfoTable->addRow();
    $clientCell = $clientInfoTable->addCell(9000);
    $clientCell->addText('INFORMATIONS CLIENT', ['name' => 'Arial', 'size' => 12, 'bold' => true, 'color' => '2B579A']);
    $clientCell->addText('Nom: ' . $data['name'], $fontStyle);
    $clientCell->addText('Email: ' . $data['email'], $fontStyle);
    $clientCell->addText('Date du devis: ' . date('d/m/Y'), $fontStyle);
    // $clientCell->addText('Validité: 30 jours', $fontStyle);
    $section->addTextBreak(1);

    // Récupérer les données du fichier Excel
    $excelData = get_excel_data();

    // Texte d'introduction
    $section->addText('Nous vous remercions pour votre demande de devis. Veuillez trouver ci-dessous le détail des produits sélectionnés :', ['name' => 'Arial', 'size' => 11, 'italic' => true]);
    $section->addTextBreak(1);

    // Créer une table pour les produits avec un style amélioré
    $table = $section->addTable($tableStyle);

    // En-têtes de la table avec fond coloré
    $table->addRow(500); // Hauteur de ligne fixe pour l'en-tête
    $table->addCell(2000, $cellBoldStyle)->addText('Référence', $tableHeaderStyle);
    $table->addCell(2800, $cellBoldStyle)->addText('Désignation', $tableHeaderStyle);
    $table->addCell(1100, $cellBoldStyle)->addText('Quantité', $tableHeaderStyle);
    $table->addCell(1000, $cellBoldStyle)->addText('Prix HT', $tableHeaderStyle);
    $table->addCell(1200, $cellBoldStyle)->addText('Total HT', $tableHeaderStyle);
    $table->addCell(1800, $cellBoldStyle)->addText('Image', $tableHeaderStyle);

    // Parcourir les produits
    $totalHT = 0;
    $rowCount = 0;

    // $all_references = []; // Tableau pour stocker toutes les références uniques

    foreach ($products as $product) {
        // Récupérer la quantité (avec 1 comme valeur par défaut)
        $quantity = isset($product['quantity']) ? intval($product['quantity']) : 1;

        // Vérifier s'il s'agit de références multiples (séparées par ou non par "&")
        // et les diviser en conséquence
        $product_name = trim($product['name']);
        $temp_references = [];
        $unique_references = [];
        $reference_quantities = [];

        // D'abord, diviser par le séparateur "&" s'il est présent
        if (strpos($product_name, '&') !== false) {
            $temp_references = array_map('trim', explode('&', $product_name));
        } else {
            // Sinon, utiliser une expression régulière pour trouver les motifs de référence
            // La lettre peut être majuscule ou minuscule
            $pattern = '/([A-Za-z](?:\s+\d+){2,4})/';

            if (preg_match_all($pattern, $product_name, $matches)) {
                $temp_references = $matches[0];
            } else {
                // Si aucun motif n'est trouvé, utiliser la chaîne complète
                $temp_references = [$product_name];
            }
        }

        // Normaliser et dédupliquer les références
        foreach ($temp_references as $ref) {
            $ref = trim($ref);

            // Standardiser la référence pour comparaison
            // 1. Convertir la première lettre en majuscule
            if (!empty($ref) && strlen($ref) > 0) {
                $standardized_ref = strtoupper(substr($ref, 0, 1)) . substr($ref, 1);
            } else {
                $standardized_ref = $ref;
            }

            // 2. Extraire les composants de la référence pour une comparaison structurée
            $ref_components = preg_split('/\s+/', $standardized_ref);
            $letter = isset($ref_components[0]) ? $ref_components[0] : '';

            // Reconstruire la référence standardisée avec les composants
            $standard_key = implode('_', $ref_components);

            // **NOUVELLE VÉRIFICATION** pour voir si la référence est déjà traitée
            // if (isset($all_references[$standard_key])) {
            //     continue; // On ignore la référence car elle a déjà été ajoutée
            // }

            // $all_references[$standard_key] = true; // Ajouter au tableau global

            // Vérifier si cette référence existe déjà
            if (isset($reference_quantities[$standard_key])) {
                // Référence déjà vue, incrémenter la quantité
                $reference_quantities[$standard_key] += $quantity;
            } else {
                // Nouvelle référence, l'ajouter à notre liste
                $unique_references[$standard_key] = $standardized_ref;
                $reference_quantities[$standard_key] = $quantity;
            }
        }

        foreach ($unique_references as $key => $ref) {
            // Récupérer la quantité pour cette référence
            $ref_quantity = $reference_quantities[$key];

            // Extraire les composants pour traitement
            $ref_components = preg_split('/\s+/', $ref);
            $letter = isset($ref_components[0]) ? strtoupper($ref_components[0]) : '';

            // Initialiser les numéros avec des valeurs par défaut
            $num1 = isset($ref_components[1]) ? $ref_components[1] : '';
            $num2 = isset($ref_components[2]) ? $ref_components[2] : '';
            $num3 = isset($ref_components[3]) ? $ref_components[3] : '';

            // Rechercher les infos dans les données Excel
            $price = '';
            $designation = '';

            foreach ($excelData as $row) {
                // Essayer de faire correspondre les composants disponibles
                if (
                    $row['letter'] == $letter &&
                    (empty($num1) || $row['num1'] == $num1) &&
                    (empty($num2) || $row['num2'] == $num2) &&
                    (empty($num3) || $row['num3'] == $num3)
                ) {

                    $price = $row['price'];
                    $designation = $row['designation'];
                    break;
                }
            }

            // Alterner les couleurs de ligne pour une meilleure lisibilité
            $rowCount++;
            $cellRowStyle = $cellStyle;
            if ($rowCount % 2 == 0) {
                $cellRowStyle = array_merge($cellStyle, ['bgColor' => 'F2F2F2']);
            }

            // Vérifier si le prix est valide
            $price = str_replace(',', '.', $price); // Remplacer la virgule par un point pour la conversion
            $price = floatval($price);
            // Vérifier si le prix est valide
            if ($price === 0) {
                $price = 0; // Si le prix est invalide, le mettre à 0
            }

            // Vérifier si la quantité est valide
            $ref_quantity = max(1, $reference_quantities[$standard_key]);

            // Ajouter la ligne produit
            $table->addRow();
            $table->addCell(2000, $cellRowStyle)->addText($ref, $fontStyle);
            $table->addCell(2800, $cellRowStyle)->addText($designation, $fontStyle);
            $table->addCell(1100, $cellRowStyle)->addText($ref_quantity, $fontStyle);
            $table->addCell(1000, $cellRowStyle)->addText($price . ' €', $fontStyle);

            // Calculer et ajouter le total par ligne
            $lineTotal = $price * $ref_quantity;
            $table->addCell(1200, $cellRowStyle)->addText(number_format($lineTotal, 2, ',', ' ') . ' €', $fontStyle);

            // Ajouter l'image si disponible
            $cell = $table->addCell(1800, $cellRowStyle);
            if (!empty($product['img']) && $product['img'] !== 'N/A') {
                // Convertir l'URL de l'image en chemin local si nécessaire
                $img_path = convert_url_to_path($product['img']);
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

            // Ajouter au total HT
            $totalHT += (float)$price * $ref_quantity;
        }
    }

    // Ajouter le total dans un tableau dédié pour une meilleure présentation
    $section->addTextBreak(1);

    $totalTable = $section->addTable(['borderSize' => 0, 'cellMargin' => 80, 'alignment' => \PhpOffice\PhpWord\SimpleType\JcTable::END]);

    // Total HT
    $totalTable->addRow();
    $totalTable->addCell(3000, ['borderBottomSize' => 1, 'borderBottomColor' => '2B579A'])->addText('Total HT', ['bold' => true]);
    $totalTable->addCell(2000, ['borderBottomSize' => 1, 'borderBottomColor' => '2B579A'])->addText(number_format($totalHT, 2, ',', ' ') . ' €', ['bold' => true]);

    // TVA
    $tva = $totalHT * 0.2;
    $totalTable->addRow();
    $totalTable->addCell(3000, ['borderBottomSize' => 1, 'borderBottomColor' => '2B579A'])->addText('TVA (20%)', $fontStyle);
    $totalTable->addCell(2000, ['borderBottomSize' => 1, 'borderBottomColor' => '2B579A'])->addText(number_format($tva, 2, ',', ' ') . ' €', $fontStyle);

    // Total TTC
    $totalTTC = $totalHT * 1.2;
    $totalTable->addRow();
    $totalTable->addCell(3000, ['borderBottomSize' => 2, 'borderBottomColor' => '2B579A'])->addText('Total TTC', ['bold' => true, 'size' => 12]);
    $totalTable->addCell(2000, ['borderBottomSize' => 2, 'borderBottomColor' => '2B579A'])->addText(number_format($totalTTC, 2, ',', ' ') . ' €', ['bold' => true, 'size' => 12]);

    // Ajouter les conditions du devis
    // $section->addTextBreak(1);
    // $section->addText('CONDITIONS DU DEVIS', $subHeaderStyle);

    // $conditionsTable = $section->addTable(['borderSize' => 1, 'borderColor' => '2B579A', 'cellMargin' => 80]);
    // $conditionsTable->addRow();
    // $condCell = $conditionsTable->addCell(9000);
    // $condCell->addText('Validité du devis : 30 jours à compter de la date d\'émission', $fontStyle);
    // $condCell->addText('Délai de livraison : 2 à 3 semaines à compter de la validation de la commande', $fontStyle);
    // $condCell->addText('Conditions de paiement : 50% à la commande, solde à la livraison', $fontStyle);
    // $condCell->addText('Garantie : Tous nos produits sont garantis 1 an pièces et main d\'œuvre', $fontStyle);

    // Note de bas de page
    $section->addTextBreak(1);
    $section->addText('Pour valider ce devis, merci de nous le retourner signé avec la mention "Bon pour accord".', ['italic' => true, 'size' => 10]);
    $section->addText('Nous vous remercions de votre confiance et restons à votre disposition pour tout renseignement complémentaire.', ['italic' => true, 'size' => 10]);

    // Signature
    $section->addTextBreak(2);
    $signatureTable = $section->addTable();
    $signatureTable->addRow();
    $signatureTable->addCell(4500)->addText('Signature du client :', ['bold' => true]);
    // $signatureTable->addCell(4500)->addText('Pour JBADev :', ['bold' => true]);

    // Générer le fichier
    $objWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');

    $upload_dir = wp_upload_dir();
    $file_name = 'Devis_JBADev_' . date('Ymd_His') . '.docx';
    $file_path = $upload_dir['path'] . '/' . $file_name;

    $objWriter->save($file_path);

    return $file_path;
}

// Fonction pour récupérer les données du fichier Excel
function get_excel_data()
{
    // Initialiser un tableau pour stocker les données
    $excelData = [];

    // Récupérer le contenu du fichier Excel en ligne
    $excel_url = 'https://docs.google.com/spreadsheets/d/1OfjkbSpPK3CcvsMbWGabFrdW0cOnxHE9/edit?usp=sharing&ouid=103467285749476133477&rtpof=true&sd=true';

    // Pour Google Sheets, il faut généralement convertir l'URL pour pouvoir télécharger
    // Format: https://docs.google.com/spreadsheets/d/[ID]/export?format=csv
    $download_url = preg_replace('/\/edit\?usp=sharing.*$/', '/export?format=csv', $excel_url);

    // Télécharger le contenu
    $response = wp_remote_get($download_url);

    if (is_wp_error($response)) {
        // En cas d'erreur, retourner un tableau vide
        return $excelData;
    }

    $csvContent = wp_remote_retrieve_body($response);

    // Analyser le CSV
    $rows = explode("\n", $csvContent);
    foreach ($rows as $row) {
        $columns = str_getcsv($row);

        // Vérifier que nous avons suffisamment de colonnes
        if (count($columns) >= 13) {
            $letterIndex = 5;  // Colonne F (index 5)
            $num1Index = 6;    // Colonne G (index 6)
            $num2Index = 7;    // Colonne H (index 7)
            $num3Index = 8;    // Colonne I (index 8)
            $priceIndex = 10;  // Colonne K (index 10)
            $descIndex = 12;   // Colonne M (index 12)

            $excelData[] = [
                'letter' => isset($columns[$letterIndex]) ? trim($columns[$letterIndex]) : '',
                'num1' => isset($columns[$num1Index]) ? trim($columns[$num1Index]) : '',
                'num2' => isset($columns[$num2Index]) ? trim($columns[$num2Index]) : '',
                'num3' => isset($columns[$num3Index]) ? trim($columns[$num3Index]) : '',
                'price' => isset($columns[$priceIndex]) ? trim($columns[$priceIndex]) : '',
                'designation' => isset($columns[$descIndex]) ? trim($columns[$descIndex]) : ''
            ];
        }
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
