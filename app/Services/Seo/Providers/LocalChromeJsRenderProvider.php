<?php

namespace App\Services\Seo\Providers;

use App\Services\Seo\Contracts\JsRenderProvider;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Free local headless Chrome/Chromium — no Browserless / paid API.
 */
class LocalChromeJsRenderProvider implements JsRenderProvider
{
    public function configured(): bool
    {
        return $this->binary() !== null;
    }

    public function name(): string
    {
        return 'local_chrome';
    }

    public function fetch(string $url): array
    {
        $binary = $this->binary();
        if (! $binary) {
            throw new RuntimeException('Local Chrome/Chromium not found. Install Chrome or set CHROME_BINARY.');
        }

        $started = microtime(true);
        set_time_limit(0);

        $budgetMs = (int) config('services.chrome.virtual_time_budget_ms', 8000);

        $result = Process::timeout(25)
            ->quietly()
            ->run([
                $binary,
                '--headless=new',
                '--disable-gpu',
                '--no-sandbox',
                '--disable-dev-shm-usage',
                '--hide-scrollbars',
                '--mute-audio',
                '--disable-extensions',
                '--log-level=3',
                '--virtual-time-budget='.$budgetMs,
                '--dump-dom',
                $url,
            ]);

        if (! $result->successful()) {
            throw new RuntimeException(
                'Local Chrome render failed: '.Str::limit($result->errorOutput() ?: $result->output(), 300)
            );
        }

        $html = (string) $result->output();
        if (trim($html) === '') {
            throw new RuntimeException('Local Chrome returned empty HTML for '.$url);
        }

        return [
            'html' => $html,
            'status' => 200,
            'load_time_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    public function binary(): ?string
    {
        $configured = trim((string) config('services.chrome.binary', ''));
        if ($configured !== '' && (is_executable($configured) || is_file($configured))) {
            return $configured;
        }

        foreach ($this->candidateBinaries() as $path) {
            if ($path !== '' && (is_executable($path) || is_file($path))) {
                return $path;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function candidateBinaries(): array
    {
        $which = [];
        foreach (['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser', 'chrome'] as $name) {
            $found = trim((string) Process::run(['bash', '-lc', 'command -v '.escapeshellarg($name)])->output());
            if ($found !== '') {
                $which[] = $found;
            }
        }

        return array_values(array_unique(array_filter([
            ...$which,
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            '/Applications/Chromium.app/Contents/MacOS/Chromium',
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/usr/local/bin/chromium',
            'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
        ])));
    }
}
