<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MarketingPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_about_pricing_and_contact_pages_render(): void
    {
        $this->get(route('about'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Marketing/About')
                ->has('plans', 4));

        $this->get(route('pricing'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Marketing/Pricing')
                ->has('plans', 4)
                ->where('interval', 'month'));

        $this->get(route('contact'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Marketing/Contact')
                ->has('plans', 4)
                ->has('contact_email'));
    }

    public function test_contact_form_submits(): void
    {
        Mail::fake();

        $this->from(route('contact'))
            ->post(route('contact.store'), [
                'name' => 'Anil',
                'email' => 'anil@example.com',
                'company' => 'Vibgyor',
                'message' => 'Need a demo of RankwayAI pricing.',
            ])
            ->assertRedirect(route('contact'))
            ->assertSessionHas('success');
    }
}
