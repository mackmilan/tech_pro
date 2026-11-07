<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Image;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderChecklist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ServiceOrderChecklistImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        config(['filesystems.default' => 's3']);
    }

    protected function createOrderWithChecklist(): array
    {
        $branch = Branch::create(['name' => 'Matriz', 'code' => 'MTZ-01']);
        $customer = Customer::create(['name' => 'Cliente Teste', 'phone' => '11999999999']);
        $order = ServiceOrder::create([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'number' => 'OS-TEST-001',
            'status' => 'open',
            'priority' => 'normal',
            'device_model' => 'iPhone 13',
            'defect_reported' => 'Tela quebrada',
            'opened_at' => now(),
            'total' => 0,
        ]);
        $checklist = $order->checklists()->create([
            'item_name' => 'Tela frontal',
            'category' => 'Display',
            'stage' => 'entry',
            'status' => 'defect',
            'notes' => 'Trincada no canto superior direito',
        ]);

        return [$branch, $customer, $order, $checklist];
    }

    public function test_guest_cannot_access_checklist_image_endpoints(): void
    {
        [, , $order, $checklist] = $this->createOrderWithChecklist();

        $this->getJson("/api/v1/service-order-checklists/{$checklist->id}/images")->assertUnauthorized();
        $this->postJson("/api/v1/service-order-checklists/{$checklist->id}/images")->assertUnauthorized();
        $this->getJson("/api/v1/service-orders/{$order->id}/checklists/{$checklist->id}/images")->assertUnauthorized();
        $this->postJson("/api/v1/service-orders/{$order->id}/checklists/{$checklist->id}/images")->assertUnauthorized();
    }

    public function test_user_can_upload_list_set_primary_and_delete_checklist_images(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        [, , $order, $checklist] = $this->createOrderWithChecklist();

        $file1 = UploadedFile::fake()->image('screen_crack.png', 800, 600);
        $res1 = $this->postJson("/api/v1/service-order-checklists/{$checklist->id}/images", [
            'image' => $file1,
        ])->assertCreated();

        $image1Id = $res1->json('id');
        $image1Path = $res1->json('path');
        $this->assertTrue($res1->json('is_primary'));
        $this->assertSame('screen_crack.png', $res1->json('original_name'));
        Storage::disk('s3')->assertExists($image1Path);

        // Upload second image via nested order route
        $file2 = UploadedFile::fake()->image('frame_dent.jpg', 800, 600);
        $res2 = $this->postJson("/api/v1/service-orders/{$order->id}/checklists/{$checklist->id}/images", [
            'image' => $file2,
            'is_primary' => false,
        ])->assertCreated();

        $image2Id = $res2->json('id');
        $image2Path = $res2->json('path');
        $this->assertFalse($res2->json('is_primary'));
        Storage::disk('s3')->assertExists($image2Path);

        // List images
        $listRes = $this->getJson("/api/v1/service-order-checklists/{$checklist->id}/images")->assertOk();
        $this->assertCount(2, $listRes->json('data'));

        // List via order route
        $listRes2 = $this->getJson("/api/v1/service-orders/{$order->id}/checklists/{$checklist->id}/images")->assertOk();
        $this->assertCount(2, $listRes2->json('data'));

        // Make second image primary
        $this->patchJson("/api/v1/service-order-checklists/{$checklist->id}/images/{$image2Id}/primary")
            ->assertOk()
            ->assertJsonPath('is_primary', true);

        $this->assertFalse(Image::find($image1Id)->is_primary);
        $this->assertTrue(Image::find($image2Id)->is_primary);

        // Delete primary image and ensure the other becomes primary
        $this->deleteJson("/api/v1/service-orders/{$order->id}/checklists/{$checklist->id}/images/{$image2Id}")->assertNoContent();
        Storage::disk('s3')->assertMissing($image2Path);
        $this->assertSoftDeleted('images', ['id' => $image2Id]);
        $this->assertTrue(Image::find($image1Id)->is_primary);
    }

    public function test_service_order_show_and_index_include_checklist_images(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        [, , $order, $checklist] = $this->createOrderWithChecklist();

        $file = UploadedFile::fake()->image('test_screen.png', 400, 400);
        $this->postJson("/api/v1/service-order-checklists/{$checklist->id}/images", [
            'image' => $file,
        ])->assertCreated();

        // Check show endpoint
        $showRes = $this->getJson("/api/v1/service-orders/{$order->id}")->assertOk();
        $showChecklists = $showRes->json('checklists');
        $this->assertCount(1, $showChecklists);
        $this->assertCount(1, $showChecklists[0]['images']);
        $this->assertSame('test_screen.png', $showChecklists[0]['images'][0]['original_name']);

        // Check index endpoint
        $indexRes = $this->getJson('/api/v1/service-orders')->assertOk();
        $orders = $indexRes->json('data');
        $this->assertCount(1, $orders);
        $this->assertCount(1, $orders[0]['checklists'][0]['images']);
    }

    public function test_updating_service_order_preserves_checklist_items_and_images(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        [$branch, $customer, $order, $checklist] = $this->createOrderWithChecklist();

        $file = UploadedFile::fake()->image('before_repair.png', 500, 500);
        $this->postJson("/api/v1/service-order-checklists/{$checklist->id}/images", [
            'image' => $file,
        ])->assertCreated();

        // Update the order, passing existing checklist ID
        $updateRes = $this->putJson("/api/v1/service-orders/{$order->id}", [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'device_model' => 'iPhone 13 Pro',
            'defect_reported' => 'Tela quebrada e bateria estufada',
            'checklists' => [
                [
                    'id' => $checklist->id,
                    'item_name' => 'Tela frontal atualizada',
                    'category' => 'Display',
                    'stage' => 'entry',
                    'status' => 'defect',
                    'notes' => 'Trincada no canto direito e sem touch',
                ],
                [
                    'item_name' => 'Bateria',
                    'category' => 'Energia',
                    'stage' => 'entry',
                    'status' => 'defect',
                ],
            ],
        ])->assertOk();

        $updatedChecklists = $updateRes->json('checklists');
        $this->assertCount(2, $updatedChecklists);

        // Find the original item
        $original = collect($updatedChecklists)->firstWhere('id', $checklist->id);
        $this->assertNotNull($original);
        $this->assertSame('Tela frontal atualizada', $original['item_name']);
        $this->assertCount(1, $original['images']);
        $this->assertSame('before_repair.png', $original['images'][0]['original_name']);
    }

    public function test_executing_service_order_preserves_checklist_items_and_images(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        [, , $order, $checklist] = $this->createOrderWithChecklist();

        $file = UploadedFile::fake()->image('defect_proof.png', 400, 400);
        $this->postJson("/api/v1/service-order-checklists/{$checklist->id}/images", [
            'image' => $file,
        ])->assertCreated();

        $execRes = $this->postJson("/api/v1/service-orders/{$order->id}/execute", [
            'status' => 'in_progress',
            'defect_diagnosed' => 'Tela quebrada confirmada',
            'solution_applied' => 'Substituição iniciada',
            'checklists' => [
                [
                    'id' => $checklist->id,
                    'item_name' => 'Tela frontal',
                    'category' => 'Display',
                    'stage' => 'entry',
                    'status' => 'ok',
                    'notes' => 'Trocada com sucesso',
                ],
            ],
        ])->assertOk();

        $execChecklists = $execRes->json('checklists');
        $this->assertCount(1, $execChecklists);
        $this->assertSame($checklist->id, $execChecklists[0]['id']);
        $this->assertCount(1, $execChecklists[0]['images']);
        $this->assertSame('defect_proof.png', $execChecklists[0]['images'][0]['original_name']);
    }

    public function test_checklist_image_rejects_cross_order_access(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        [$branch, $customer, $order1, $checklist1] = $this->createOrderWithChecklist();

        $order2 = ServiceOrder::create([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'number' => 'OS-OTHER-002',
            'status' => 'open',
            'priority' => 'normal',
            'device_model' => 'Galaxy S22',
            'defect_reported' => 'Não liga',
            'opened_at' => now(),
            'total' => 0,
        ]);

        $this->getJson("/api/v1/service-orders/{$order2->id}/checklists/{$checklist1->id}/images")->assertNotFound();

        $file = UploadedFile::fake()->image('test.png', 100, 100);
        $this->postJson("/api/v1/service-orders/{$order2->id}/checklists/{$checklist1->id}/images", [
            'image' => $file,
        ])->assertNotFound();
    }
}

