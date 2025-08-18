<div class="payment-failure">
    <h2>Payment Failed</h2>
    <p class="message bad">Sorry, your payment could not be processed. This can happen for several reasons:</p>
    <ul>
        <li>Your card was declined</li>
        <li>Insufficient funds</li>
        <li>3D Secure authentication failed</li>
        <li>Network connection issues</li>
    </ul>
    <p>Please try again with a different payment method or contact your bank if the problem persists.</p>
    
    <% if $CurrentBooking %>
        <div class="booking-details">
            <h3>Booking Details</h3>
            <p><strong>Tour:</strong> $CurrentBooking.Tour.Title</p>
            <p><strong>Date:</strong> $CurrentBooking.BookingDate.Nice</p>
            <p><strong>Guests:</strong> $CurrentBooking.TotalNumberOfGuests</p>
        </div>
    <% end_if %>
    
    <div class="payment-failure-actions">
        <a href="$Link('signup')" class="button btn-primary">Try Booking Again</a>
        <% if $CurrentBooking %>
            <a href="mailto:info@pics.co.nz?subject=Payment Issue - Booking $CurrentBooking.Code" class="button btn-secondary">Contact Support</a>
        <% else %>
            <a href="mailto:info@pics.co.nz?subject=Payment Issue" class="button btn-secondary">Contact Support</a>
        <% end_if %>
    </div>
</div> 