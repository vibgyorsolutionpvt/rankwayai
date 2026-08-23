<?php

namespace App\Services\Seo;

use App\Models\CmsConnection;
use App\Models\SeoBlogPost;
use App\Models\SeoContentDraft;
use App\Models\Workspace;
use App\Services\Ai\AiContentService;
use App\Services\Billing\PlanAccess;
use App\Services\Seo\Contracts\CmsPublisher;
use App\Services\Seo\Providers\AskefyCmsPublisher;
use App\Services\Seo\Providers\WordpressCmsPublisher;
use Illuminate\Support\Str;
use RuntimeException;

class SeoCmsPublishService
{
    public function __construct(
        private PlanAccess $plans,
        private AiContentService $ai,
    ) {}

    public function createDraftFromKeyword(
        Workspace $workspace,
        string $keyword,
        ?int $keywordId = null,
        ?int $userId = null,
    ): SeoContentDraft {
        if (! $this->plans->allows($workspace, 'seo_cms')) {
            throw new RuntimeException($this->plans->denyMessage('seo_cms'));
        }

        $result = $this->ai->writeBlogArticle($workspace, $keyword, $userId);
        if (! ($result['ok'] ?? false)) {
            throw new RuntimeException($result['message'] ?? 'Could not generate blog article');
        }

        $article = $result['article'] ?? [];
        $title = is_string($article['title'] ?? null) ? $article['title'] : ('Guide: '.$keyword);
        $body = is_string($article['body_html'] ?? null) && trim(strip_tags($article['body_html'])) !== ''
            ? $article['body_html']
            : '<p>Draft generated for <strong>'.e($keyword).'</strong>. Review before publish.</p>';
        $metaTitle = is_string($article['meta_title'] ?? null)
            ? $article['meta_title']
            : Str::limit($title, 60, '');
        $metaDescription = is_string($article['meta_description'] ?? null)
            ? $article['meta_description']
            : Str::limit('Learn about '.$keyword.'.', 155, '');

        return SeoContentDraft::query()->create([
            'workspace_id' => $workspace->id,
            'seo_keyword_id' => $keywordId,
            'title' => $title,
            'slug' => Str::slug($title) ?: Str::slug($keyword),
            'body_html' => $body,
            'meta_title' => $metaTitle,
            'meta_description' => $metaDescription,
            'status' => 'draft',
        ]);
    }

    public function approve(SeoContentDraft $draft): void
    {
        if (! $draft->isReviewed()) {
            throw new RuntimeException('Open Review, edit if needed, and save before approving.');
        }

        if (! in_array($draft->status, ['draft', 'failed'], true)) {
            throw new RuntimeException('Only reviewed drafts can be approved.');
        }

        $draft->update(['status' => 'approved', 'last_error' => null]);
    }

    public function publish(Workspace $workspace, SeoContentDraft $draft, CmsConnection $connection): SeoContentDraft
    {
        if (! $this->plans->allows($workspace, 'seo_cms')) {
            throw new RuntimeException($this->plans->denyMessage('seo_cms'));
        }

        if (! $draft->isReviewed()) {
            throw new RuntimeException('Review the draft before publishing.');
        }

        if (! in_array($draft->status, ['approved', 'failed'], true)) {
            throw new RuntimeException('Approve the draft after review before publishing.');
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
     * Push a discovered / demo blog URL to the connected Askefy page.
     *
     * @return array{ok:bool,url?:string,message:string}
     */
    public function publishBlogPost(Workspace $workspace, SeoBlogPost $post, CmsConnection $connection): array
    {
        if (! $this->plans->allows($workspace, 'seo_cms')) {
            throw new RuntimeException($this->plans->denyMessage('seo_cms'));
        }

        if (! in_array($connection->provider ?? '', ['askefy', 'verba'], true)) {
            throw new RuntimeException('Connect Askefy first to publish blogs there.');
        }

        if ($post->verba_published_at !== null) {
            return [
                'ok' => true,
                'url' => $post->verba_published_url,
                'already' => true,
                'message' => 'Already published to Askefy'
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
                'message' => $result['message'] ?? 'Askefy publish failed',
            ];
        }

        $post->forceFill([
            'verba_published_at' => now(),
            'verba_published_url' => $result['url'] ?? null,
        ])->save();

        return [
            'ok' => true,
            'url' => $result['url'] ?? null,
            'message' => 'Published to Askefy'.(filled($result['url'] ?? null) ? ': '.$result['url'] : ''),
        ];
    }

    public function publisherFor(CmsConnection $connection): CmsPublisher
    {
        return match ($connection->provider) {
            'askefy', 'verba' => app(AskefyCmsPublisher::class),
            default => app(WordpressCmsPublisher::class),
        };
    }
}
