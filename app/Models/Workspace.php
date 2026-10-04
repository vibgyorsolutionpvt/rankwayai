<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use App\Support\BusinessTypes;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'business_type',
        'industry',
        'city',
        'state',
        'country',
        'postal_code',
        'address',
        'phone',
        'whatsapp',
        'email',
        'website',
        'tagline',
        'description',
        'services',
        'products',
        'target_audience',
        'working_hours',
        'social_links',
        'crm_lead_custom_fields',
        'enabled_modules',
        'enabled_social_platforms',
    ];

    protected function casts(): array
    {
        return [
            'enabled_modules' => 'array',
            'enabled_social_platforms' => 'array',
            'services' => 'array',
            'products' => 'array',
            'social_links' => 'array',
            'crm_lead_custom_fields' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Workspace $workspace): void {
            if (blank($workspace->slug)) {
                $workspace->slug = static::uniqueSlug($workspace->name);
            }
        });
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;
        $i = 1;

        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')
            ->withPivot(['role', 'enabled_modules'])
            ->withTimestamps();
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function brandKits(): HasMany
    {
        return $this->hasMany(BrandKit::class);
    }

    public function businessCards(): HasMany
    {
        return $this->hasMany(BusinessCard::class);
    }

    public function brochures(): HasMany
    {
        return $this->hasMany(Brochure::class);
    }

    public function itineraries(): HasMany
    {
        return $this->hasMany(Itinerary::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    /**
     * Active brand kit for SMM / Channels / AI / Funnels.
     */
    public function brandKit(): HasOne
    {
        return $this->hasOne(BrandKit::class)->where('is_active', true);
    }

    public function resolveBrandKit(): ?BrandKit
    {
        return $this->brandKit
            ?? $this->brandKits()->orderByDesc('is_active')->orderBy('id')->first();
    }

    public function mediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function socialPosts(): HasMany
    {
        return $this->hasMany(SocialPost::class);
    }

    public function seoSites(): HasMany
    {
        return $this->hasMany(SeoSite::class);
    }

    public function seoTasks(): HasMany
    {
        return $this->hasMany(SeoTask::class);
    }

    public function seoKeywords(): HasMany
    {
        return $this->hasMany(SeoKeyword::class);
    }

    public function seoCompetitors(): HasMany
    {
        return $this->hasMany(SeoCompetitor::class);
    }

    public function crmLeads(): HasMany
    {
        return $this->hasMany(CrmLead::class);
    }

    public function channelCampaigns(): HasMany
    {
        return $this->hasMany(ChannelCampaign::class);
    }

    public function channelMessageTemplates(): HasMany
    {
        return $this->hasMany(ChannelMessageTemplate::class);
    }

    public function whatsappConversations(): HasMany
    {
        return $this->hasMany(WhatsappConversation::class);
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(WorkspaceIntegration::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(WorkspaceSubscription::class);
    }

    public function funnels(): HasMany
    {
        return $this->hasMany(Funnel::class);
    }

    public function roleFor(User $user): ?WorkspaceRole
    {
        $membership = $this->users()->where('user_id', $user->id)->first();

        if (! $membership) {
            return null;
        }

        return WorkspaceRole::from($membership->pivot->role);
    }

    public function hasMember(User $user): bool
    {
        return $this->users()->where('user_id', $user->id)->exists();
    }

    public function resolvedIndustry(): ?string
    {
        $value = trim((string) ($this->industry ?? ''));

        if ($value !== '' && $value !== 'local business') {
            return $value;
        }

        $fromType = BusinessTypes::label($this->business_type);

        return $fromType;
    }

    public function resolvedCity(): ?string
    {
        $value = trim((string) ($this->city ?? ''));

        return $value !== '' && $value !== 'India' ? $value : null;
    }

    public function hasBusinessProfile(): bool
    {
        return $this->resolvedIndustry() !== null && $this->resolvedCity() !== null;
    }

    public function resolvedPhone(): ?string
    {
        $value = trim((string) ($this->phone ?? ''));

        if ($value !== '') {
            return $value;
        }

        $fromKit = trim((string) ($this->resolveBrandKit()?->phone ?? ''));

        return $fromKit !== '' ? $fromKit : null;
    }

    public function resolvedWhatsapp(): ?string
    {
        $value = trim((string) ($this->whatsapp ?? ''));
        if ($value !== '') {
            return $value;
        }

        return $this->resolvedPhone();
    }

    public function resolvedEmail(): ?string
    {
        $value = trim((string) ($this->email ?? ''));

        if ($value !== '') {
            return $value;
        }

        $fromKit = trim((string) ($this->resolveBrandKit()?->email ?? ''));

        return $fromKit !== '' ? $fromKit : null;
    }

    public function resolvedWebsite(): ?string
    {
        $value = trim((string) ($this->website ?? ''));

        if ($value !== '') {
            return $value;
        }

        $fromKit = trim((string) ($this->resolveBrandKit()?->website_url ?? ''));

        return $fromKit !== '' ? $fromKit : null;
    }

    /**
     * Full business profile payload for Settings / documents / AI.
     *
     * @return array<string, mixed>
     */
    public function businessProfile(): array
    {
        return [
            'business_name' => $this->name,
            'business_type' => $this->business_type,
            'business_type_label' => BusinessTypes::label($this->business_type),
            'industry' => $this->resolvedIndustry(),
            'tagline' => $this->tagline,
            'description' => $this->description,
            'phone' => $this->resolvedPhone(),
            'whatsapp' => $this->resolvedWhatsapp(),
            'email' => $this->resolvedEmail(),
            'website' => $this->resolvedWebsite(),
            'address' => $this->address,
            'city' => $this->resolvedCity(),
            'state' => $this->state,
            'country' => $this->country,
            'postal_code' => $this->postal_code,
            'services' => array_values(array_filter($this->services ?? [])),
            'products' => array_values(array_filter($this->products ?? [])),
            'target_audience' => $this->target_audience,
            'working_hours' => $this->working_hours,
            'social_links' => $this->social_links ?? [],
        ];
    }

    /**
     * @return array{phone:?string,email:?string,website:?string,whatsapp:?string}
     */
    public function contactDetails(): array
    {
        return [
            'phone' => $this->resolvedPhone(),
            'whatsapp' => $this->resolvedWhatsapp(),
            'email' => $this->resolvedEmail(),
            'website' => $this->resolvedWebsite(),
        ];
    }

    public function hasContactDetails(): bool
    {
        $contact = $this->contactDetails();

        return ($contact['phone'] ?? null) !== null
            || ($contact['whatsapp'] ?? null) !== null
            || ($contact['email'] ?? null) !== null
            || ($contact['website'] ?? null) !== null;
    }
}
