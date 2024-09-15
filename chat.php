<?php
// chat.php
// Increase timeout because your server might be a sleepy potato
ini_set('default_socket_timeout', 3600);
// Increase PHP runtime so the server can take a nap if needed
set_time_limit(3600);

// Turn off output buffering, because who needs it anyway?
if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip', '1');
}
ini_set('zlib.output_compression', 'Off'); // Compression is overrated
while (ob_get_level() > 0) {
    ob_end_clean(); // Because layers of buffering are like onions – sometimes they just make you cry
}

// Headers, because without them, things fall apart
header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache'); // Because we like things fresh
header('Connection: keep-alive'); // Keep that connection alive like a houseplant you forgot to water

// Get the POST data (or lack thereof, depending on how your day is going)
$data = json_decode(file_get_contents('php://input'), true);
$userMessage = isset($data['message']) ? trim($data['message']) : '';
$chatHistory = isset($data['history']) ? $data['history'] : [];

if ($userMessage === '') {
    echo "data: " . json_encode(['error' => 'Empty message. Try again, human.']) . "\n\n";
    exit;
}

// Function to call the AI API (because AI does everything now, right?)
function callOpenAI($messages, $stream) {
    $api_url = 'http://macbook:1234/v1/chat/completions'; // Replace this with the actual API if you're serious
    $model = 'llama-3.1'; // Not a real llama, but we wish it was
    $temperature = 0.8; // Perfect room temperature for some spicy creativity
    $max_tokens = 4000; // That's a lot of tokens. Don't spend them all in one place
    $api_key = 'your-api-key-here'; // If you don't have one, just cry in the corner
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key, // Bearer of bad news if you forgot to add your key
    ];

    $postData = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => $temperature, // Just the right amount of warm fuzzies
        'max_tokens' => $max_tokens, // More tokens = more fun
        'stream' => $stream // Stream like it's Netflix, but with AI
    ];

    $ch = curl_init($api_url); // Let's spin up some curls – it’s workout time
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));

    if ($stream) {
        // Streaming mode activated
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) {
            // Ensure data is in SSE format – because regular data is just too basic
            $lines = explode("\n", $data); // Breaking the data like it’s a piñata
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue; // Empty lines are not welcome here
                }
                if (strpos($line, 'data: ') === 0) {
                    // Already in SSE format? How convenient
                    echo $line . "\n\n";
                } else {
                    // Prepend 'data: ' because it's polite to follow the format
                    echo "data: " . $line . "\n\n";
                }
                flush(); // Because you don’t want your data clogged up like a bad toilet
            }
            return strlen($data); // Return the length of the data – size matters, apparently
        });
    } else {
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return it like a library book
    }

    $response = curl_exec($ch); // Execute like a boss

    if (curl_errno($ch)) {
        $error_msg = 'Curl error: ' . curl_error($ch); // If something goes wrong, just blame curl
        curl_close($ch); // Close the curl and walk away
        if ($stream) {
            echo "data: " . json_encode(['error' => $error_msg]) . "\n\n"; // Apologize to the client
        } else {
            return ['error' => $error_msg]; // Return the error like a bad report card
        }
        exit; // Because sometimes you just need to leave
    }

    curl_close($ch); // Curling is over, time to cool down

    if (!$stream) {
        $result = json_decode($response, true); // Decode the response like a spy reading encrypted messages

        if (isset($result['choices'][0]['message']['content'])) {
            return ['content' => $result['choices'][0]['message']['content']]; // Jackpot! We got the content
        } else {
            return ['error' => 'API response error: ' . json_encode($result)]; // When life gives you errors, make error-ade
        }
    }
}

// Prepare messages for the first API call (AI1)
// The first step in our master plan to communicate with the bots
$messagesFirstCall = [];

// Add recent chat history (excluding thought processes, because nobody wants to know how the sausage is made)
$historyLimit = 30; // Limiting history like your browser’s incognito mode
$recentHistory = array_slice($chatHistory, -$historyLimit);

foreach ($recentHistory as $entry) {
    if ($entry['sender'] === 'user') {
        $messagesFirstCall[] = ['role' => 'user', 'content' => $entry['text']]; // User said something, better remember it
    } else if ($entry['sender'] === 'assistant') {
        $messagesFirstCall[] = ['role' => 'assistant', 'content' => $entry['text']]; // Assistant mumbled something too
    }
    // Exclude 'thought_process' entries because thoughts are private, even for robots
}

// Add system prompt for AI1 – because AI needs a plan too
$systemPromptAI1 = "You received this message: [$userMessage], Help solve the user's request by generating a detailed step-by-step plan. 
Please ensure that your thought process is clear and detailed, as if you're instructing yourself on how to tailor an answer. 
Do not return an answer, just return the thought process as if it's between you and yourself. Please provide your response strictly in the following format and respect the <THOUGHT> tags: <THOUGHT> 

[step by step plan of how to answer the user's message one per line, use bullet points and line breaks]

</THOUGHT>";

$messagesFirstCall[] = ['role' => 'system', 'content' => $systemPromptAI1];

// Add the user's message because that's what started this whole thing
$messagesFirstCall[] = ['role' => 'user', 'content' => $userMessage];

// First API call – Time for AI to put on its thinking cap
$firstResponse = callOpenAI($messagesFirstCall, false);

if (isset($firstResponse['error'])) {
    echo "data: " . json_encode(['error' => 'Error in first API call: ' . $firstResponse['error']]) . "\n\n"; // Oops, something broke
    exit;
}

$thoughtProcess = $firstResponse['content']; // AI’s deep inner thoughts

// Send the thought process as the first SSE message (optional, for "Show Thought Process" feature)
echo "data: " . json_encode(['thought_process' => $thoughtProcess]) . "\n\n";
flush(); // Keep that stream flowing

// Prepare messages for the second API call (AI2) – Round two!
$messagesSecondCall = [];

// Add recent chat history to AI2 as well, because everyone likes a good recap
foreach ($recentHistory as $entry) {
    if ($entry['sender'] === 'user') {
        $messagesSecondCall[] = ['role' => 'user', 'content' => $entry['text']]; // User chatter, round 2
    } else if ($entry['sender'] === 'assistant') {
        $messagesSecondCall[] = ['role' => 'assistant', 'content' => $entry['text']]; // Assistant input because why not
    }
    // Exclude 'thought_process' entries again because privacy is important
}

// Add system prompt for AI2 – because it's time for the AI to stop thinking and start talking
$systemPromptAI2 = "You are a human reflecting on your own thought process to provide an answer to the user.

Here is your thought process suggesting how to answer:
<thought_process>
//this is for internal use only & reference
$thoughtProcess
here is the user message: $userMessage
</thought_process>

Your task:
Provide an answer to the user's request based on your thought process.

**Important:** Do not include the thought process or mention that you reviewed it in your final answer. Just provide an answer to the user.";

$messagesSecondCall[] = ['role' => 'system', 'content' => $systemPromptAI2];

// Provide the thought process to AI2 as an assistant message
//$messagesSecondCall[] = ['role' => 'assistant', 'content' => $thoughtProcess]; // No need to spill all the beans here

// Add the user's message again because the user always has the last word
$messagesSecondCall[] = ['role' => 'user', 'content' => $userMessage];

// Second API call – Time for the AI to finally spill the beans and give an answer
callOpenAI($messagesSecondCall, true);

// End the SSE stream with a bang
echo "data: [DONE]\n\n";
flush(); // Flushing like your browser history after a long day
?>
