<%t SilverShop\DeliveryScheduling\Email\DeliveryNotificationEmail.OutHeading "Your order is on its way" %>

<%t SilverShop\DeliveryScheduling\Email\DeliveryNotificationEmail.OutIntro "Good news: your order is out for delivery and should be with you soon." %>
<% if $Order %>
<%t SilverShop\DeliveryScheduling\Email\DeliveryNotificationEmail.Order "Order" %> #$Order.Reference
<% end_if %>
<% if $WindowSummary %>
<%t SilverShop\DeliveryScheduling\Email\DeliveryNotificationEmail.WindowLabel "Requested window" %>: $WindowSummary
<% end_if %>
<%t SilverShop\DeliveryScheduling\Email\DeliveryNotificationEmail.OutFineprint "You are receiving this because your order is scheduled for delivery." %>
