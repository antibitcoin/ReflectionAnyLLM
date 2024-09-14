<?php
// chat.php
// Increase timeout because patience is a virtue, but we’re still coding, not meditating
ini_set('default_socket_timeout', 3600);
// Increase PHP runtime because some chats just never end...
set_time_limit(3600);

// Turn off output buffering to let the data flow like a river (or something poetic)
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

// Get the POST data like it's a gift, but carefully
$data = json_decode(file_get_contents('php://input'), true);

// Validate that JSON is valid because life’s too short to deal with bad JSON
if (json_last_error() !== JSON_ERROR_NONE) {
    echo "data: " . json_encode(['error' => 'Invalid JSON data.']) . "\n\n";
    exit;
}

// Sanitize user input because we don't want any mischief-makers injecting bad stuff
$userMessage = isset($data['message']) ? htmlspecialchars(trim($data['message']), ENT_QUOTES, 'UTF-8') : '';
$chatHistory = isset($data['history']) ? array_map('htmlspecialchars', $data['history']) : [];

if ($userMessage === '') {
    echo "data: " . json_encode(['error' => 'Empty message.']) . "\n\n";
    exit;
}

// Function to call the AI API, making sure everything is as secure as possible
function callOpenAI($messages, $stream) {
    $api_url = 'http://macbook:1234/v1/chat/completions'; // Feel free to change this to something cooler
    $model = 'llama-3.1'; // Of course, this llama is well-trained
    $temperature = 0.8; // Keeping things spicy but not too spicy
    $max_tokens = 4000; // Let’s not make the AI too chatty

    $headers = [
        'Content-Type: application/json',
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
        // For streaming, like binge-watching a series but nerdier
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) {
            // Ensure data is in SSE format because that's how we roll
            $lines = explode("\n", $data);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue; // We don’t want to waste bandwidth on blank lines, do we?
                }
                if (strpos($line, 'data: ') === 0) {
                    // Already in SSE format, yay!
                    echo $line . "\n\n";
                } else {
                    // Prepend 'data: ' if missing, because we’re perfectionists
                    echo "data: " . $line . "\n\n";
                }
                flush(); // Because nobody likes waiting
            }
            return strlen($data);
        });
    } else {
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Get the result, no drama
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
        exit;
    }

    curl_close($ch);

    if (!$stream) {
        $result = json_decode($response, true);

        if (isset($result['choices'][0]['message']['content'])) {
            return ['content' => $result['choices'][0]['message']['content']];
        } else {
            return ['error' => 'API response error: ' . json_encode($result)];
        }
    }
}

// Prepare messages for the first API call (AI1)
$messagesFirstCall = [];

// Add recent chat history (excluding thought processes because we like a clean slate)
$historyLimit = 10; // Limit history to keep things manageable
$recentHistory = array_slice($chatHistory, -$historyLimit);

foreach ($recentHistory as $entry) {
    if ($entry['sender'] === 'user') {
        $messagesFirstCall[] = ['role' => 'user', 'content' => $entry['text']];
    } else if ($entry['sender'] === 'assistant') {
        $messagesFirstCall[] = ['role' => 'assistant', 'content' => $entry['text']];
    }
    // Exclude 'thought_process' entries because AI doesn’t need to overthink things
}

// Add system prompt for AI1, because AI needs instructions too
$systemPromptAI1 = "Help solve the user's request by generating a detailed step-by-step plan.
Please ensure that your thought process is clear and detailed, as if you are instructing your self on how to tailor an answer.
do not return an answer, just return the thought process as if it's between you and yourself.
Please provide your response strictly in the following format and respect the <THOUGHT> tags:
<THOUGHT> [Your short step-by-step plan] </THOUGHT>";

$messagesFirstCall[] = ['role' => 'system', 'content' => $systemPromptAI1];

// Add the user's message (because that’s kind of the whole point)
$messagesFirstCall[] = ['role' => 'user', 'content' => $userMessage];

// First API call (no pressure, but this better work)
$firstResponse = callOpenAI($messagesFirstCall, false);

if (isset($firstResponse['error'])) {
    echo "data: " . json_encode(['error' => 'Error in first API call: ' . $firstResponse['error']]) . "\n\n";
    exit;
}

$thoughtProcess = $firstResponse['content'];

// Send the thought process as the first SSE message (optional, for "Show Thought Process" feature)
echo "data: " . json_encode(['thought_process' => $thoughtProcess]) . "\n\n";
flush();

// Prepare messages for the second API call (AI2) because one AI isn't enough sometimes
$messagesSecondCall = [];

// Add recent chat history to AI2 as well, we don’t play favorites
foreach ($recentHistory as $entry) {
    if ($entry['sender'] === 'user') {
        $messagesSecondCall[] = ['role' => 'user', 'content' => $entry['text']];
    } else if ($entry['sender'] === 'assistant') {
        $messagesSecondCall[] = ['role' => 'assistant', 'content' => $entry['text']];
    }
    // Again, exclude 'thought_process' entries because we don’t need AI introspection
}

// Add system prompt for AI2, the grand finale AI
$systemPromptAI2 = "You are a human reflecting on your own thought process to provide a refined final answer to the user.

Here is your thought process:
$thoughtProcess

Your task:

1. Provide a final answer to the user's request based on your thought process.

**Important:** Do not include the thought process or mention that you reviewed it in your final answer. Just provide the final answer to the user.

The user's original request:

$userMessage";

$messagesSecondCall[] = ['role' => 'system', 'content' => $systemPromptAI2];

// Add the user's message again, just to be safe
$messagesSecondCall[] = ['role' => 'user', 'content' => $userMessage];

// Second API call (bring it home, AI2)
callOpenAI($messagesSecondCall, true);

// End the SSE stream because all good things must come to an end
echo "data: [DONE]\n\n";
flush();
?>
