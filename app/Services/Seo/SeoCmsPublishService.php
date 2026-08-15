<?php

namespace App\Services\Seo;

use App\Models\CmsConnection;
use App\Models\SeoBlogPost;
use App\Models\SeoContentDraft;
use App\Models\Workspace;
use App\Services\Ai\AiContentService;
use App\Services\Billing\PlanAccess;
use App\Services\Seo\Contracts\CmsPublisher;
use App\Services\Seo\Providers\VerbaCmsPublisher;
use App\Services\Seo\Providers\WordpressCmsPublisher;
use Illuminate\Support\Str;
use RuntimeException;

class SeoCmsPublishService
{
    public function __construct(
        private PlanAccess $plans,
        private AiContentService $ai,
    ) {}

    public function createDraftFromKeyword(Workspace $workspace, string $keyword, ?int $keywordId = null): SeoContentDraft
    {
        if (! $this->plans->allows($workspace, 'seo_cms')) {
            throw new RuntimeException($this->plans->denyMessage('seo_cms'));
        }

        $result = $this->ai->blogOutline($workspace, $keyword);
        if (! ($result['ok'] ?? false)) {
            throw new RuntimeException($result['message'] ?? 'Could not generate outline');
        }

        $outline = $result['outline'] ?? [];
        $title = is_array($outline)
            ? ($outline['title'] ?? $outline['h1'] ?? ('Guide: '.$keyword))
            : ('Guide: '.$keyword);
        $sections = is_array($outline) ? ($outline['sections'] ?? $outline['outline'] ?? []) : [];
        if (! is_array($sections)) {
            $sections = [];
        }

        $settings = $this->ai->settings($workspace);

        $body = '<p>Draft generated for <strong>'.e($keyword).'</strong>. Review before publish.</p>';
        foreach ($sections as $section) {
            if (is_string($section)) {
                $body .= '<h2>'.e($section).'</h2><p></p>';
            } elseif (is_array($section)) {
                $heading = $section['heading'] ?? $section['title'] ?? 'Section';
                $body .= '<h2>'.e((string) $heading).'</h2>';
                if (! empty($section['bullets']) && is_array($section['bullets'])) {
                    $body .= '<ul>';
                    foreach ($section['bullets'] as $b) {
                        $body .= '<li>'.e((string) $b).'</li>';
                    }
                    $body .= '</ul>';
                }
            }
        }

        return SeoContentDraft::query()->create([
            'workspace_id' => $workspace->id,
            'seo_keyword_id' => $keywordId,
            'title' => is_string($title) ? $title : ('Guide: '.$keyword),
            'slug' => Str::slug(is_string($title) ? $title : $keyword),
            'body_html' => $body,
            'meta_title' => Str::limit(is_string($title) ? $title : $keyword, 60, ''),
            'meta_description' => Str::limit('Learn about '.$keyword.' — tips for '.$settings->location.'.', 155, ''),
            'status' => 'draft',
        ]);
    }

    public function approve(SeoContentDraft $draft): void
    {
        $draft->update(['status' => 'approved', 'last_error' => null]);
    }

    public function publish(Workspace $workspace, SeoContentDraft $draft, CmsConnection $connection): SeoContentDraft
    {
        if (! $this->plans->allows($workspace, 'seo_cms')) {
            throw new RuntimeException($this->plans->denyMessage('seo_cms'));
        }
        if (! in_array($draft->status, ['draft', 'approved', 'failed'], true)) {
            throw new RuntimeException('Draft is not publishable in status '.$draft->status);
        }

        $draft->update(['status' => 'publishing', 'cms_connection_id' => $connection->id, 'last_error' => null]);

        $publisher = $this->publisherFor($connection);
        $result = $publisher->publish($connection->credentials ?? [], [
            'title' => $draft->title,
            'slug' => $draft->slug,
            'body_html' => $draft->body_html,
            'status' => 'publish',
            'meta_title' => $draft->meta_title,
            'meta_description' => $draft->meta_description,
            'new_topics' => ['SEO', 'Marketing'],
        ]);

        if (! ($result['ok'] ?? false)) {
            $draft->update([
                'status' => 'failed',
                'last_error' => $result['message'] ?? 'Publish failed',
            ]);

            return $draft->fresh();
        }

        $draft->update([
            'status' => 'published',
            'external_id' => $result['external_id'] ?? null,
            'published_url' => $result['url'] ?? null,
            'published_at' => now(),
        ]);

        return $draft->fresh();
    }

    /**
     * Push a discovered / demo blog URL to the connected Verba page.
     *
     * @return array{ok:bool,url?:string,message:string}
     */
    public function publishBlogPost(Workspace $workspace, SeoBlogPost $post, CmsConnection $connection): array
    {
        if (! $this->plans->allows($workspace, 'seo_cms')) {
            throw new RuntimeException($this->plans->denyMessage('seo_cms'));
        }

        if (($connection->provider ?? '') !== 'verba') {
            throw new RuntimeException('Connect Verba first to publish blogs there.');
        }

        if ($post->verba_published_at !== null) {
            return [
                'ok' => true,
                'url' => $post->verba_published_url,
                'already' => true,
                'message' => 'Already published to Verba'
                    .(filled($post->verba_published_url) ? ': '.$post->verba_published_url : ''),
            ];
        }

        $plain = trim(html_entity_decode(strip_tags((string) ($post->excerpt ?: '')), ENT_QUOTES | ENT_HTML5));
        if ($plain === '') {
            $plain = trim((string) ($post->title ?: 'Untitled'));
        }

        $host = parse_url($post->url, PHP_URL_HOST) ?: $post->url;
        $body = '<p>'.e($plain).'</p><p><a href="'.e($post->url).'">Read on '.e((string) $host).'</a></p>';

        $publisher = $this->publisherFor($connection);
        $creds = is_array($connection->credentials) ? $connection->credentials : [];
        $siteKey = (string) $post->seo_site_id;
        $sitePage = $creds['site_pages'][$siteKey] ?? null;
        if (is_array($sitePage) && filled($sitePage['slug'] ?? null)) {
            $creds['page_slug'] = $sitePage['slug'];
        }

        $result = $publisher->publish($creds, [
            'title' => $post->title ?: 'Untitled',
            'body_html' => $body,
            'new_topics' => ['SEO', 'Marketing'],
        ]);

        if (! ($result['ok'] ?? false)) {
            return [
                'ok' => false,
                'message' => $result['message'] ?? 'Verba publish failed',
            ];
        }

        $post->forceFill([
            'verba_published_at' => now(),
            'verba_published_url' => $result['url'] ?? null,
        ])->save();

        return [
            'ok' => true,
            'url' => $result['url'] ?? null,
            'message' => 'Published to Verba'.(filled($result['url'] ?? null) ? ': '.$result['url'] : ''),
        ];
    }

    public function publisherFor(CmsConnection $connection): CmsPublisher
    {
        return match ($connection->provider) {
            'verba' => app(VerbaCmsPublisher::class),
            default => app(WordpressCmsPublisher::class),
        };
    }
}
