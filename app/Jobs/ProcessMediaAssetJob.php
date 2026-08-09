<?php

namespace App\Jobs;

use App\Models\MediaAsset;
use App\Services\Media\ImageVariantService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessMediaAssetJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $mediaAssetId) {}

    public function handle(ImageVariantService $variants): void
    {
        $asset = MediaAsset::query()->find($this->mediaAssetId);

        if (! $asset) {
            return;
        }

        $asset->update(['status' => 'processing']);

        try {
            $generated = $variants->generate($asset);
            $asset->update([
                'variants' => $generated,
                'status' => 'ready',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Media variant generation failed', [
                'media_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
            $asset->update(['status' => 'failed']);
        }
    }
}
