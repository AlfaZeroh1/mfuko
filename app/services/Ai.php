<?php
declare(strict_types=1);

/** Optional LLM narration of insights. Provider: anthropic | openai (Settings → AI). */
final class Ai
{
    public static function enabled(): bool
    {
        return in_array(Settings::get('ai_provider'), ['anthropic', 'openai'], true) && Settings::get('ai_api_key') !== '';
    }

    public static function ask(string $question, array $context): string
    {
        if (!self::enabled()) throw new ApiError('AI assistant is not configured. Add an API key in Settings → AI.');
        $system = 'You are Mfuko, a sharp, friendly retail business advisor for small and medium shops in Africa (mostly Kenya). '
            . 'Use ONLY the JSON business data provided. Be specific with numbers and product names, use the business currency, '
            . 'and give practical actions the owner can take this week. Keep it under 220 words, use short bullet points, no markdown headings.';
        $user = "Business data (JSON):\n" . json_encode($context, JSON_UNESCAPED_UNICODE) . "\n\nQuestion: " . $question;
        $provider = Settings::get('ai_provider');
        $key = Settings::get('ai_api_key');
        if ($provider === 'anthropic') {
            $model = Settings::get('ai_model') ?: 'claude-sonnet-5-5';
            $r = self::post('https://api.anthropic.com/v1/messages', [
                'x-api-key: ' . $key, 'anthropic-version: 2023-06-01',
            ], ['model' => $model, 'max_tokens' => 900, 'system' => $system, 'messages' => [['role' => 'user', 'content' => $user]]]);
            $text = '';
            foreach ($r['content'] ?? [] as $block) if (($block['type'] ?? '') === 'text') $text .= $block['text'];
            if ($text === '') throw new ApiError('AI error: ' . ($r['error']['message'] ?? 'empty response'), 502);
            return trim($text);
        }
        $model = Settings::get('ai_model') ?: 'gpt-4o-mini';
        $r = self::post('https://api.openai.com/v1/chat/completions', ['Authorization: Bearer ' . $key],
            ['model' => $model, 'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]], 'max_tokens' => 900]);
        $text = $r['choices'][0]['message']['content'] ?? '';
        if ($text === '') throw new ApiError('AI error: ' . ($r['error']['message'] ?? 'empty response'), 502);
        return trim($text);
    }

    private static function post(string $url, array $headers, array $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_POSTFIELDS => json_encode($body),
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new ApiError('AI service unreachable: ' . $err, 502);
        return json_decode((string)$raw, true) ?: [];
    }
}
