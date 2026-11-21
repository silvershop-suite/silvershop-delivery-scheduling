<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><%t SilverShop\DeliveryScheduling\Email\DeliveryNotificationEmail.OutHeading "Your order is on its way" %></title>
    <style>
        body { margin: 0; padding: 0; background: #f5f6f8; color: #1c2733;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        .shell { max-width: 600px; margin: 0 auto; padding: 24px 16px; }
        .card { background: #ffffff; border: 1px solid #e3e7ec; border-radius: 12px; padding: 28px; }
        h1 { font-size: 22px; margin: 0 0 12px; }
        p { margin: 0 0 14px; font-size: 15px; line-height: 1.5; }
        .ref { font-weight: 600; font-size: 16px; }
        .window { background: #eef7f0; border: 1px solid #c7e6cf; border-radius: 10px; padding: 14px; font-size: 15px; margin: 14px 0; }
        .window strong { display: block; margin-bottom: 4px; }
        .fineprint { color: #8a97a4; font-size: 12px; margin-top: 20px; }
    </style>
</head>
<body>
<div class="shell">
    <div class="card">
        <h1><%t SilverShop\DeliveryScheduling\Email\DeliveryNotificationEmail.OutHeading "Your order is on its way" %></h1>

        <p><%t SilverShop\DeliveryScheduling\Email\DeliveryNotificationEmail.OutIntro "Good news: your order is out for delivery and should be with you soon." %></p>

        <% if $Order %>
            <p class="ref"><%t SilverShop\DeliveryScheduling\Email\DeliveryNotificationEmail.Order "Order" %> #$Order.Reference</p>
        <% end_if %>

        <% if $WindowSummary %>
            <div class="window">
                <strong><%t SilverShop\DeliveryScheduling\Email\DeliveryNotificationEmail.WindowLabel "Requested window" %></strong>
                $WindowSummary
            </div>
        <% end_if %>

        <p class="fineprint"><%t SilverShop\DeliveryScheduling\Email\DeliveryNotificationEmail.OutFineprint "You are receiving this because your order is scheduled for delivery." %></p>
    </div>
</div>
</body>
</html>
