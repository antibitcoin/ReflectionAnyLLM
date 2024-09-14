<?php
// chat.php
//increase timeout if your server is potato
ini_set('default_socket_timeout', 3600);
//increase php runtime
set_time_limit(3600);

// Turn off output buffering
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

// Get the POST data
$data = json_decode(file_get_contents('php://input'), true);
$userMessage = isset($data['message']) ? trim($data['message']) : '';
$chatHistory = isset($data['history']) ? $data['history'] : [];

if ($userMessage === '') {
    echo "data: " . json_encode(['error' => 'Empty message.']) . "\n\n";
    exit;
}

// Function to call the AI API
function callOpenAI($messages, $stream) {
    $api_url = 'http://localhost:1234/v1/chat/completions';
    $model = 'llama-3.1'; // this is not really important if using lmstudio
    $temperature = 0.8;
    $max_tokens = 4000;
    $api_key = 'your-api-key-here'; // Add your API key here if using some online thingy like groq or openrouter or openai
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key,
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
        // For streaming
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) {
            // Ensure data is in SSE format
            // Split the data in case multiple messages are concatenated
            $lines = explode("\n", $data);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (strpos($line, 'data: ') === 0) {
                    // Already in SSE format
                    echo $line . "\n\n";
                } else {
                    // Prepend 'data: ' if missing
                    echo "data: " . $line . "\n\n";
                }
                flush();
            }
            return strlen($data);
        });
    } else {
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
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

// Add recent chat history (excluding thought processes)
$historyLimit = 10; // Adjust as needed
$recentHistory = array_slice($chatHistory, -$historyLimit);

foreach ($recentHistory as $entry) {
    if ($entry['sender'] === 'user') {
        $messagesFirstCall[] = ['role' => 'user', 'content' => $entry['text']];
    } else if ($entry['sender'] === 'assistant') {
        $messagesFirstCall[] = ['role' => 'assistant', 'content' => $entry['text']];
    }
    // Exclude 'thought_process' entries
}

// Add system prompt for AI1
$systemPromptAI1 = "Help solve the user's request by generating a detailed step-by-step plan.
Please ensure that your thought process is clear and detailed, as if you are instructing your self on how to tailor an answer.
do not return an answer, just return the though process as if it's between you and your self.
Please provide your response strictly in the following format and respect the <THOUGHT> tags:
<THOUGHT> [Your short step-by-step plan] </THOUGHT>

";

$messagesFirstCall[] = ['role' => 'system', 'content' => $systemPromptAI1];

// Add the user's message
$messagesFirstCall[] = ['role' => 'user', 'content' => $userMessage];

// First API call
$firstResponse = callOpenAI($messagesFirstCall, false);

if (isset($firstResponse['error'])) {
    echo "data: " . json_encode(['error' => 'Error in first API call: ' . $firstResponse['error']]) . "\n\n";
    exit;
}

$thoughtProcess = $firstResponse['content'];

// Send the thought process as the first SSE message (optional, for "Show Thought Process" feature)
echo "data: " . json_encode(['thought_process' => $thoughtProcess]) . "\n\n";
flush();

// Prepare messages for the second API call (AI2)
$messagesSecondCall = [];

// Add recent chat history to AI2 as well
foreach ($recentHistory as $entry) {
    if ($entry['sender'] === 'user') {
        $messagesSecondCall[] = ['role' => 'user', 'content' => $entry['text']];
    } else if ($entry['sender'] === 'assistant') {
        $messagesSecondCall[] = ['role' => 'assistant', 'content' => $entry['text']];
    }
    // Exclude 'thought_process' entries
}

// Add system prompt for AI2
$systemPromptAI2 = "You are a human reflecting on your own thought process to provide a refined final answer to the user.

Here is your thought process:
$thoughtProcess

Your task:


1. Provide a final answer to the user's request based on your thought process.

**Important:** Do not include the thought process or mention that you reviewed it in your final answer. Just provide the final answer to the user.

The user's original request:

$userMessage
";

$messagesSecondCall[] = ['role' => 'system', 'content' => $systemPromptAI2];

// Provide the thought process to AI2 as an assistant message
//$messagesSecondCall[] = ['role' => 'assistant', 'content' => $thoughtProcess];

// Add the user's message again
$messagesSecondCall[] = ['role' => 'user', 'content' => $userMessage];

// Second API call
callOpenAI($messagesSecondCall, true);

// End the SSE stream
echo "data: [DONE]\n\n";
flush();
?>
