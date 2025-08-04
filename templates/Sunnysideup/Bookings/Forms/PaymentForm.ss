<% if $isPaymentEnabled %>
    <div class="payment-section">
        <h3>Payment Information</h3>
        <p>Total Amount: $<span class="payment-amount">$TotalAmount</span></p>
        
        <div class="stripe-payment-form">
            <div id="card-element" class="stripe-element"></div>
            <div id="card-errors" class="stripe-error" role="alert"></div>
        </div>
    </div>
<% end_if %> 