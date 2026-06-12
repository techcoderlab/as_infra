<?php

namespace App\Contracts\Messaging;

interface MessagingProviderInterface
{
    /**
     * Send a generic message payload.
     *
     * @param  string  $to  The recipient identifier (Phone string, Email address, Slack channel ID, etc.)
     * @param  string|array  $content  The content of the message. Can be a string for simple text, or an array for complex payloads (templates, embeds, attachments).
     * @param  array  $options  Any platform-specific options (e.g., 'type' => 'template', 'language' => 'en_US', 'parse_mode' => 'HTML').
     * @return mixed
     */
    public function sendMessage(string $to, $content, array $options = []);
}
