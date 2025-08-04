(function() {
    'use strict';
    
    // Only initialize if Stripe is available and payment is enabled
    if (typeof Stripe !== 'undefined' && window.paymentEnabled) {
        const stripe = Stripe(window.stripePublishableKey);
        const elements = stripe.elements();
        
        const card = elements.create('card');
        card.mount('#card-element');
        
        // Handle form submission
        const form = document.querySelector('.booking-form');
        if (form) {
            form.addEventListener('submit', function(event) {
                if (window.paymentRequired) {
                    event.preventDefault();
                    processPayment();
                }
            });
        }
        
        function processPayment() {
            stripe.createToken(card).then(function(result) {
                if (result.error) {
                    const errorElement = document.getElementById('card-errors');
                    errorElement.textContent = result.error.message;
                } else {
                    // Add token to form and submit
                    const tokenInput = document.createElement('input');
                    tokenInput.type = 'hidden';
                    tokenInput.name = 'stripeToken';
                    tokenInput.value = result.token.id;
                    form.appendChild(tokenInput);
                    form.submit();
                }
            });
        }
    }
})(); 