<?php

namespace App\Http\Controllers;

use App\Models\RankwayDomain;
use App\Services\Rankway\RankwayDomainAnalyzer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class WebsiteRankCheckerController extends Controller
{
    public function show(Request $request): Response
    {
        $domainId = (int) $request->query('id', 0);
        $result = null;
        $unlocked = (bool) $request->user();

        if ($domainId > 0) {
            $record = RankwayDomain::query()->with('latestMetric')->find($domainId);
            if ($record) {
                $result = $record->toPublicArray($unlocked);
            }
        }

        return Inertia::render('Marketing/WebsiteRankChecker', $this->pageProps([
            'title' => 'Free Website Rank Checker — Rankway Score',
            'description' => 'Check your website’s Rankway Score and estimated rank among Rankway-indexed sites. Free SEO visibility snapshot — then improve with RankwayAI.',
            'path' => '/website-rank-checker',
        ], [
            'result' => $result,
            'unlocked' => $unlocked,
            'query_domain' => (string) $request->query('domain', ''),
        ]));
    }

    public function check(Request $request, RankwayDomainAnalyzer $analyzer): RedirectResponse
    {
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:255'],
            'force' => ['sometimes', 'boolean'],
        ]);

        try {
            $force = (bool) ($data['force'] ?? false);
            $record = $analyzer->analyze($data['domain'], $force);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not analyze this website right now. Try again shortly.');
        }

        return redirect()->route('website-rank-checker', [
            'id' => $record->id,
            'domain' => $record->domain,
        ])->with(
            $record->status === 'failed' ? 'error' : 'success',
            $record->status === 'failed'
                ? ($record->last_error ?: 'Analysis failed')
                : 'Rankway Score ready for '.$record->domain
        );
    }

    public function status(Request $request, RankwayDomain $domain): Response|\Illuminate\Http\JsonResponse
    {
        $domain->load('latestMetric');
        $unlocked = (bool) $request->user();
        $payload = $domain->toPublicArray($unlocked);

        if ($request->wantsJson()) {
            return response()->json($payload);
        }

        return Inertia::render('Marketing/WebsiteRankChecker', $this->pageProps([
            'title' => 'Free Website Rank Checker — Rankway Score',
            'description' => 'Check your website’s Rankway Score and estimated rank among Rankway-indexed sites.',
            'path' => '/website-rank-checker',
        ], [
            'result' => $payload,
            'unlocked' => $unlocked,
            'query_domain' => $domain->domain,
        ]));
    }

    /**
     * @param  array{title:string, description:string, path:string}  $seo
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function pageProps(array $seo, array $extra = []): array
    {
        $marketing = config('seo.marketing');
        $publicBase = rtrim((string) ($marketing['public_url'] ?? config('app.url')), '/');
        $og = $marketing['og_image'] ?? null;

        return array_merge([
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('register'),
            'seo' => [
                'title' => $seo['title'],
                'description' => $seo['description'],
                'keywords' => $marketing['keywords'],
                'canonical' => $publicBase.$seo['path'],
                'image' => filled($og)
                    ? (str_starts_with($og, 'http') ? $og : $publicBase.$og)
                    : null,
            ],
        ], $extra);
    }
}
