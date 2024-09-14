<?php
// chat.php
// Increase timeout because some servers are slow as molasses
ini_set('default_socket_timeout', 3600);
// Increase PHP runtime because, well, who knows how long this chat will go...
set_time_limit(3600);

// Turn off output buffering because we’re here for real-time action, not slow-mo
if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip', '1');
}
ini_set('zlib.output_compression', 'Off');
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

// Get the POST data, because user messages don’t just float in the ether
$data = json_decode(file_get_contents('php://input'), true);

// Sanitize the user's message and history because we don’t trust anyone, even ourselves
$userMessage = isset($data['message']) ? htmlspecialchars(trim($data['message']), ENT_QUOTES, 'UTF-8') : '';
$chatHistory = isset($data['history']) ? array_map(function($item) {
    // Gotta clean those inputs or your app might explode. Or, you know, just not work.
    return [
        'sender' => htmlspecialchars(trim($item['sender']), ENT_QUOTES, 'UTF-8'),
        'text' => htmlspecialchars(trim($item['text']), ENT_QUOTES, 'UTF-8')
    ];
}, $data['history']) : [];

if ($userMessage === '') {
    // No message? No chat. That’s just how this works.
    echo "data: " . json_encode(['error' => 'Empty message.']) . "\n\n";
    exit;
}

// Function to call the AI API (aka the magic spell function)
function callOpenAI($messages, $stream) {
    $api_url = 'YOUR_API_ENDPOINT_HERE'; // Replace with your API endpoint, unless you're feeling adventurous
    $model = 'YOUR_MODEL_HERE'; // Replace with your model, or don't, we don't judge
    $api_key = "YOUR_API_KEY_HERE"; // Replace with your API key, aka the golden ticket
    $temperature = 0.8; // Keep it cool, but not too cool
    $max_tokens = 4000; // Because nobody likes a chatty AI... except when they do

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key, // Because without this, nobody's listening
    ];

    $postData = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => $temperature,
        'max_tokens' => $max_tokens,
        'stream' => $stream
    ];

    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));

    if ($stream) {
        // For streaming, like binge-watching a show, but with more data and fewer popcorn breaks
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) {
            // Stream data like it’s hot! Or at least lukewarm...
            $lines = explode("\n", $data);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue; // Nobody likes empty lines. Not even AI.
                }
                if (strpos($line, 'data: ') === 0) {
                    // Already in SSE format, gold star!
                    echo $line . "\n\n";
                } else {
                    // Prepend 'data: ' because we’re nice like that
                    echo "data: " . $line . "\n\n";
                }
                flush(); // Flush it like a plumber in a rush
            }
            return strlen($data);
        });
    } else {
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return the result, no drama
    }

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $error_msg = 'Curl error: ' . curl_error($ch);
        curl_close($ch);
        if ($stream) {
            echo "data: " . json_encode(['error' => $error_msg]) . "\n\n";
        } else {
            return ['error' => $error_msg];
        }
        exit; // It didn’t work, so let’s just walk away casually
    }

    curl_close($ch);

    if (!$stream) {
        $result = json_decode($response, true);

        if (isset($result['choices'][0]['message']['content'])) {
            // Success! The AI has spoken.
            return ['content' => $result['choices'][0]['message']['content']];
        } else {
            // Something went wrong. Did we break the AI?
            return ['error' => 'API response error: ' . json_encode($result)];
        }
    }
}

// Prepare messages for the first API call (AI1) – because one AI is never enough
$messagesFirstCall = [];

// Add recent chat history (excluding thought processes, because we’re not overthinkers, right?)
$historyLimit = 10; // We don’t need AI to remember everything, just the juicy bits
$recentHistory = array_slice($chatHistory, -$historyLimit);

foreach ($recentHistory as $entry) {
    if ($entry['sender'] === 'user') {
        $messagesFirstCall[] = ['role' => 'user', 'content' => $entry['text']];
    } else if ($entry['sender'] === 'assistant') {
        $messagesFirstCall[] = ['role' => 'assistant', 'content' => $entry['text']];
    }
}

// Add system prompt for AI1, because every good AI needs a little nudge in the right direction
$systemPromptAI1 = "Help solve the user's request by generating a detailed step-by-step plan.
Please ensure that your thought process is clear and detailed, as if you are instructing yourself on how to tailor an answer.
Do not return an answer, just return the thought process as if it's between you and yourself.
Please provide your response strictly in the following format and respect the <THOUGHT> tags:
<THOUGHT> [Your short step-by-step plan] </THOUGHT>";

$messagesFirstCall[] = ['role' => 'system', 'content' => $systemPromptAI1];

// Add the user's message, because that’s what this is all about
$messagesFirstCall[] = ['role' => 'user', 'content' => $userMessage];

// First API call (no pressure, AI)
$firstResponse = callOpenAI($messagesFirstCall, false);

if (isset($firstResponse['error'])) {
    // Error? Oh no! Better luck next API call...
    echo "data: " . json_encode(['error' => 'Error in first API call: ' . $firstResponse['error']]) . "\n\n";
    exit;
}

$thoughtProcess = $firstResponse['content'];

// Send the thought process as the first SSE message (optional, for those who love deep thoughts)
echo "data: " . json_encode(['thought_process' => $thoughtProcess]) . "\n\n";
flush(); // Because we’re not keeping secrets here

// Prepare messages for the second API call (AI2) – because two AIs are better than one
$messagesSecondCall = [];

// Add recent chat history to AI2 as well (we don’t play favorites here)
foreach ($recentHistory as $entry) {
    if ($entry['sender'] === 'user') {
        $messagesSecondCall[] = ['role' => 'user', 'content' => $entry['text']];
    } else if ($entry['sender'] === 'assistant') {
        $messagesSecondCall[] = ['role' => 'assistant', 'content' => $entry['text']];
    }
}

// Add system prompt for AI2 – time for the big finale
$systemPromptAI2 = "You are a human reflecting on your own thought process to provide a refined final answer to the user.

Here is your thought process:
$thoughtProcess

Your task:

1. Provide a final answer to the user's request based on your thought process.

**Important:** Do not include the thought process or mention that you reviewed it in your final answer. Just provide the final answer to the user.

The user's original request:

$userMessage";

$messagesSecondCall[] = ['role' => 'system', 'content' => $systemPromptAI2];

// Add the user's message again, just for good measure
$messagesSecondCall[] = ['role' => 'user', 'content' => $userMessage];

// Second API call – let's hope AI gets it right this time
callOpenAI($messagesSecondCall, true);

// End the SSE stream, because all good things must come to an end
echo "data: [DONE]\n\n";
flush(); // We’re done here. Go home, folks!
?>
