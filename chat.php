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

ini_set('default_socket_timeout', 3600);
set_time_limit(3600);

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

function sendSSE($data) {
    echo "data: " . json_encode($data) . "\n\n";
    flush();
}

$data = json_decode(file_get_contents('php://input'), true);
$userMessage = isset($data['message']) ? trim($data['message']) : '';
$chatHistory = isset($data['history']) ? $data['history'] : [];

if ($userMessage === '') {
    sendSSE(['error' => 'Empty message.']);
    exit;
}

function callOpenAI($messages, $stream) {
    $api_url = 'http://192.168.0.3:1234/v1/chat/completions';
    $model = 'gpt-4o-mini'; 
    $temperature = 0.3; 
    $max_tokens = 1500; 
    $api_key = 'YOUR_API_KEY'; 
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 3600); 

    if ($stream) {

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) {

            $lines = explode("\n", $data);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (strpos($line, 'data: ') === 0 || strpos($line, 'data:') === 0) {
                    echo $line . "\n\n";
                    flush();
                }
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
            sendSSE(['error' => $error_msg]);
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

function prepareRecentHistory($chatHistory, $limit = 5) { 
    $recentHistory = array_slice($chatHistory, -$limit);
    $messages = [];
    foreach ($recentHistory as $entry) {
        if ($entry['sender'] === 'user') {
            $messages[] = ['role' => 'user', 'content' => $entry['text']];
        } else if ($entry['sender'] === 'assistant') {
            $messages[] = ['role' => 'assistant', 'content' => $entry['text']];
        }

    }
    return $messages;
}

$messagesAI1 = prepareRecentHistory($chatHistory);

$systemPromptAI1 = "You received this message: [$userMessage].

You are an AI that uses reasoning and chain of thought to generate a detailed plan to address the user's request.

Generate a detailed plan to address the user's request.

- Each step should be done in logical order to address the request.
- The plan should have a dynamic number of steps, as many as needed depending on the difficulty of the task.
- Ensure that each step is clear, specific, and focuses on one task.
- Format the plan with each step on a new line.
- Do not return the final answer to the user; instead, plan out how you will solve the problem.
- Each step should be as if it's instructions or prompts to another AI to solve the problem.
- Provide your response strictly within the <PLAN> tags.
- generate as much steps as you think logically needed to address the request.
Example format (do not include the word 'Step' and the number; output directly the title of the step):

<PLAN>
**step one title**
**step two title**
**step three title**
**step four title**
...
</PLAN>
";

$messagesAI1[] = ['role' => 'system', 'content' => $systemPromptAI1];
$messagesAI1[] = ['role' => 'user', 'content' => $userMessage];

$ai1Response = callOpenAI($messagesAI1, false);

if (isset($ai1Response['error'])) {
    sendSSE(['error' => 'Error in AI1 initial plan: ' . $ai1Response['error']]);
    exit;
}

$planOutline = $ai1Response['content'];

preg_match('/<PLAN>(.*?)<\/PLAN>/s', $planOutline, $matches);
$planContent = trim($matches[1] ?? $planOutline);

$planSteps = preg_split('/\n+/', $planContent);
$planSteps = array_filter($planSteps, function($line) {
    return trim($line) !== '';
});
$planSteps = array_values($planSteps); 

sendSSE(['full_thought_process' => $planContent]);
flush();

$executionHistory = [];
$reflectionHistory = [];
$maxIterations = count($planSteps);
$iteration = 0;
$done = false;

while (!$done && $iteration < $maxIterations) {
    $currentStepTitle = $planSteps[$iteration] ?? '';

    sendSSE(['current_step' => $currentStepTitle]);
    flush();

    $iteration++;

    $messagesAI2 = prepareRecentHistory($chatHistory);

    $stepsDoneSoFar = implode("\n", array_slice($planSteps, 0, $iteration - 1));

    $remainingSteps = implode("\n", array_slice($planSteps, $iteration - 1));

    $systemPromptAI2 = "You are an assistant executing steps to address the user's request based on a given plan.

Here are the steps done so far:
<STEPS_DONE>
$stepsDoneSoFar
</STEPS_DONE>

Here are the remaining steps:
<STEPS_REMAINING>
$remainingSteps
</STEPS_REMAINING>

Your task:
- Carefully read the next step provided by the user.
- Before executing, ensure you fully understand the user's request and the specific tasks required.
- Break down and execute the next step precisely and in detail.
- After execution, reflect on the result to verify its correctness.
- Provide a detailed reflection explaining what went well, any errors found, and how you corrected them.
- Use precise calculations or coding as needed, employing reasoning and logic as a human would.
- Maintain consistent formatting and clarity in your response.
- Provide your response strictly within the <NEXT_STEP> tags.

Format:
<NEXT_STEP>
<EXECUTION>
[Execution of the next step]
</EXECUTION>
<REFLECTION>
[Detailed reflection on the execution, including any corrections]
</REFLECTION>
</NEXT_STEP>

Important:
- Do not skip any steps.
- Ensure that both <EXECUTION> and <REFLECTION> sections are filled.
- If you have completed all steps, include '<done>' in your reflection.
";

    $messagesAI2[] = ['role' => 'system', 'content' => $systemPromptAI2];

    foreach ($executionHistory as $index => $historyEntry) {
        $reflectionEntry = $reflectionHistory[$index] ?? '';
        $messagesAI2[] = ['role' => 'assistant', 'content' => "<EXECUTION>\n" . $historyEntry . "\n</EXECUTION>\n<REFLECTION>\n" . $reflectionEntry . "\n</REFLECTION>"];
    }

    $messagesAI2[] = ['role' => 'user', 'content' => $currentStepTitle];

    $ai2Response = callOpenAI($messagesAI2, false);

    if (isset($ai2Response['error'])) {
        sendSSE(['error' => 'Error in AI2 execution: ' . $ai2Response['error']]);
        exit;
    }

    $nextStepResult = $ai2Response['content'];

    if (preg_match('/<NEXT_STEP>(.*?)<\/NEXT_STEP>/s', $nextStepResult, $stepMatches)) {
        $nextStepContent = trim($stepMatches[1]);
    } else {

        $nextStepContent = $nextStepResult;
    }

    if (preg_match('/<EXECUTION>(.*?)<\/EXECUTION>/s', $nextStepContent, $execMatches)) {
        $executionContent = trim($execMatches[1]);
    } else {

        $executionContent = $nextStepContent;
    }

    if (preg_match('/<REFLECTION>(.*?)<\/REFLECTION>/s', $nextStepContent, $reflMatches)) {
        $reflectionContent = trim($reflMatches[1]);
    } else {

        $reflectionContent = '';
    }

    if (strpos($reflectionContent, '<done>') !== false || strpos($executionContent, '<done>') !== false || $iteration >= $maxIterations) {
        $done = true;
        $reflectionContent = str_replace('<done>', '', $reflectionContent);
        $executionContent = str_replace('<done>', '', $executionContent);
    }

    $executionHistory[] = $executionContent;
    $reflectionHistory[] = $reflectionContent;

    sendSSE(['debug' => 'AI2 Execution', 'step' => $iteration, 'execution' => $executionContent, 'reflection' => $reflectionContent]);
}

$finalExecutedPlan = '';
foreach ($executionHistory as $index => $execution) {
    $reflection = $reflectionHistory[$index] ?? '';
    $finalExecutedPlan .= "<EXECUTION>\n" . $execution . "\n</EXECUTION>\n";
    if ($reflection) {
        $finalExecutedPlan .= "<REFLECTION>\n" . $reflection . "\n</REFLECTION>\n";
    }
}

$messagesAI3 = prepareRecentHistory($chatHistory);

$systemPromptAI3 = "You are an assistant tasked with providing a comprehensive answer to the user's request based on the executed plan and reflections.

Here is the executed plan and reflections:
<EXECUTED_PLAN>
$finalExecutedPlan
</EXECUTED_PLAN>

Your task:
- Provide a complete, clear, concise, and informative answer to the user's original message.
- Incorporate insights from the reflections to improve the final answer.
- Ensure all calculations and code are accurate.
- Do not mention the plan, execution steps, or reflections in your final answer; just provide the direct response.
";

$messagesAI3[] = ['role' => 'system', 'content' => $systemPromptAI3];
$messagesAI3[] = ['role' => 'user', 'content' => $userMessage];

callOpenAI($messagesAI3, true);

sendSSE(['status' => '[DONE]']);
flush();
exit();
?>
