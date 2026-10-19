<?php

namespace Tests\Feature;

use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Admin-only document streaming (App\Http\Controllers\Admin\DocumentController)
 * reads from the PRIVATE local disk. Proves:
 *   - an admin can open each document type,
 *   - other roles and guests are denied,
 *   - unknown model / wrong document / missing file 404,
 *   - the same paths are NOT reachable on the public disk.
 */
class AdminDocumentStreamingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const LOGISTICS_ID_PATH = 'logistics-ids/id-card.jpg';

    private const LOGISTICS_DTI_PATH = 'logistics-permits/dti-permit.jpg';

    private const SELLER_ID_PATH = 'seller-ids/id-card.jpg';

    private const SELLER_PERMIT_PATH = 'business-permits/permit.jpg';

    private const BUYER_ID_PATH = 'ids/id-card.jpg';

    private Seller $seller;

    private LogisticsProvider $logistics;

    private Buyer $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep real storage clean: the private disk is faked per test run.
        Storage::fake('local');

        Storage::disk('local')->put(self::LOGISTICS_ID_PATH, 'LOGISTICS-ID-CONTENTS');
        Storage::disk('local')->put(self::LOGISTICS_DTI_PATH, 'LOGISTICS-DTI-CONTENTS');
        Storage::disk('local')->put(self::SELLER_ID_PATH, 'SELLER-ID-CONTENTS');
        Storage::disk('local')->put(self::SELLER_PERMIT_PATH, 'SELLER-PERMIT-CONTENTS');
        Storage::disk('local')->put(self::BUYER_ID_PATH, 'BUYER-ID-CONTENTS');

        $this->seller = Seller::factory()->create(['status' => 'approved']);
        $this->seller->forceFill([
            'upload_id_path' => self::SELLER_ID_PATH,
            'business_permit_path' => self::SELLER_PERMIT_PATH,
        ])->save();

        $this->logistics = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
        $this->logistics->forceFill([
            'upload_id_path' => self::LOGISTICS_ID_PATH,
            'dti_permit_path' => self::LOGISTICS_DTI_PATH,
        ])->save();

        $this->buyer = Buyer::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
        $this->buyer->forceFill(['upload_id_path' => self::BUYER_ID_PATH])->save();
    }

    private function admin(): Admin
    {
        return Admin::create([
            'name' => 'Document Admin',
            'email' => 'doc.admin.'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    // ── Allowed: admin ───────────────────────────────────────────────────────

    public function test_admin_can_stream_logistics_id_and_dti_permit(): void
    {
        $id = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.documents.stream', ['type' => 'logistics', 'id' => $this->logistics->id, 'document' => 'id']))
            ->assertOk();
        $this->assertStringContainsString('LOGISTICS-ID-CONTENTS', $id->streamedContent());

        $dti = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.documents.stream', ['type' => 'logistics', 'id' => $this->logistics->id, 'document' => 'dti_permit']))
            ->assertOk();
        $this->assertStringContainsString('LOGISTICS-DTI-CONTENTS', $dti->streamedContent());
    }

    public function test_admin_can_stream_seller_and_buyer_documents(): void
    {
        $sellerId = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.documents.stream', ['type' => 'seller', 'id' => $this->seller->id, 'document' => 'id']))
            ->assertOk();
        $this->assertStringContainsString('SELLER-ID-CONTENTS', $sellerId->streamedContent());

        $permit = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.documents.stream', ['type' => 'seller', 'id' => $this->seller->id, 'document' => 'business_permit']))
            ->assertOk();
        $this->assertStringContainsString('SELLER-PERMIT-CONTENTS', $permit->streamedContent());

        $buyerId = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.documents.stream', ['type' => 'buyer', 'id' => $this->buyer->id, 'document' => 'id']))
            ->assertOk();
        $this->assertStringContainsString('BUYER-ID-CONTENTS', $buyerId->streamedContent());
    }

    // ── Denied: guests and other roles ───────────────────────────────────────

    public function test_guest_is_denied(): void
    {
        $this->get(route('admin.documents.stream', ['type' => 'logistics', 'id' => $this->logistics->id, 'document' => 'id']))
            ->assertRedirect(route('admin.login'));
    }

    public function test_non_admin_roles_are_denied(): void
    {
        $url = route('admin.documents.stream', ['type' => 'logistics', 'id' => $this->logistics->id, 'document' => 'id']);

        // A live session on another guard still fails auth:admin → login redirect.
        $this->actingAs($this->buyer, 'buyer')->get($url)->assertRedirect(route('admin.login'));
        $this->actingAs($this->seller, 'seller')->get($url)->assertRedirect(route('admin.login'));
        $this->actingAs($this->logistics, 'logistics')->get($url)->assertRedirect(route('admin.login'));
    }

    public function test_stream_response_carries_nosniff(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.documents.stream', ['type' => 'logistics', 'id' => $this->logistics->id, 'document' => 'id']))
            ->assertOk();

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    // ── 404 paths ────────────────────────────────────────────────────────────

    public function test_unknown_model_returns_404(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.documents.stream', ['type' => 'logistics', 'id' => 999999, 'document' => 'id']))
            ->assertNotFound();
    }

    public function test_wrong_document_for_type_returns_404(): void
    {
        // logistics has no business_permit → getDocumentPath() = null → 404.
        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.documents.stream', ['type' => 'logistics', 'id' => $this->logistics->id, 'document' => 'business_permit']))
            ->assertNotFound();
    }

    public function test_missing_file_on_disk_returns_404(): void
    {
        $ghost = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
        $ghost->forceFill(['upload_id_path' => 'logistics-ids/never-written.jpg'])->save();

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.documents.stream', ['type' => 'logistics', 'id' => $ghost->id, 'document' => 'id']))
            ->assertNotFound();
    }

    public function test_documents_are_not_reachable_on_the_public_disk(): void
    {
        foreach ([self::LOGISTICS_ID_PATH, self::LOGISTICS_DTI_PATH, self::SELLER_ID_PATH, self::SELLER_PERMIT_PATH, self::BUYER_ID_PATH] as $path) {
            Storage::disk('public')->assertMissing($path);
            $this->assertFalse(
                file_exists(public_path('storage/'.$path)),
                "document {$path} must not exist under public/storage"
            );
        }
    }
}
