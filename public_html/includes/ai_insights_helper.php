<?php
/**
 * Shared AI-insights helper.
 *
 * Provides a single, reusable way to call Gemini / OpenAI for "insight
 * bullet" generation, with a deterministic rule-based fallback when the
 * provider is missing, the request fails, or the response can't be parsed.
 *
 * Both the admin audit-log insights (api/admin/audit_insights.php) and the
 * nutritionist dashboard insights (api/nutritionist/dashboard_insights.php)
 * route through this helper so the provider/key/model handling and
 * fallback policy live in one place.
 *
 * Per-feature config (e.g. NUTRITIONIST_AI_PROVIDER, NUTRITIONIST_AI_KEY,
 * NUTRITIONIST_AI_MODEL) is checked first so each feature can target a
 * different model / limit independently. If those are absent, the shared
 * CHATBOT_* keys are used, and if those are also absent the fallback runs.
 *
 * Public API:
 *   ai_insights_generate([
 *     'summary_text'   => string,
 *     'system_message' => string,           // optional
 *     'provider'       => 'gemini'|'openai',// optional override
 *     'api_key'        => string,           // optional override
 *     'model'          => string,           // optional override
 *     'max_tokens'     => int,              // default 500
 *     'temperature'    => float,            // default 0.3
 *     'fallback'       => callable,         // returns ['source' => 'rule_based', 'insights' => [...]]
 *     'feature_tag'    => string,           // used in error logs, e.g. 'nutritionist_dashboard'
 *   ]): array
 */

if (!function_exists('ai_insights_generate')) {
    /**
     * Run the AI insight generation, falling back to a rule-based
     * generator if anything goes wrong.
     */
    function ai_insights_generate(array $options): array
    {
        $summaryText   = (string)($options['summary_text'] ?? '');
        $systemMessage = (string)($options['system_message'] ?? '');
        $fallback      = $options['fallback'] ?? null;
        $featureTag    = (string)($options['feature_tag'] ?? 'ai_insights');
        $maxTokens     = (int)($options['max_tokens'] ?? 500);
        $temperature   = (float)($options['temperature'] ?? 0.3);

        // Resolve the provider/key/model with feature-level overrides first,
        // then the shared CHATBOT_* keys. Either layer can disable the AI
        // call by leaving the key blank.
        $provider = (string)($options['provider'] ?? '');
        $apiKey   = (string)($options['api_key'] ?? '');
        $model    = (string)($options['model'] ?? '');

        if ($provider === '' || $apiKey === '') {
            $provider = $provider !== ''
                ? $provider
                : ai_insights_resolve_config('PROVIDER', 'CHATBOT_PROVIDER', 'gemini');
            $apiKey = $apiKey !== ''
                ? $apiKey
                : ai_insights_resolve_config('KEY', 'CHATBOT_API_KEY', '');
            $model = $model !== ''
                ? $model
                : ai_insights_resolve_config('MODEL', 'CHATBOT_MODEL', 'gemini-2.0-flash');
        }

        $provider = strtolower(trim($provider));
        $apiKey   = trim($apiKey);

        if ($apiKey !== '' && in_array($provider, ['gemini', 'openai'], true)) {
            try {
                return ai_insights_call_provider(
                    $provider,
                    $apiKey,
                    $model,
                    $systemMessage,
                    $summaryText,
                    $maxTokens,
                    $temperature
                );
            } catch (\Throwable $e) {
                error_log('[ai_insights:' . $featureTag . '] provider error: ' . $e->getMessage());
            }
        }

        if (is_callable($fallback)) {
            $result = $fallback();
            if (is_array($result) && isset($result['insights']) && is_array($result['insights'])) {
                return $result;
            }
        }

        return ['source' => 'rule_based', 'insights' => []];
    }
}

if (!function_exists('ai_insights_resolve_config')) {
    /**
     * Look up a config constant with a feature-specific prefix first, then
     * a shared prefix, then a hard-coded default.
     *
     *   $featureSuffix = 'PROVIDER' → checks NUTRITIONIST_AI_PROVIDER, then
     *   CHATBOT_PROVIDER, then $default.
     */
    function ai_insights_resolve_config(string $featureSuffix, string $sharedSuffix, string $default): string
    {
        $featureConst = 'NUTRITIONIST_AI_' . $featureSuffix;
        if (defined($featureConst)) {
            $val = trim((string)constant($featureConst));
            if ($val !== '') {
                return $val;
            }
        }

        if (defined($sharedSuffix)) {
            $val = trim((string)constant($sharedSuffix));
            if ($val !== '') {
                return $val;
            }
        }

        return $default;
    }
}

if (!function_exists('ai_insights_call_provider')) {
    /**
     * Make the actual HTTP call to the configured provider and return the
     * parsed JSON response as ['source' => 'ai', 'insights' => [...]].
     */
    function ai_insights_call_provider(
        string $provider,
        string $apiKey,
        string $model,
        string $systemMessage,
        string $userPrompt,
        int $maxTokens,
        float $temperature
    ): array {
        if ($provider === 'gemini') {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
                . ($model ?: 'gemini-2.0-flash') . ':generateContent?key=' . $apiKey;
            $body = json_encode([
                'contents' => [['parts' => [['text' => trim($systemMessage . "\n\n" . $userPrompt)]]]],
                'generationConfig' => [
                    'temperature' => $temperature,
                    'maxOutputTokens' => $maxTokens,
                ],
            ]);
        } else {
            $url = 'https://api.openai.com/v1/chat/completions';
            $body = json_encode([
                'model' => $model ?: 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'system', 'content' => $systemMessage],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
            ]);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            throw new \RuntimeException("AI request failed: HTTP {$httpCode}");
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new \RuntimeException('AI response decode failed');
        }

        $text = '';
        if ($provider === 'gemini') {
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        } else {
            $text = $data['choices'][0]['message']['content'] ?? '';
        }

        $text = trim((string)$text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/i', '', $text);

        $parsed = json_decode($text, true);
        if (is_array($parsed) && isset($parsed['insights']) && is_array($parsed['insights'])) {
            return ['source' => 'ai', 'insights' => array_slice(array_values($parsed['insights']), 0, 6)];
        }

        throw new \RuntimeException('AI response format invalid');
    }
}