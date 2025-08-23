
<% if $Fields %>
    <form $FormAttributes>
        <% if $Message %>
            <div class="form-message-container">
                <p id="{$FormName}_error" class="message $MessageType flash-message">$Message</p>
            </div>
        <% end_if %>
        
        <fieldset>
            <% loop $Fields %>
                $FieldHolder
            <% end_loop %>
        </fieldset>
        
        <!-- Loading indicator for tour availability -->
        <div id="availability-loading" class="availability-loading" style="display: none;">
            <div class="loading-spinner">
                <div class="spinner"></div>
                <p>Loading available tours...</p>
            </div>
        </div>
        
        <% if $Actions %>
            <div class="Actions">
                <% loop $Actions %>
                    $Field
                <% end_loop %>
            </div>
        <% end_if %>
    </form>
<% end_if %>
