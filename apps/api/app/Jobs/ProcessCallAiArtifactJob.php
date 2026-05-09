<?php

namespace App\Jobs;

use App\Models\CallAiArtifact;
use App\Models\CallSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class ProcessCallAiArtifactJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly string $artifactId)
    {
    }

    public function handle(): void
    {
        $artifact = CallAiArtifact::query()->find($this->artifactId);
        if (! $artifact) {
            return;
        }

        $call = CallSession::query()
            ->where('tenant_id', $artifact->tenant_id)
            ->where('id', $artifact->call_session_id)
            ->first();
        if (! $call) {
            $artifact->status = 'failed';
            $artifact->metadata = ['error' => 'call_not_found'];
            $artifact->processed_at = now();
            $artifact->save();
            return;
        }

        $recordingUrl = (string) ($call->recording_url ?? '');
        if ($recordingUrl === '') {
            $artifact->status = 'failed';
            $artifact->metadata = ['error' => 'recording_url_missing'];
            $artifact->processed_at = now();
            $artifact->save();
            return;
        }

        $bytes = null;
        try {
            $response = Http::timeout(30)->get($recordingUrl);
            if ($response->successful()) {
                $bytes = (string) $response->body();
            }
        } catch (\Throwable) {
            $bytes = null;
        }

        $artifact->provider_mode = 'mock';
        $artifact->status = 'completed';
        $artifact->transcript = $bytes ? 'Transcript generated (mock).' : 'Transcript generated (mock, no audio fetched).';
        $artifact->summary = 'Summary generated (mock).';
        $artifact->qa_score = 80;
        $artifact->metadata = array_merge((array) ($artifact->metadata ?? []), [
            'recording_url' => $recordingUrl,
            'audio_bytes' => $bytes ? strlen($bytes) : 0,
        ]);
        $artifact->processed_at = now();
        $artifact->save();
    }
}

