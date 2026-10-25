<%t SilverShop\DeliveryScheduling\Email\DeliveryNotificationEmail.ScheduledHeading "Your order is scheduled" %>

<%t SilverShop\DeliveryScheduling\Email\DeliveryNotificationEmail.ScheduledIntro "Thanks — we have scheduled your order. Here are the details." %>
<% if $Order %>
<%t SilverShop\DeliveryScheduling\Email\DeliveryNotificationEmail.Order "Order" %> #$Order.Reference
<% end_if %>
<% if $WindowSummary %>
<%t SilverShop\DeliveryScheduling\Email\DeliveryNotificationEmail.WindowLabel "Requested window" %>: $WindowSummary
<% end_if %>
<% if $StoreName %>
$StoreName
<% end_if %>
<%t SilverShop\DeliveryScheduling\Email\DeliveryNotificationEmail.ScheduledFineprint "You are receiving this because you scheduled a delivery or pickup for your order." %>
