<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinkResourcesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_link_resources_page(): void
    {
        $user = User::factory()->create([
            'is_admin' => true,
        ]);

        $this->actingAs($user)
            ->get('/admin/link-resources')
            ->assertOk();
    }
}
