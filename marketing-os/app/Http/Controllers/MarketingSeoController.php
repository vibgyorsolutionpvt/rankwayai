<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class MarketingSeoController extends Controller
{
    public function robots(): Response
    {
        $sitemap = $this->publicBase().'/sitemap.xml';

        $body = implode("\n", [
            'User-agent: *',
            'Allow: /',
            'Disallow: /home',
            'Disallow: /today',
            'Disallow: /seo',
            'Disallow: /settings',
            'Disallow: /admin',
            'Disallow: /brand',
            'Disallow: /social',
            'Disallow: /crm',
            'Disallow: /whatsapp',
            'Disallow: /channels',
            'Disallow: /funnels',
            'Disallow: /billing',
            'Disallow: /workspaces',
            'Disallow: /ai',
            'Disallow: /media',
            'Disallow: /login',
            'Disallow: /register',
            'Disallow: /password',
            'Disallow: /email',
            'Disallow: /webhooks',
            '',
            'Sitemap: '.$sitemap,
            '',
        ]);

        return response($body, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function sitemap(): Response
    {
        $base = $this->publicBase();
        $now = now()->toAtomString();

        $urls = [
            ['loc' => $base.'/', 'priority' => '1.0', 'changefreq' => 'weekly'],
            ['loc' => $base.'/about', 'priority' => '0.8', 'changefreq' => 'monthly'],
            ['loc' => $base.'/pricing', 'priority' => '0.9', 'changefreq' => 'weekly'],
            ['loc' => $base.'/contact', 'priority' => '0.7', 'changefreq' => 'monthly'],
        ];

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $url) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>'.e($url['loc'])."</loc>\n";
            $xml .= '    <lastmod>'.$now."</lastmod>\n";
            $xml .= '    <changefreq>'.$url['changefreq']."</changefreq>\n";
            $xml .= '    <priority>'.$url['priority']."</priority>\n";
            $xml .= "  </url>\n";
        }
        $xml .= '</urlset>';

        return response($xml, 200)->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    private function publicBase(): string
    {
        return rtrim((string) config('seo.marketing.public_url', config('app.url')), '/');
    }
}
