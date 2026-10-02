<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_privacy_terms_and_data_deletion_pages_are_public(): void
    {
        $this->get('/privacy')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Marketing/Privacy'));

        $this->get('/terms')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Marketing/Terms')
                ->has('contact_email'));

        $this->get('/data-deletion')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Marketing/DataDeletion')
                ->has('contact_email'));
    }

    public function test_sitemap_lists_legal_pages(): void
    {
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('/terms', false)
            ->assertSee('/data-deletion', false);
    }
}
