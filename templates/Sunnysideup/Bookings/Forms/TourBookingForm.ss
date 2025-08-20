
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
        
        <% if $Actions %>
            <div class="Actions">
                <% loop $Actions %>
                    $Field
                <% end_loop %>
            </div>
        <% end_if %>
    </form>
<% end_if %>
