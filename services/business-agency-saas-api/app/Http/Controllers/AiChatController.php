<?php

namespace App\Http\Controllers;

use App\Models\AiAgent;
use App\Models\AiChat;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Ai\AiGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class AiChatController extends Controller
{
    public function __construct(protected AiGateway $aiGateway)
    {
    }

    public function index()
    {
        $this->authorize('viewAny', AiChat::class);

        // Trait automatically filters by tenant_id

        // Cache::forget('ai_agents');
        $agents = Cache::remember('ai_agents', 30, function () {
            return AiAgent::select('id', 'slug', 'is_active')->latest()->get();
        });

        return response()->json([
            'chats' => AiChat::select('id', 'tenant_id', 'name', 'ai_agent_id', 'avatar_url', 'webhook_url', 'webhook_secret', 'welcome_message')->latest()->get(),
            'agents' => $agents,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', AiChat::class);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'webhook_url' => 'nullable|url',
            'webhook_secret' => 'nullable|string',
            'welcome_message' => 'nullable|string',
            'ai_agent_id' => 'nullable|exists:ai_agents,id',
        ]);

        $chat = AiChat::create($validated);

        return response()->json($chat, 201);
    }

    public function update(Request $request, AiChat $aiChat)
    {
        // Policy authorization check recommended here (e.g., $this->authorize('update', $aiChat))
        $this->authorize('update', $aiChat);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'webhook_url' => 'nullable|url',
            'webhook_secret' => 'nullable|string',
            'welcome_message' => 'nullable|string',
            'ai_agent_id' => 'nullable|exists:ai_agents,id',
        ]);

        $aiChat->update($validated);

        return response()->json($aiChat);
    }

    public function destroy(AiChat $aiChat)
    {
        $this->authorize('delete', $aiChat);

        $aiChat->delete();

        return response()->json(['message' => 'Deleted successfully']);
    }

    /**
     * Get Chat History with Pagination (Cursor-based)
     */
    public function history(Request $request, AiChat $aiChat)
    {

        $this->authorize('view', $aiChat);

        $limit = 25; // Strict limit
        $beforeId = $request->input('before_id'); // The cursor

        // 1. Build Query (Latest messages first)
        $query = ChatMessage::where('ai_chat_id', $aiChat->id)
            ->where('user_id', Auth::id())
            // ->orderBy('id', 'desc');
            ->orderByRaw('id DESC NULLS LAST'); // for postgres

        // 2. Apply Cursor (Load messages OLDER than the top one)
        if ($beforeId) {
            $query->where('id', '<', $beforeId);
        }

        // 3. Fetch Data
        $messages = $query->take($limit)->get();

        // 4. Check if more exist (for infinite scroll)
        $lastMsg = $messages->last();
        $hasMore = $lastMsg ? ChatMessage::where('ai_chat_id', $aiChat->id)
            ->where('user_id', Auth::id())
            ->where('id', '<', $lastMsg->id)
            ->exists() : false;

        // 5. Transform for Deep Chat (Reverse to chronological: Old -> New)
        $formatted = $messages->reverse()->values()->map(function ($msg) {
            $m = ['role' => $msg->role, 'text' => $msg->content];
            if ($msg->files) {
                $m['files'] = $msg->files;
            }

            return $m;
        });

        return response()->json([
            'messages' => $formatted,
            'has_more' => $hasMore,
            'next_cursor' => $lastMsg ? $lastMsg->id : null,
        ]);
    }

    /**
     * Check Webhook Status
     */
    public function checkConnection(AiChat $aiChat)
    {
        $this->authorize('view', $aiChat);

        if (!$aiChat->webhook_url) {
            return response()->json([
                'status' => 'inactive',
                'message' => 'No webhook URL configured',
                'latency_ms' => null,
            ]);
        }

        try {
            $http = Http::timeout(3);

            if ($aiChat->webhook_secret) {
                $http->withHeaders(['Authorization' => $aiChat->webhook_secret]);
            }

            $start = microtime(true);

            // Universal non-triggering health check
            $response = $http->send('OPTIONS', $aiChat->webhook_url);

            $latency = round((microtime(true) - $start) * 1000, 2);

            $statusCode = $response->status();

            $isActive = in_array($statusCode, [200, 204]);

            return response()->json([
                'status' => $isActive ? 'active' : 'inactive',
                'code' => $statusCode,
                'latency_ms' => $latency,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'inactive',
                'message' => 'Unreachable',
                'latency_ms' => null,
            ]);
        }
    }

    // 5. PROXY (The Magic Part)
    // Front-end sends message here -> We forward to n8n -> Return n8n response
    public function chat(Request $request, AiChat $aiChat)
    {
        // --- DEBUG START: Log Incoming Data ---
        Log::info('--- AI Chat Request Start ---');
        Log::info('Inputs:', $request->except('files'));
        // --- DEBUG END ---

        $user = Auth::user();
        $url = $aiChat->webhook_url;

        $sessionId = "user_{$user->id}_agent_{$aiChat->id}";

        // 1. ROBUST TEXT EXTRACTION
        // Priority A: Check the 'text_content' field we added in the interceptor
        $userText = $request->input('text_content');

        // Priority B: Fallback to parsing 'messages' (if text-only JSON request)
        if (!$userText) {
            $rawMessages = $request->input('messages');
            // If it's a string (multipart), decode it
            if (is_string($rawMessages)) {
                $rawMessages = json_decode($rawMessages, true);
            }
            // If it's already an array (json request), use it
            $incoming = is_array($rawMessages) ? ($rawMessages[0] ?? []) : [];
            $userText = $incoming['text'] ?? '';
        }

        Log::info('Final Extracted Text:', ['text' => $userText]);

        // 2. Handle File Storage
        $n8nFiles = [];
        $dbFiles = [];

        if ($request->hasFile('files')) {
            // Force array to handle single/multiple files safely
            $uploadedFiles = $request->file('files');
            if (!is_array($uploadedFiles)) {
                $uploadedFiles = [$uploadedFiles];
            }

            foreach ($uploadedFiles as $file) {
                try {
                    $tenantId = $user->current_tenant_id ?? 'default';
                    $filename = \Illuminate\Support\Str::random(20) . '.' . $file->getClientOriginalExtension();
                    $folder = "tenants/{$tenantId}/chat_uploads/" . date('Y');

                    $path = $file->storeAs($folder, $filename, 'public');

                    if ($path) {
                        $fullUrl = asset('storage/' . $path);
                        $mime = $file->getMimeType();

                        $type = 'file';
                        if (str_starts_with($mime, 'image')) {
                            $type = 'image';
                        }
                        if (str_starts_with($mime, 'audio')) {
                            $type = 'audio';
                        }

                        $dbFiles[] = ['type' => $type, 'name' => $file->getClientOriginalName(), 'src' => $fullUrl];
                        $n8nFiles[] = ['filename' => $file->getClientOriginalName(), 'url' => $fullUrl, 'mimeType' => $mime];
                    }
                } catch (\Exception $e) {
                    Log::error('File Upload Error: ' . $e->getMessage());
                }
            }
        }

        // 3. Save USER Message
        ChatMessage::create([
            'ai_chat_id' => $aiChat->id,
            'user_id' => $user->id,
            'role' => 'user',
            'content' => $userText,
            'files' => count($dbFiles) > 0 ? $dbFiles : null,
        ]);

        // 4. Send Payload to n8n
        $payload = [
            'sessionId' => $sessionId,
            'text' => $userText, // This should now be populated
            'files' => $n8nFiles,
        ];

        $http = Http::timeout(120);
        if ($aiChat->webhook_secret) {
            $http->withHeaders(['Authorization' => $aiChat->webhook_secret]);
        }

        try {
            $response = $http->post($url, $payload);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Connection Failed: ' . $e->getMessage()], 200);
        }

        if ($response->failed()) {
            return response()->json(['error' => 'AI Error: ' . $response->body()], 200);
        }

        // 5. Save AI Response
        $responseData = $response->json();

        $aiText = '';
        if (isset($responseData['output'])) {
            $aiText = $responseData['output'];
        } elseif (isset($responseData['text'])) {
            $aiText = $responseData['text'];
        } elseif (isset($responseData['message'])) {
            $aiText = $responseData['message'];
        } elseif (isset($responseData['data']) && is_string($responseData['data'])) {
            $aiText = $responseData['data'];
        } else {
            $aiText = json_encode($responseData);
        }

        ChatMessage::create([
            'ai_chat_id' => $aiChat->id,
            'user_id' => $user->id,
            'role' => 'ai',
            'content' => $aiText,
        ]);

        return response()->json($responseData);
    }

    public function chatStream(Request $request, User $user, AiChat $aiChat)
    {

        // if (! $request->hasValidSignature()) {
        //     abort(403, 'Invalid or expired signature');
        // }

        // 1. configuration: Disable compression and internal buffering
        if (function_exists('apache_setenv')) {
            apache_setenv('no-gzip', '1');
        }
        ini_set('zlib.output_compression', 'Off');
        ini_set('output_buffering', 'Off');
        ob_implicit_flush(true);

        // Clear buffer
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        return response()->stream(function () use ($aiChat, $user) {
            // Log::info("DEBUG: Stream started for Chat ID: " . $aiChat->id);

            // OPTIMIZATION: Dynamic Agent Resolution
            // 1. Try to get the specific agent assigned to this chat room
            $agent = $aiChat->agent;

            // 2. Fallback: If no specific agent attached, find a default active one for this tenant
            if (!$agent) {
                $agent = AiAgent::where('tenant_id', $aiChat->tenant_id)
                    ->where('is_active', true)
                    ->first();
            }

            if (!$agent) {
                echo 'data: ' . json_encode(['type' => 'error', 'data' => 'No active AI Agent configuration found.']) . "\n\n";
                echo 'data: ' . json_encode(['type' => 'done']) . "\n\n";

                return;
            }

            // Get History
            $lastUserMessage = ChatMessage::where('ai_chat_id', $aiChat->id)
                ->where('role', 'user')
                ->latest('id')
                ->first();

            if (!$lastUserMessage) {
                echo 'data: ' . json_encode(['type' => 'done']) . "\n\n";

                return;
            }

            $history = ChatMessage::where('ai_chat_id', $aiChat->id)
                ->where('id', '<', $lastUserMessage->id)
                ->orderByDesc('id')
                ->limit($agent->context_window_size ?? 10) // Optimization: Increased context window slightly
                ->get()
                ->reverse()
                ->map(fn($m) => [
                    'role' => $m->role,
                    'content' => $m->content,
                ])
                ->values()
                ->toArray();

            // Send connection signal
            echo 'data: ' . json_encode(['type' => 'connected']) . "\n\n";

            // Execute Stream via Gateway
            try {
                $response = $this->aiGateway->streamChat(
                    $agent,
                    [
                        'user_id' => $user->id,
                        'user_name' => $user->name,
                        'tenant_id' => $aiChat->tenant_id,
                        'chat_id' => $aiChat->id, // Pass ID for tool context
                        'current_date_time' => now()->toDateTimeString(),
                    ],
                    $history,
                    $lastUserMessage->content
                );

                $body = $response->toPsrResponse()->getBody();
                $buffer = '';
                $fullAiText = '';

                while (!$body->eof()) {
                    $chunk = $body->read(1024);
                    $buffer .= $chunk;

                    while (($pos = strpos($buffer, "\n\n")) !== false) {
                        $line = substr($buffer, 0, $pos);
                        $buffer = substr($buffer, $pos + 2);
                        $cleanLine = trim($line);

                        if (str_starts_with($cleanLine, 'data: ')) {
                            $jsonStr = substr($cleanLine, 6);

                            // Pass through to frontend immediately
                            echo 'data: ' . $jsonStr . "\n\n";

                            // Capture text for DB storage
                            $data = json_decode($jsonStr, true);
                            if ($data && isset($data['type']) && $data['type'] === 'token') {
                                $fullAiText .= $data['data'];
                            }
                        }
                    }
                }

                // Save AI Response to DB
                if ($fullAiText !== '') {
                    ChatMessage::create([
                        'ai_chat_id' => $aiChat->id,
                        'user_id' => $user->id, // AI speaks on behalf of the system, but we track the user session
                        'role' => 'ai',
                        'content' => $fullAiText,
                    ]);
                }

                echo 'data: ' . json_encode(['type' => 'done']) . "\n\n";
            } catch (\Exception $e) {
                Log::error('Stream Error: ' . $e->getMessage());
                echo 'data: ' . json_encode(['type' => 'error', 'data' => 'I encountered an error processing your request.']) . "\n\n";
                echo 'data: ' . json_encode(['type' => 'done']) . "\n\n";
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
            // 'ngrok-skip-browser-warning' => 'true',
            // 'Access-Control-Allow-Origin' => 'http://127.0.0.1:5173',
            // 'Access-Control-Allow-Headers' => 'http://127.0.0.1:5173',

        ]);
    }

    public function storeMessage(Request $request, AiChat $aiChat)
    {
        $user = $request->user() ?? Auth::user();

        $request->validate([
            'text_content' => 'required|string',
        ]);

        ChatMessage::create([
            'ai_chat_id' => $aiChat->id,
            'user_id' => $user->id,
            'role' => 'user',
            'content' => $request->text_content,
        ]);

        return response()->json([
            'stream_url' => URL::temporarySignedRoute(
                'ai.chat.stream',
                now()->addMinutes(5),
                [
                    'user' => $user->id,
                    'aiChat' => $aiChat->id,
                ]
            ),
        ]);
    }

    /* Before optimizing ai_chats, ai_agents, and agent_triggers db tables */
    // public function chatStream(Request $request, User $user, AiChat $aiChat)
    // {

    //     // LOG HERE FIRST
    //     Log::info("DEBUG: Method entered for Chat ID: " . $aiChat->id);

    //     // if (!$user) {
    //     //     abort(401, 'User must be logged in to chat.');
    //     // }

    //     $agent = AiAgent::where('tenant_id', $aiChat->tenant_id)
    //         ->where('slug', 'agent-chit-chat')
    //         // ->where('slug', 'lead-qualifier-agent')
    //         ->where('is_active', true)
    //         ->first();

    //     if (!$agent) {
    //         abort(404, 'No active AI Agent found.');
    //     }

    //     $lastUserMessage = ChatMessage::where('ai_chat_id', $aiChat->id)
    //         ->where('role', 'user')
    //         ->latest('id')
    //         ->first();

    //     if (!$lastUserMessage) {
    //         abort(400, 'No user message found to respond to.');
    //     }

    //     $history = ChatMessage::where('ai_chat_id', $aiChat->id)
    //         ->where('id', '<', $lastUserMessage->id)
    //         ->orderByDesc('id')
    //         ->limit(5)
    //         ->get()
    //         ->reverse()
    //         ->map(fn($m) => [
    //             'role' => $m->role,
    //             'content' => $m->content,
    //         ])
    //         ->values()
    //         ->toArray();

    //     Log::info("DEBUG: Logic passed, entering stream block");

    //     return response()->stream(function () use ($agent, $history, $lastUserMessage, $aiChat, $user) {

    //         // 1. configuration: Disable compression and internal buffering
    //         if (function_exists('apache_setenv')) {
    //             apache_setenv('no-gzip', '1');
    //         }
    //         ini_set('zlib.output_compression', 'Off');
    //         ini_set('output_buffering', 'Off');

    //         // 2. Clear any existing buffers (Loop until fully clear)
    //         while (ob_get_level() > 0) {
    //             ob_end_flush();
    //         }

    //         // 3. Enable Implicit Flush
    //         // This tells PHP to automatically flush the system buffer after every output block.
    //         // You do NOT need to call flush() manually after this.
    //         ob_implicit_flush(true);

    //         // 1. Send 2kb of whitespace padding to force Nginx to flush the buffer
    //         echo ":" . str_repeat(" ", 2048) . "\n";

    //         // Initial connection signal
    //         echo "data: " . json_encode(['type' => 'connected']) . "\n\n";

    //         $response = $this->aiGateway->streamChat(
    //             $agent,
    //             [
    //                 'user_id' => $user->id,
    //                 'user_name' => $user->name,
    //                 'tenant_id' => $aiChat->tenant_id,
    //                 'time' => now()->toDateTimeString(),
    //             ],
    //             $history,
    //             $lastUserMessage->content
    //         );

    //         $body = $response->toPsrResponse()->getBody();
    //         $buffer = '';
    //         $fullAiText = '';

    //         // Log::info('AI Chat Stream: ' . $body->getContents());

    //         while (!$body->eof()) {

    //             // Read stream
    //             $chunk = $body->read(1024); // Increased to 1024 for better efficiency
    //             $buffer .= $chunk;

    //             // Process buffer for complete events
    //             while (($pos = strpos($buffer, "\n\n")) !== false) {
    //                 $line = substr($buffer, 0, $pos);
    //                 $buffer = substr($buffer, $pos + 2);
    //                 $cleanLine = trim($line);
    //                 Log::info("Clean line from gemini: " . $cleanLine);
    //                 if (str_starts_with($cleanLine, 'data: ')) {
    //                     $jsonStr = substr($cleanLine, 6);
    //                     $data = json_decode($jsonStr, true);
    //                     Log::info("Decoded data from gemini:", $data);

    //                     if (!$data) continue;

    //                     // Accumulate AI text
    //                     if (isset($data['type']) && $data['type'] === 'token') {
    //                         $fullAiText .= $data['data'];
    //                     }

    //                     // FIX: Just Echo. Implicit flush handles the rest.
    //                     echo "data: " . $jsonStr . "\n\n";
    //                 }
    //             }
    //         }

    //         // End of stream
    //         echo "data: " . json_encode(['type' => 'done']) . "\n\n";

    //         // Persist AI message
    //         if ($fullAiText !== '') {
    //             ChatMessage::create([
    //                 'ai_chat_id' => $aiChat->id,
    //                 'user_id' => $user->id,
    //                 'role' => 'ai',
    //                 'content' => $fullAiText,
    //             ]);
    //         }
    //     }, 200, [
    //         'Content-Type' => 'text/event-stream',
    //         'Cache-Control' => 'no-cache',
    //         'Connection' => 'keep-alive',
    //         // Nginx specific: Critical for streaming through Nginx
    //         'X-Accel-Buffering' => 'no',
    //     ]);
    // }

    // public function chatStream(Request $request, User $user, AiChat $aiChat)
    // {
    //     return response()->stream(function () use ($aiChat, $user, $request) {

    //         $responseText = '';

    //         foreach ($this->fakeAiStream() as $token) {
    //             $responseText .= $token;

    //             echo "data: " . json_encode([
    //                 'type' => 'token',
    //                 'data' => $token,
    //             ]) . "\n\n";

    //             // ob_flush();
    //             // flush();
    //             usleep(40000);
    //         }

    //         ChatMessage::create([
    //             'ai_chat_id' => $aiChat->id,
    //             'user_id' => $user->id,
    //             'role' => 'ai',
    //             'content' => $responseText,
    //         ]);

    //         echo "data: " . json_encode([
    //             'type' => 'done',
    //         ]) . "\n\n";

    //         // ob_flush();
    //         // flush();
    //     }, 200, [
    //         'Content-Type' => 'text/event-stream',
    //         'Cache-Control' => 'no-cache',
    //         'X-Accel-Buffering' => 'no',
    //     ]);
    // }

    // private function fakeAiStream()
    // {
    //     return explode(' ', 'This is a correct streaming AI response generated token by token.');
    // }

    /**
     * The Streaming Chat Endpoint
     */
    // public function chatStream(Request $request, User $user, AiChat $aiChat)
    // {
    //     // $user = request()->user();

    //     if (!$user) {
    //         abort(401, 'User must be logged in to chat.');
    //     }

    //     // 1. Resolve active agent
    //     $agent = AiAgent::where('tenant_id', $aiChat->tenant_id)
    //         ->where('is_active', true)
    //         ->first();

    //     if (! $agent) {
    //         abort(404, 'No active AI Agent found.');
    //     }

    //     // 2. Load latest user message (already saved via POST)
    //     $lastUserMessage = ChatMessage::where('ai_chat_id', $aiChat->id)
    //         ->where('role', 'user')
    //         ->latest('id')
    //         ->first();

    //     if (! $lastUserMessage) {
    //         abort(400, 'No user message found to respond to.');
    //     }

    //     // 3. Load recent history (excluding current user message)
    //     $history = ChatMessage::where('ai_chat_id', $aiChat->id)
    //         ->where('id', '<', $lastUserMessage->id)
    //         ->orderByDesc('id')
    //         ->limit(10)
    //         ->get()
    //         ->reverse()
    //         ->map(fn($m) => [
    //             'role' => $m->role,
    //             'content' => $m->content,
    //         ])
    //         ->values()
    //         ->toArray();

    //     // 4. Start SSE stream
    //     return response()->stream(function () use ($agent, $history, $lastUserMessage, $aiChat, $user) {

    //         // Clean output buffers (CRITICAL for SSE)
    //         while (ob_get_level() > 0) {
    //             ob_end_clean();
    //         }

    //         @ini_set('output_buffering', 'off');
    //         @ini_set('zlib.output_compression', '0');

    //         // Optional handshake
    //         echo "data: " . json_encode(['type' => 'connected']) . "\n\n";
    //         flush();

    //         $context = [
    //             'user_id'   => $user->id,
    //             'user_name' => $user->name,
    //             'tenant_id' => $aiChat->tenant_id,
    //             'time'      => now()->toDateTimeString(),
    //         ];

    //         // Call AI gateway
    //         $response = $this->aiGateway->streamChat(
    //             $agent,
    //             $context,
    //             $history,
    //             $lastUserMessage->content
    //         );

    //         $body = $response->toPsrResponse()->getBody();
    //         $fullText = '';

    //         while (! $body->eof()) {
    //             $chunk = trim($body->read(1024));
    //             if ($chunk === '') {
    //                 continue;
    //             }

    //             $chunk = mb_convert_encoding($chunk, 'UTF-8', 'UTF-8');

    //             echo "data: " . json_encode([
    //                 'type' => 'token',
    //                 'data' => $chunk,
    //             ], JSON_UNESCAPED_UNICODE) . "\n\n";

    //             flush();

    //             $fullText .= $chunk;
    //         }

    //         // End-of-stream marker (REQUIRED)
    //         echo "data: " . json_encode(['type' => 'done']) . "\n\n";
    //         flush();

    //         // Persist AI message
    //         if ($fullText !== '') {
    //             ChatMessage::create([
    //                 'ai_chat_id' => $aiChat->id,
    //                 'user_id'    => $user->id, // AI/system
    //                 'role'       => 'ai',
    //                 'content'    => $fullText,
    //             ]);
    //         }
    //     }, 200, [
    //         'Content-Type'  => 'text/event-stream; charset=utf-8',
    //         'Cache-Control' => 'no-cache',
    //         'Connection'    => 'keep-alive',
    //         'X-Accel-Buffering' => 'no',
    //         'Access-Control-Allow-Origin' => request()->header('Origin'),
    //         'Access-Control-Allow-Credentials' => 'true',
    //     ]);
    // }
    // public function chatStream(Request $request, User $user, AiChat $aiChat)
    // {
    //     abort_if(!$user, 401);

    //     $agent = AiAgent::where('tenant_id', $aiChat->tenant_id)
    //         ->where('is_active', true)
    //         ->firstOrFail();

    //     $lastUserMessage = ChatMessage::where('ai_chat_id', $aiChat->id)
    //         ->where('role', 'user')
    //         ->latest()
    //         ->firstOrFail();

    //     return response()->stream(function () use ($agent, $aiChat, $user, $lastUserMessage) {

    //         if (ob_get_level()) ob_end_clean();

    //         header('Content-Type: text/event-stream');
    //         header('Cache-Control: no-cache');
    //         header('X-Accel-Buffering: no');

    //         $response = $this->aiGateway->streamChat(
    //             $agent,
    //             [
    //                 'user_id'   => $user->id,
    //                 'tenant_id' => $aiChat->tenant_id,
    //             ],
    //             [], // history already embedded
    //             $lastUserMessage->content
    //         );

    //         $body = $response->toPsrResponse()->getBody();
    //         $buffer = '';
    //         $fullText = '';

    //         while (!$body->eof()) {
    //             $buffer .= $body->read(256);

    //             while (($pos = strpos($buffer, "\n\n")) !== false) {
    //                 $chunk = trim(substr($buffer, 0, $pos));
    //                 $buffer = substr($buffer, $pos + 2);

    //                 if (!str_starts_with($chunk, 'data:')) continue;

    //                 $json = json_decode(substr($chunk, 5), true);
    //                 if (!$json || $json['type'] !== 'token') continue;

    //                 $token = $json['data'];
    //                 $fullText .= $token;

    //                 echo "data: " . json_encode([
    //                     'text' => $token
    //                 ]) . "\n\n";

    //                 flush();
    //             }
    //         }

    //         // Final marker — REQUIRED
    //         echo "data: [DONE]\n\n";
    //         flush();

    //         ChatMessage::create([
    //             'ai_chat_id' => $aiChat->id,
    //             'user_id'    => $user->id,
    //             'role'       => 'ai',
    //             'content'    => $fullText,
    //         ]);
    //     });
    // }

}
