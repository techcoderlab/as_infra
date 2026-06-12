<?php

namespace App\Jobs;

use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class SendToN8NJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Lead $lead) {}

    public function handle(): void
    {
        $form = $this->lead->form;

        if (! $form || ! $form->n8n_webhook_url) {
            return;
        }

        Http::post($form->n8n_webhook_url, [
            'lead_id' => $this->lead->id,
            'form_id' => $this->lead->form_id,
            'payload' => $this->lead->payload,
            'tenant_id' => $this->lead->tenant_id,
            'created_at' => $this->lead->created_at,
        ]);
    }
}
