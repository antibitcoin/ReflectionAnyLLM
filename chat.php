<?php
/*
    MIT License (Totally Serious Edition)
    -------------------------------------
    Permission is hereby granted, free of charge, to any person obtaining a copy
    of this software and associated documentation files (the "Software"), to deal
    in the Software without restriction, including without limitation the rights
    to use, copy, modify, merge, publish, distribute, and/or sell copies of the Software, 
    BUT with one tiny request: please be kind and link back to this project, 
    because hey, sharing is caring!

    THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND. So if this 
    software crashes your space station or misplaces your car keys, that's on you.

    The MIT License, 2024. (Fun version)
*/

// chat.php

// Extend the timeout because, you know, AI isn't fast enough yet
ini_set('default_socket_timeout', 3600);
set_time_limit(3600);

// Turn off output buffering so we can deliver real-time goodness, like Netflix for code
if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip', '1'); // Disable gzip, we need this raw and unfiltered
}
ini_set('zlib.output_compression', 'Off'); // Compression is for suckers
while (ob_get_level() > 0) {
    ob_end_clean(); // Clean everything like it's spring cleaning day
}

// Set the headers for SSE (Server-Sent Events, not Secret Super Events, sadly)
header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache'); // No cache, because the future is now
header('Connection: keep-alive');  // We aren't saying goodbye anytime soon

// Function to send SSE messages to the client (aka "Let's chat!")
function sendSSE($data) {
    echo "data: " . json_encode($data) . "\n\n"; // Yeah, we’re sending JSON over SSE, deal with it
    flush(); // This sends the message to the browser. No waiting around!
}

// Grab that sweet POST data
$data = json_decode(file_get_contents('php://input'), true);
$userMessage = isset($data['message']) ? trim($data['message']) : '';
$chatHistory = isset($data['history']) ? $data['history'] : [];

// In case someone sends an empty message because... reasons
if ($userMessage === '') {
    sendSSE(['error' => 'Empty message. Like, what am I supposed to do with that?']);
    exit;
}

// Function to call OpenAI-compatible API (Yeah, it can be LM Studio, OpenRouter, Ollama, or anything that gets the job done)
function callOpenAI($messages, $stream) {
    $api_url = 'https://api.openai.com/v1/chat/completions'; // Change this to your favorite LLM endpoint. It’s like picking your favorite pizza topping.
    $model = 'gpt-4o-mini'; // Why mini? Because big things come in small packages
    $temperature = 0.8; // Spicy level: medium-well
    $max_tokens = 4000; // Because nobody likes getting cut off mid-conversation
    $api_key = 'your-api-key-here'; // Replace this with your magical API key, unless you're into free API limits
    
    // Headers: because APIs like well-dressed requests
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key, // This bearer brings honey (and AI responses)
    ];

    // Assemble the request data like an IKEA cabinet
    $postData = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => $temperature,
        'max_tokens' => $max_tokens,
        'stream' => $stream
    ];

    $ch = curl_init($api_url); // Get that curl nice and ready
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData)); // Send the JSON, because we like things structured
    curl_setopt($ch, CURLOPT_TIMEOUT, 3600); // Because we don’t like waiting forever... just an hour

    if ($stream) {
        // For those fancy real-time streaming responses. It's like live TV, but less dramatic.
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) {
            // SSE expects things to be streamed line by line, like poetry, but for developers
            $lines = explode("\n", $data);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (strpos($line, 'data: ') === 0 || strpos($line, 'data:') === 0) {
                    echo $line . "\n\n"; // Send it all to the client
                    flush(); // Flush it like a pro (no pun intended)
                }
            }
            return strlen($data); // Tell curl how much we just processed
        });
    } else {
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return the whole response in one big package, like a surprise
    }

    $response = curl_exec($ch); // Make the API call. Go, go, go!

    // If curl decides to take a nap instead of working
    if (curl_errno($ch)) {
        $error_msg = 'Curl error: ' . curl_error($ch); // Ugh, curl… again?
        curl_close($ch);
        if ($stream) {
            sendSSE(['error' => $error_msg]); // Tell the client curl messed up
        } else {
            return ['error' => $error_msg]; // More private error handling, because we're cool like that
        }
        exit;
    }

    curl_close($ch);

    if (!$stream) {
        $result = json_decode($response, true); // Decode the JSON like it’s an ancient scroll

        // Check if we got a good response
        if (isset($result['choices'][0]['message']['content'])) {
            return ['content' => $result['choices'][0]['message']['content']];
        } else {
            return ['error' => 'API response error: ' . json_encode($result)];
        }
    }
}

