<?php

namespace App\Http\Controllers;

use App\Services\Billing\PlanCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class MarketingController extends Controller
{
    public function about(): Response
    {
        return Inertia::render('Marketing/About', $this->pageProps([
            'title' => 'About RankwayAI — Marketing OS for growth teams',
            'description' => 'RankwayAI helps Indian businesses and agencies run SEO, Search Console, social, WhatsApp, and CRM in one workspace.',
            'path' => '/about',
        ], [
            'plans' => PlanCatalog::plansForMarket(PlanCatalog::MARKET_IN, PlanCatalog::INTERVAL_MONTH),
        ]));
    }

    public function contact(): Response
    {
        return Inertia::render('Marketing/Contact', $this->pageProps([
            'title' => 'Contact RankwayAI',
            'description' => 'Talk to the RankwayAI team about SEO, onboarding, agency plans, or a product demo.',
            'path' => '/contact',
        ], [
            'contact_email' => config('seo.marketing.contact_email', 'info@vibgyorsolution.com'),
            'plans' => PlanCatalog::plansForMarket(PlanCatalog::MARKET_IN, PlanCatalog::INTERVAL_MONTH),
        ]));
    }

    public function contactStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'company' => ['nullable', 'string', 'max:160'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $to = (string) config('seo.marketing.contact_email', 'info@vibgyorsolution.com');
        $body = implode("\n", [
            'Name: '.$data['name'],
            'Email: '.$data['email'],
            'Company: '.($data['company'] ?: '—'),
            '',
            $data['message'],
        ]);

        Mail::raw($body, function ($mail) use ($to, $data) {
            $mail->to($to)
                ->replyTo($data['email'], $data['name'])
                ->subject('RankwayAI contact — '.$data['name']);
        });

        return back()->with('success', 'Thanks — we received your message and will reply soon.');
    }

    public function pricing(Request $request): Response
    {
        $interval = PlanCatalog::normalizeInterval($request->string('interval')->toString());

        return Inertia::render('Marketing/Pricing', $this->pageProps([
            'title' => 'RankwayAI Pricing — Free, Starter, Growth & Agency',
            'description' => 'Transparent RankwayAI plans in INR. Start free, then scale SEO, AI, and channel sends as your team grows.',
            'path' => '/pricing',
        ], [
            'plans' => PlanCatalog::plansForMarket(PlanCatalog::MARKET_IN, $interval),
            'interval' => $interval,
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
