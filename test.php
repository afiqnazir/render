<?php
// Telegram Bot Token
$bot_token = '7656950864:AAGD-ATsjJhrmHPo-1DjVWnf7ZCwl7U5gRk';
$admin_chat_id = '5272331552';

// Set webhook
file_get_contents("https://api.telegram.org/bot$bot_token/setWebhook?url=https://payment.hamsterswap.store/test.php");

// Helper function to send a message
function sendMessage($chat_id, $text, $reply_markup = null) {
    global $bot_token;
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'Markdown'
    ];
    if ($reply_markup) {
        $data['reply_markup'] = $reply_markup;
    }
    file_get_contents("https://api.telegram.org/bot$bot_token/sendMessage?" . http_build_query($data));
}

// Helper function to log messages
function logMessage($message) {
    file_put_contents('log.txt', date('Y-m-d H:i:s') . " - $message\n", FILE_APPEND);
}

// Process incoming updates
$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) {
    exit;
}

$chat_id = $update['message']['chat']['id'];
$username = $update['message']['chat']['username'];
$text = $update['message']['text'];

logMessage("Received message: $text from chat_id: $chat_id");

if ($text == '/start') {
    sendMessage($chat_id, "👋 Welcome, @$username! Please enter the amount you wish to add using /addfund command followed by the amount.");
} elseif (strpos($text, '/addfund') === 0) {
    $parts = explode(' ', $text);
    if (count($parts) != 2 || !is_numeric($parts[1])) {
        sendMessage($chat_id, 'Usage: /addfund <amount>');
        exit;
    }
    $amount = $parts[1];
    // Make API call to create order
    $response = file_get_contents('https://test.hostdod.com/api/create-order', false, stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query([
                'customer_mobile' => '8145344963',
                'user_token' => 'f52b9d4c191c1e9f10587d44bc04f357',
                'amount' => $amount,
                'order_id' => uniqid(),
                'redirect_url' => 'https://test.hostdod.com',
                'remark1' => 'testremark',
                'remark2' => 'testremark2',
                'route' => '1'
            ])
        ]
    ]));
    $response_data = json_decode($response, true);

    if ($response_data['status']) {
        $payment_url = $response_data['result']['payment_url'];
        $order_id = $response_data['result']['orderId'];
        sendMessage($chat_id, "💰 Please complete the payment: [Payment Link]($payment_url)");
        sendMessage($chat_id, "🆔 Your Order ID is: `$order_id`. Use `/checkstatus $order_id` to check the payment status.");
        // Store order_id and amount to track payment status later
        file_put_contents("data/$chat_id.txt", json_encode(['order_id' => $order_id, 'amount' => $amount]));
    } else {
        sendMessage($chat_id, "❌ Error: " . $response_data['message']);
        logMessage("Error creating order: " . $response_data['message']);
    }
} elseif ($text == '/submitreview') {
    $data = json_decode(file_get_contents("data/$chat_id.txt"), true);
    if (!$data) {
        sendMessage($chat_id, '❌ No payment found. Please start the process using /addfund command.');
        exit;
    }
    $order_id = $data['order_id'];
    sendMessage($admin_chat_id, "🛎 User @$username (ID: $chat_id) has submitted a payment for review.\n🆔 Order ID: `$order_id`\n💵 Amount: " . $data['amount']);
    sendMessage($chat_id, '✅ Your payment has been submitted for review. The admin will approve it shortly.');
} elseif (strpos($text, '/checkstatus') === 0) {
    $parts = explode(' ', $text);
    if (count($parts) != 2) {
        sendMessage($chat_id, 'Usage: /checkstatus <order_id>');
        exit;
    }
    $order_id = $parts[1];
    check_payment_status($chat_id, $order_id);
}

function check_payment_status($chat_id, $order_id) {
    global $bot_token, $admin_chat_id;
    logMessage("Checking payment status for order_id: $order_id");

    $response = file_get_contents('https://test.hostdod.com/api/check-order-status', false, stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query([
                'user_token' => '5869846007a5fe4353aa946c919813a7',
                'order_id' => $order_id
            ])
        ]
    ]));
    $response_data = json_decode($response, true);

    if ($response_data['status']) {
        if ($response_data['result']['txnStatus'] == 'SUCCESS') {
            sendMessage($chat_id, "🎉 Payment Successful! Order ID: `$order_id`, Amount: " . $response_data['result']['amount']);
            sendMessage($admin_chat_id, "✅ Payment for Order ID: `$order_id` has been successfully processed.");
            logMessage("Payment successful for order_id: $order_id");
            // Send message with 'Submit for Review' button
            $keyboard = json_encode([
                'inline_keyboard' => [[
                    ['text' => 'Submit for Review', 'callback_data' => 'submit_review_' . $order_id]
                ]]
            ]);
            sendMessage($chat_id, "Please click the button below to submit for review.", $keyboard);
        } elseif ($response_data['result']['txnStatus'] == 'PENDING') {
            sendMessage($chat_id, "🕒 Payment is still pending. Order ID: `$order_id`");
            logMessage("Payment pending for order_id: $order_id");
        } else {
            sendMessage($chat_id, "❌ Payment failed or encountered an error: " . $response_data['message']);
            logMessage("Payment failed for order_id: $order_id. Error: " . $response_data['message']);
        }
    } else {
        sendMessage($chat_id, "❌ Payment failed or encountered an error: " . $response_data['message']);
        logMessage("API request failed for order_id: $order_id. Error: " . $response_data['message']);
    }
}

// Handle callback queries for inline buttons
if (isset($update['callback_query'])) {
    $callback_query = $update['callback_query'];
    $callback_data = $callback_query['data'];
    $chat_id = $callback_query['message']['chat']['id'];
    
    if (strpos($callback_data, 'submit_review_') === 0) {
        $order_id = str_replace('submit_review_', '', $callback_data);
        $data = json_decode(file_get_contents("data/$chat_id.txt"), true);
        if ($data['order_id'] === $order_id) {
            sendMessage($admin_chat_id, "🛎 User @$username (ID: $chat_id) has submitted a payment for review.\n🆔 Order ID: `$order_id`\n💵 Amount: " . $data['amount']);
            sendMessage($chat_id, '✅ Your payment has been submitted for review. The admin will approve it shortly.');
        }
    }
}

?>
