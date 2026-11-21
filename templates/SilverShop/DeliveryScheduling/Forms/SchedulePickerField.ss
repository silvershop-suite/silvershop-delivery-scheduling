<ul $AttributesHTML>
    <% loop $Options %>
        <li class="$Class" data-contexts="$Contexts">
            <input id="$ID"
                   class="radio"
                   name="$Name"
                   type="radio"
                   role="$Role"
                   value="$Value"
                   <% if $isChecked %>checked<% end_if %>
                   <% if $isDisabled %>disabled<% end_if %>
            />
            <label class="form-label" for="$ID">$Title</label>
        </li>
    <% end_loop %>
</ul>
<p class="message warning" data-schedule-empty hidden><%t SilverShop\DeliveryScheduling\Forms\SchedulePickerField.NoneForMethod 'No dates are available for this choice right now; we will contact you to arrange a date.' %></p>