// Function to prepare recent chat history, because we don’t want the AI to forget its manners
function prepareRecentHistory($chatHistory, $limit = 30) {
    $recentHistory = array_slice($chatHistory, -$limit); // Only the freshest memories
    $messages = [];
    foreach ($recentHistory as $entry) {
        if ($entry['sender'] === 'user') {
            $messages[] = ['role' => 'user', 'content' => $entry['text']]; // User’s deep thoughts
        } else if ($entry['sender'] === 'assistant') {
            $messages[] = ['role' => 'assistant', 'content' => $entry['text']]; // Assistant's sage advice
        }
        // Leave out the boring stuff
    }
    return $messages;
}

// AI wizardry begins here
$messagesAI1 = prepareRecentHistory($chatHistory);

// Set the system up for some serious planning. You don’t just "wing" AI
$systemPromptAI1 = "You received this message: [$userMessage]. Generate a detailed plan to address the user's request.
- The plan should have a dynamic number of steps, as many as needed.
- Format the plan using bold for step titles and bullet points in Markdown, with each step on a new line.
- Do not return the final answer to the user; instead, plan out how you will solve the problem.
- Provide your response strictly within the <PLAN> tags.
";

// Add this gem of a plan request to our messages
$messagesAI1[] = ['role' => 'system', 'content' => $systemPromptAI1];
$messagesAI1[] = ['role' => 'user', 'content' => $userMessage];

// AI’s first step: planning mode activated
$ai1Response = callOpenAI($messagesAI1, false);

// Did the AI get stuck on the first step? That’s embarrassing
if (isset($ai1Response['error'])) {
    sendSSE(['error' => 'Error in AI1 initial plan: ' . $ai1Response['error']]);
    exit;
}

$planOutline = $ai1Response['content'];

// Step by step, bit by bit
preg_match('/<PLAN>(.*?)<\/PLAN>/s', $planOutline, $matches);
$planContent = trim($matches[1] ?? $planOutline);

// Let's split those steps like a banana
$planSteps = preg_split('/\n+/', $planContent);
$planSteps = array_filter($planSteps, function($line) {
    return trim($line) !== ''; // No empty steps here, thank you very much
});
$planSteps = array_values($planSteps); // Re-index because we like clean arrays

// Send the thought process to the front-end for the "Show Thought Process" button (because why not?)
sendSSE(['full_thought_process' => $planContent]);
flush(); // Make sure it gets there safely

// AI continues to execute the plan like a determined project manager
$executionHistory = [];
$maxIterations = count($planSteps); // We don’t want to loop forever, do we?
$iteration = 0;
$done = false;

while (!$done && $iteration < $maxIterations) {
    $currentStepTitle = $planSteps[$iteration] ?? '';
    sendSSE(['current_step' => $currentStepTitle]); // "Look! I’m doing something!"
    flush();

    $iteration++;

    $messagesAI2 = prepareRecentHistory($chatHistory);

    // Execution history—because AI can have a memory span longer than a goldfish
    if (!empty($executionHistory)) {
        $executionHistoryText = implode("\n", $executionHistory);
        $messagesAI2[] = ['role' => 'assistant', 'content' => $executionHistoryText];
    }

    // Next, execute the next step. It’s like AI's to-do list, but cooler.
    $systemPromptAI2 = "You are executing the following plan step by step to address the user's request.
<PLAN>
$planOutline
</PLAN>

Here are the steps you have completed so far:
<EXECUTION_HISTORY>
" . implode("\n", $executionHistory) . "
</EXECUTION_HISTORY>

Your task:
- Execute the next step in the plan...
- Tag <done> when you're done.
";

// AI, execute! (But don’t hurt anyone.)
$messagesAI2[] = ['role' => 'system', 'content' => $systemPromptAI2];
$messagesAI2[] = ['role' => 'user', 'content' => $userMessage];

$ai2Response = callOpenAI($messagesAI2, false);

if (isset($ai2Response['error'])) {
    sendSSE(['error' => 'Error in AI2 execution: ' . $ai2Response['error']]);
    exit;
}

$nextStepResult = $ai2Response['content'];

// AI thinks it's done? Better check for the <done> tag.
if (strpos($nextStepResult, '<done>') !== false || $iteration >= $maxIterations) {
    $done = true;
    $nextStepResult = str_replace('<done>', '', $nextStepResult);
}

$executionHistory[] = $nextStepResult;
}

// When the AI has finally finished all steps, it presents the final, glorious plan
$finalExecutedPlan = implode("\n", $executionHistory);

// Final step: AI3 wraps it all up like a shiny present
$messagesAI3 = prepareRecentHistory($chatHistory);

$systemPromptAI3 = "You are an assistant tasked with providing a comprehensive answer to the user's request based on the executed plan.

<EXECUTED_PLAN>
$finalExecutedPlan
</EXECUTED_PLAN>

- Just give a direct, final answer. The user doesn't care about your process.
";

// Call AI3 for the final answer, stream it like the latest episode of your favorite show
callOpenAI($messagesAI3, true);

// End the SSE stream like we’re walking off stage
sendSSE(['status' => '[DONE]']);
flush();
exit();
?>
