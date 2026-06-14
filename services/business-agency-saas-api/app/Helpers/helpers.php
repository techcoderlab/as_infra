<?php

use Illuminate\Support\Arr;

/**
 * Example:
 * $data = ['id' => 1, 'user' => ['name' => 'John', 'role' => 'admin']];
 * keys_except($data, 'user.role'); // Returns ['id' => 1, 'user' => ['name' => 'John']]
 */
if (!function_exists('keys_except')) {
    function keys_except($array, $keys): array
    {
        // 1. Force to array (handles nulls or objects safely)
        $result = is_array($array) ? $array : (is_iterable($array) ? iterator_to_array($array) : (array) $array);

        if (empty($keys)) {
            return $result;
        }

        $keys = (array) $keys;

        // Optimization: Use direct Arr::forget for simple keys (Fastest)
        // Use dot-regex logic ONLY if a wildcard is present
        foreach ($keys as $key) {
            if (!str_contains($key, '*')) {
                Arr::forget($result, $key);
            } else {
                $flattened = Arr::dot($result);
                $regex = '/^' . str_replace('\*', '[^.]+', preg_quote($key, '/')) . '(\.|$)/';
                // preg_grep is much faster than looping and preg_match-ing individually
                $matches = preg_grep($regex, array_keys($flattened));
                foreach ($matches as $match) {
                    unset($flattened[$match]);
                }
                $result = Arr::undot($flattened);
            }
        }

        // if (!$returnNew) $array = $result;
        return $result;
    }
}

/**
 * Example:
 * $data = ['users' => [['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']]];
 * keys_only($data, 'users.*.name'); // Returns only the names in the structure
 */
if (!function_exists('keys_only')) {
    function keys_only($array, $keys): array
    {
        if (empty($array)) {
            return [];
        }

        if (empty($keys)) {
            return $array;
        }

        $array = is_array($array) ? $array : (is_iterable($array) ? iterator_to_array($array) : (array) $array);

        $keys = (array) $keys;
        $flattened = Arr::dot($array);
        $filteredFlattened = [];

        // foreach ($keys as $key) {
        //     $regex = '/^' . str_replace('\*', '[^.]+', preg_quote($key, '/')) . '(\.|$)/';
        //     // preg_grep extracts all matching keys at once
        //     $matchedKeys = preg_grep($regex, array_keys($flattened));
        //     foreach ($matchedKeys as $match) {
        //         $filteredFlattened[$match] = $flattened[$match];
        //     }
        // }

        // Pro-tip: Combine all keys into one regex pattern
        $patterns = array_map(fn($k) => str_replace('\*', '[^.]+', preg_quote($k, '/')), $keys);
        $regex = '/^(' . implode('|', $patterns) . ')(\.|$)/';
        $matchedKeys = preg_grep($regex, array_keys($flattened));

        foreach ($matchedKeys as $match) {
            $cleanKey = str_replace('[]', '', $match);
            $filteredFlattened[$cleanKey] = $flattened[$match];
        }

        $result = Arr::undot($filteredFlattened);

        // if (!$returnNew) $array = $result;
        return $result;
    }
}

if (!function_exists('sanitize_payload')) {
    /**
     * Recursively truncate strings in a nested array.
     */
    function sanitize_payload(mixed $data, int $limit = 5000): mixed
    {
        // If it's an array, recurse into it
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = sanitize_payload($value, $limit);
            }

            return $data;
        }

        // If it's a string, truncate it safely
        if (is_string($data)) {
            return mb_strlen($data) > $limit
                ? mb_substr($data, 0, $limit) . '...'
                : $data;
        }

        return $data;

        // // Deep Sanitize: Ensure no single key in the payload is over 5k chars
        // $sanitizedPayload = collect($trimmedData)->map(function ($value) {
        //     return is_string($value) ? mb_substr($value, 0, 5000) : $value;
        // })->toArray();
    }

    /**
     * Helper: cleans Markdown code blocks from LLM responses (e.g. ```json ... ```)
     */
    function clean_and_decode_json(string $text): array
    {
        Log::error($text);
        if (empty(trim($text))) {
            return [
                'error' => true,
            ];
        }

        // 1. Strip Markdown Code Blocks
        // This removes ```json and the closing ``` if the AI ignores the "No Markdown" rule
        $cleaned = preg_replace('/^```(?:json)?\s+|\s*```$/i', '', trim($text));

        // 2. Locate the Actual JSON Object
        // We look for the first '{' and the last '}' to isolate the object
        $start = strpos($cleaned, '{');
        $end = strrpos($cleaned, '}');

        if ($start !== false && $end !== false) {
            $cleaned = substr($cleaned, $start, $end - $start + 1);
        }

        // 3. Attempt Decode
        $data = json_decode($cleaned, true);

        // 4. Validate Required Keys (Crucial for SaaS Stability)
        if (json_last_error() === JSON_ERROR_NONE) {
            return $data;
        }

        // Log::error("JSON Decode Failed. Raw Text: " . $text);

        // 5. Final Fallback (Always return the structure the rest of your app expects)
        return [
            'error' => true, // Optional: help your logic identify a failure state
        ];
    }
    // function clean_and_decode_json(string $text): array
    // {
    //     if (empty($text)) {
    //         return [];
    //     }

    //     // 1. Try to extract JSON from code blocks first
    //     if (preg_match('/```json\s*(\{.*\}|\[.*\])\s*```/is', $text, $matches)) {
    //         $text = $matches[1];
    //     }
    //     // 2. Try to find the *first* JSON start character ('{' or '[')
    //     elseif (preg_match('/[\[\{]/', $text, $matches, PREG_OFFSET_CAPTURE)) {
    //         $firstBracket = $matches[0][0];
    //         $startOffset = $matches[0][1];

    //         // Determine the expected closing bracket
    //         $lastBracket = ($firstBracket === '[') ? ']' : '}';

    //         // Find the *last* occurrence of the closing bracket
    //         $endOffset = strrpos($text, $lastBracket);

    //         if ($endOffset !== false && $endOffset > $startOffset) {
    //             $candidate = substr($text, $startOffset, $endOffset - $startOffset + 1);
    //             $decoded = json_decode($candidate, true);
    //             if (json_last_error() === JSON_ERROR_NONE) {
    //                 return $decoded;
    //             }
    //         }
    //     }

    //     // 3. Fallback: Try straight decode after basic cleanup
    //     $cleanText = preg_replace('/^```json\s*/i', '', $text);
    //     $cleanText = preg_replace('/^```\s*/', '', $cleanText);
    //     $cleanText = preg_replace('/\s*```$/', '', $cleanText);

    //     $data = json_decode($cleanText, true);

    //     if (json_last_error() !== JSON_ERROR_NONE) {
    //         // Do NOT log error, just treat as summary
    //         return ['summary' => $text];
    //     }

    //     return $data;
    // }
}

