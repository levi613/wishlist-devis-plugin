document.addEventListener('DOMContentLoaded', function() {
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
    // const requestBtn = document.getElementById('request-devis-btn');
    const devisForm = document.getElementById('devis-form');
    const nameInput = document.getElementById('devis-name');
    const emailInput = document.getElementById('devis-email');
    const sendBtn = document.getElementById('send-devis');
    const cancelBtn = document.getElementById('cancel-devis');
    const nameError = document.getElementById('name-error');
    const emailError = document.getElementById('email-error');
    const successMessage = document.getElementById('devis-form-success');
    const errorMessage = document.getElementById('devis-form-error');

    // Afficher le formulaire
    requestBtn.addEventListener('click', function() {
        devisForm.classList.add('active');
        // Cacher le bouton de demande quand le formulaire est affiché
        requestBtn.style.display = 'none';
    });

    // Cacher le formulaire
    cancelBtn.addEventListener('click', function() {
        devisForm.classList.remove('active');
        // Réafficher le bouton de demande
        requestBtn.style.display = 'block';
        // Réinitialiser les champs et messages d'erreur
        resetForm();
    });

    // Envoyer le formulaire
    sendBtn.addEventListener('click', function() {
        // Réinitialiser les messages d'erreur précédents
        nameError.textContent = '';
        emailError.textContent = '';
        errorMessage.textContent = '';
        successMessage.textContent = '';
        successMessage.classList.remove('active');

        // Valider les champs
        let isValid = true;
        
        // Validation du nom
        if (!nameInput.value.trim()) {
            nameError.textContent = 'Veuillez entrer votre nom';
            isValid = false;
        }
        
        // Validation de l'email
        if (!emailInput.value.trim()) {
            emailError.textContent = 'Veuillez entrer votre email';
            isValid = false;
        } else if (!isValidEmail(emailInput.value.trim())) {
            emailError.textContent = 'Veuillez entrer un email valide';
            isValid = false;
        }

        if (!isValid) {
            return;
        }

        // Récupérer les produits
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
        
        // Envoyer la requête
        fetch(wishlistDevisAjax.ajaxurl + '?action=send_devis', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                action: 'send_devis', 
                name: nameInput.value.trim(), 
                email: emailInput.value.trim(), 
                products: products 
            })
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Erreur serveur');
            }
            return response.json();
        })
        .then(data => {
            // Réactiver le bouton
            sendBtn.disabled = false;
            sendBtn.textContent = 'Envoyer';
            
            // Afficher le message de succès
            successMessage.textContent = data.message;
            successMessage.classList.add('active');
            
            // Réinitialiser le formulaire après 3 secondes
            setTimeout(() => {
                resetForm();
                devisForm.classList.remove('active');
                requestBtn.style.display = 'block';
            }, 3000);
        })
        .catch(error => {
            console.error('Erreur:', error);
            
            // Réactiver le bouton
            sendBtn.disabled = false;
            sendBtn.textContent = 'Envoyer';
            
            // Afficher le message d'erreur
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
        nameInput.value = '';
        emailInput.value = '';
        nameError.textContent = '';
        emailError.textContent = '';
        errorMessage.textContent = '';
        successMessage.textContent = '';
        successMessage.classList.remove('active');
    }
});