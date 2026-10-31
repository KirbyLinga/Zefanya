<?php

namespace Tests\Feature\Admin;

use App\Models\Admin\Admin;
use App\Models\Admin\DocumentAccessLog;
use App\Models\Buyer\Buyer;
use App\Models\Seller\Seller;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

/**
 * Every successful admin view of a private identity document must leave an
 * append-only audit row containing only references — never file contents,
 * storage paths or tokens. Moderators are denied entirely.
 */
class AdminDocumentAuditTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const BUYER_PATH = 'ids/audit-buyer.jpg';

    private const SELLER_PATH = 'seller-ids/audit-seller.jpg';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::disk('local')->put(self::BUYER_PATH, 'SECRET-BUYER-CONTENT');
        Storage::disk('local')->put(self::SELLER_PATH, 'SECRET-SELLER-CONTENT');
    }

    public function test_admin_view_writes_an_audit_row_with_references_only(): void
    {
        $admin = Admin::factory()->create();
        $buyer = Buyer::factory()->create();
        $buyer->forceFill(['upload_id_path' => self::BUYER_PATH])->save();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.documents.stream', [
                'type' => 'buyer',
                'id' => $buyer->id,
                'document' => 'id',
            ]))
            ->assertOk();

        $log = DocumentAccessLog::query()->sole();

        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame('buyer', $log->document_type);
        $this->assertSame($buyer->id, $log->document_id);
        $this->assertSame('id', $log->document_key);
        $this->assertSame('view', $log->action);
        $this->assertNotNull($log->created_at);

        $serialized = json_encode($log->getAttributes());

        $this->assertStringNotContainsString('SECRET-BUYER-CONTENT', $serialized);
        $this->assertStringNotContainsString(self::BUYER_PATH, $serialized);
    }

    public function test_super_admin_is_also_audited(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $seller = Seller::factory()->create();
        $seller->forceFill(['upload_id_path' => self::SELLER_PATH])->save();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.documents.stream', [
                'type' => 'seller',
                'id' => $seller->id,
                'document' => 'id',
            ]))
            ->assertOk();

        $this->assertDatabaseHas('admin_document_access_logs', [
            'admin_id' => $admin->id,
            'document_type' => 'seller',
            'document_id' => $seller->id,
            'document_key' => 'id',
            'action' => 'view',
        ]);
    }

    public function test_moderator_is_forbidden_and_nothing_is_logged(): void
    {
        $moderator = Admin::factory()->create(['role' => 'moderator']);
        $buyer = Buyer::factory()->create();
        $buyer->forceFill(['upload_id_path' => self::BUYER_PATH])->save();

        $this->actingAs($moderator, 'admin')
            ->get(route('admin.documents.stream', [
                'type' => 'buyer',
                'id' => $buyer->id,
                'document' => 'id',
            ]))
            ->assertForbidden();

        $this->assertSame(0, DocumentAccessLog::query()->count());
    }

    public function test_audit_rows_are_append_only(): void
    {
        $admin = Admin::factory()->create();
        $log = DocumentAccessLog::record($admin->id, 'buyer', 5, 'id', '203.0.113.10');

        $this->expectException(LogicException::class);
        $log->action = 'tampered';
        $log->save();
    }

    public function test_audit_rows_cannot_be_deleted(): void
    {
        $admin = Admin::factory()->create();
        $log = DocumentAccessLog::record($admin->id, 'buyer', 5, 'id', '203.0.113.10');

        $this->expectException(LogicException::class);
        $log->delete();
    }
}