if (!function_exists('get_host')) {
    function get_host($url)
    {
        // Hack: Add a dummy protocol if missing so parse_url identifies the host correctly
        if (strpos($url, 'http') !== 0 && strpos($url, '//') !== 0) {
            $url = 'http://' . $url;
        }

        $host = parse_url($url, PHP_URL_HOST);

        // Optional: Standardize by removing 'www.' if you want 'www.site.com' to match 'site.com'
        return strtolower(preg_replace('/^www\./i', '', $host));
    }
}

if (!function_exists('is_valid_origin')) {
    function is_valid_origin(string $allowedUrl, string $requestOrigin): bool
    {
        if (empty($allowedUrl)) {
            return false;
        }

        $requestOrigin = get_host($requestOrigin);

        if (empty($allowedUrl) || empty($requestOrigin)) {
            return false;
        }

        return str_contains($allowedUrl, $requestOrigin);
    }
}

if (!function_exists('is_bot')) {
    function is_bot($honeypot_input, $ms_since_load): bool
    {
        $is_honeypot = !empty($honeypot_input);
        $is_timing = $ms_since_load < 2500;

        return $is_honeypot || $is_timing;
    }
}

if (!function_exists('is_valid_signature')) {
    /**
     * Validate the request signature using HMAC SHA256.
     *
     * @param  Request  $request
     */
    function is_valid_signature(string $secret, string $timestamp, string $signature, string $json_body): bool
    {
        if (empty($secret)) {
            return false;
        }

        if (!$timestamp || !$signature) {
            return false;
        }

        // Optional: specific timestamp validity check (replay attack prevention)
        if (abs(time() - $timestamp) > 60) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . $json_body, $secret);

        return hash_equals($expected, (string) $signature);
    }

    // function create_valid_signature(string $secret, string $timestamp, string $json_body): string
    // {
    //     if (empty($secret)) {
    //         return '';
    //     }

    //     if (empty($timestamp)) {
    //         $timestamp = time();
    //     }

    //     return hash_hmac('sha256', $timestamp.$json_body, $secret);
    // }

    // /**
    //  * Creates an asymmetric cryptographic signature using an Ed25519 Private Key.
    //  *
    //  * @param string $privateKeyHex The Ed25519 private key/seed (from Laravel .env)
    //  * @param string $timestamp     The current timestamp string
    //  * @param string $jsonBody      The raw request payload body
    //  * @return string               The hex-encoded signature
    //  * @throws \Exception
    //  */
    // function create_valid_signature(string $privateKeyHex, string $timestamp, string $jsonBody): string
    // {
    //     if (empty($privateKeyHex)) {
    //         return '';
    //     }

    //     if (empty($timestamp)) {
    //         $timestamp = (string) time();
    //     }

    //     // Convert the hex private key back to binary
    //     $privateKeyBinary = hex2bin($privateKeyHex);

    //     // FAST FIX: If Python gave us a 32-byte seed, expand it to the 64-byte secret key PHP needs
    //     if (strlen($privateKeyBinary) === SODIUM_CRYPTO_SIGN_SEEDBYTES) { // 32 bytes
    //         $keypair = sodium_crypto_sign_seed_keypair($privateKeyBinary);
    //         $privateKeyBinary = sodium_crypto_sign_secretkey($keypair);  // Expands to 64 bytes
    //     }
    //     // Sanity check
    //     elseif (strlen($privateKeyBinary) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    //         throw new \Exception("Invalid private key length. Must be exactly 32 or 64 bytes.");
    //     }

    //     // Construct the payload message exactly as Python expects it
    //     $message = $timestamp . $jsonBody;

    //     // Generate a detached cryptographic signature using the 64-byte key
    //     $signatureBinary = sodium_crypto_sign_detached($message, $privateKeyBinary);

    //     return bin2hex($signatureBinary);
    // }

    /**
     * Creates an HMAC SHA-256 cryptographic signature for API requests.
     *
     * @param string $secret    The unique developer/app secret (e.g., sk_live_...)
     * @param string $timestamp The current UNIX timestamp string
     * @param string $jsonBody  The raw request payload body
     * @return string           The hex-encoded signature
     */
    function create_valid_signature(string $secret, string $timestamp, string $jsonBody): string
    {
        if (empty($secret)) {
            return '';
        }

        if (empty($timestamp)) {
            $timestamp = (string) time();
        }

        // Construct the payload message exactly as FastAPI expects it: Timestamp + Body
        $message = $timestamp . $jsonBody;

        // Generate the HMAC SHA-256 signature using the secret
        return hash_hmac('sha256', $message, $secret);
    }
}
