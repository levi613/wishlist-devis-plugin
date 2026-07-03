document.addEventListener('DOMContentLoaded', function () {
    // Vérifier si le tableau de wishlist existe et n'est pas vide
    const wishlistTable = document.querySelector('.wishlist-items-wrapper');
    const wishlistItems = wishlistTable ? wishlistTable.querySelectorAll('tr[data-item-id]') : [];
    const requestBtn = document.getElementById('request-devis-btn');

    // Cacher le bouton si le tableau n'existe pas ou s'il est vide
    if (!wishlistTable || wishlistItems.length === 0) {
        if (requestBtn) {
            requestBtn.style.display = 'none';
        }
        return; // Sortir si la wishlist est vide
    }

    // Éléments du DOM
    const devisForm = document.getElementById('devis-form');

    // Nouveaux champs du formulaire enrichi
    const customerTypeRadios = document.querySelectorAll('input[name="devis-customer-type"]');
    const companyFields = document.getElementById('devis-company-fields');
    const companyNameInput = document.getElementById('devis-company-name');
    const siretInput = document.getElementById('devis-siret');
    const fullNameInput = document.getElementById('devis-full-name');
    const countryInput = document.getElementById('devis-country');
    const postalCodeInput = document.getElementById('devis-postal-code');
    const cityInput = document.getElementById('devis-city');
    const addressInput = document.getElementById('devis-address');
    const emailInput = document.getElementById('devis-email');
    const phoneInput = document.getElementById('devis-phone');

    const sendBtn = document.getElementById('send-devis');
    const cancelBtn = document.getElementById('cancel-devis');
    const successMessage = document.getElementById('devis-form-success');
    const errorMessage = document.getElementById('devis-form-error');

    // Récupère le type de client actuellement sélectionné ('particulier' par défaut)
    function getSelectedCustomerType() {
        for (let i = 0; i < customerTypeRadios.length; i++) {
            if (customerTypeRadios[i].checked) {
                return customerTypeRadios[i].value;
            }
        }
        return 'particulier';
    }

    // Affiche/masque les champs société selon le type de client
    function onCustomerTypeChange(selectedType) {
        if (selectedType === 'professionnel') {
            // Professionnel : afficher les champs société
            if (companyFields) {
                companyFields.style.display = '';
            }
        } else {
            // Particulier : masquer ET vider les champs société
            if (companyFields) {
                companyFields.style.display = 'none';
            }
            if (companyNameInput) {
                companyNameInput.value = '';
            }
            if (siretInput) {
                siretInput.value = '';
            }
        }
    }

    // Brancher l'événement de changement sur les radios + état initial
    customerTypeRadios.forEach(function (radio) {
        radio.addEventListener('change', function () {
            onCustomerTypeChange(getSelectedCustomerType());
        });
    });
    // Refléter l'état du radio coché par défaut au chargement
    onCustomerTypeChange(getSelectedCustomerType());

    // Afficher le formulaire
    requestBtn.addEventListener('click', function () {
        devisForm.classList.add('active');
        // Cacher le bouton de demande quand le formulaire est affiché
        requestBtn.style.display = 'none';
    });

    // Cacher le formulaire
    cancelBtn.addEventListener('click', function () {
        devisForm.classList.remove('active');
        // Réafficher le bouton de demande
        requestBtn.style.display = 'block';
        // Réinitialiser les champs et messages d'erreur
        resetForm();
    });

    // Envoyer le formulaire
    sendBtn.addEventListener('click', function () {
        // Réinitialiser les messages précédents
        errorMessage.textContent = '';
        successMessage.textContent = '';
        successMessage.classList.remove('active');

        // Valider les champs
        let isValid = true;
        let validationErrors = [];

        // Validation du nom complet
        if (!fullNameInput || !fullNameInput.value.trim()) {
            validationErrors.push('Veuillez entrer votre prénom et nom.');
            isValid = false;
        }

        // Validation de l'email
        if (!emailInput || !emailInput.value.trim()) {
            validationErrors.push('Veuillez entrer votre email.');
            isValid = false;
        } else if (!isValidEmail(emailInput.value.trim())) {
            validationErrors.push('Veuillez entrer un email valide.');
            isValid = false;
        }

        // Validation des champs société pour les professionnels
        const customerType = getSelectedCustomerType();
        if (customerType === 'professionnel') {
            if (!companyNameInput || !companyNameInput.value.trim()) {
                validationErrors.push('Veuillez entrer le nom de la société.');
                isValid = false;
            }
            if (!siretInput || !siretInput.value.trim()) {
                validationErrors.push('Veuillez entrer le numéro de SIRET.');
                isValid = false;
            }
        }

        if (!isValid) {
            errorMessage.textContent = validationErrors.join(' ');
            return;
        }

        // Récupérer les produits (boucle de collecte existante, conservée)
        let products = [];
        document.querySelectorAll('.wishlist-items-wrapper tr').forEach(item => {
            // Vérifier que l'élément a les données nécessaires
            if (!item.getAttribute('data-item-id')) return;

            let productName = item.querySelector('.product-name')?.innerText;
            let productImg = item.querySelector('.product-thumbnail img')?.src || 'N/A';
            let quantityInput = item.querySelector('.quantity input.qty');
            let quantity = quantityInput ? quantityInput.value : 1;

            // S'assurer que productName existe
            if (productName) {
                products.push({
                    id: item.getAttribute('data-item-id'),
                    name: productName,
                    img: productImg,
                    quantity: quantity
                });
            }
        });

        // Si aucun produit trouvé
        if (products.length === 0) {
            errorMessage.textContent = 'Aucun produit dans votre liste de souhaits.';
            return;
        }

        // Désactiver le bouton pendant l'envoi
        sendBtn.disabled = true;
        sendBtn.textContent = 'Envoi en cours...';

        // Construire la charge utile enrichie (DevisPayload)
        const payload = {
            action: 'send_devis',
            customer_type: customerType,
            company_name: companyNameInput ? companyNameInput.value.trim() : '',
            siret: siretInput ? siretInput.value.trim() : '',
            full_name: fullNameInput ? fullNameInput.value.trim() : '',
            email: emailInput ? emailInput.value.trim() : '',
            phone: phoneInput ? phoneInput.value.trim() : '',
            country: countryInput ? countryInput.value.trim() : '',
            postal_code: postalCodeInput ? postalCodeInput.value.trim() : '',
            city: cityInput ? cityInput.value.trim() : '',
            address: addressInput ? addressInput.value.trim() : '',
            products: products
        };

        // Envoyer la requête
        fetch(wishlistDevisAjax.ajaxurl + '?action=send_devis', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
            .then(response => {
                // Le serveur répond avec un JSON plat {message, errors?} et un code 200/400/500.
                // On lit toujours le corps JSON pour récupérer le message, quel que soit le statut.
                return response.json().then(data => ({ ok: response.ok, data: data }));
            })
            .then(result => {
                // Réactiver le bouton
                sendBtn.disabled = false;
                sendBtn.textContent = 'Envoyer';

                const data = result.data || {};

                if (result.ok) {
                    // Afficher le message de succès (réf. incluse côté serveur)
                    successMessage.textContent = data.message || 'Votre demande a bien été envoyée.';
                    successMessage.classList.add('active');

                    // Réinitialiser le formulaire après 3 secondes
                    setTimeout(() => {
                        resetForm();
                        devisForm.classList.remove('active');
                        requestBtn.style.display = 'block';
                    }, 3000);
                } else {
                    // Conserver l'affichage du .message comme aujourd'hui
                    let message = data.message || 'Une erreur est survenue. Veuillez réessayer.';

                    // Si une carte d'erreurs de champ (400) est présente, on la complète au message
                    if (data.errors && typeof data.errors === 'object') {
                        const fieldMessages = Object.keys(data.errors)
                            .map(key => data.errors[key])
                            .filter(Boolean);
                        if (fieldMessages.length > 0) {
                            message = message + ' ' + fieldMessages.join(' ');
                        }
                    }

                    errorMessage.textContent = message;
                }
            })
            .catch(error => {
                console.error('Erreur:', error);

                // Réactiver le bouton
                sendBtn.disabled = false;
                sendBtn.textContent = 'Envoyer';

                // Afficher le message d'erreur générique
                errorMessage.textContent = 'Une erreur est survenue. Veuillez réessayer.';
            });
    });

    // Fonction pour valider un email
    function isValidEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }

    // Fonction pour réinitialiser le formulaire
    function resetForm() {
        if (companyNameInput) companyNameInput.value = '';
        if (siretInput) siretInput.value = '';
        if (fullNameInput) fullNameInput.value = '';
        if (countryInput) countryInput.value = '';
        if (postalCodeInput) postalCodeInput.value = '';
        if (cityInput) cityInput.value = '';
        if (addressInput) addressInput.value = '';
        if (emailInput) emailInput.value = '';
        if (phoneInput) phoneInput.value = '';

        // Rétablir le premier type de client (particulier) et l'état des champs société
        if (customerTypeRadios.length > 0) {
            customerTypeRadios.forEach(function (radio) {
                radio.checked = (radio.value === 'particulier');
            });
        }
        onCustomerTypeChange(getSelectedCustomerType());

        errorMessage.textContent = '';
        successMessage.textContent = '';
        successMessage.classList.remove('active');
    }
});
