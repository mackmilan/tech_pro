<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Image;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        config(['filesystems.default' => 's3']);
    }

    public function test_guest_cannot_access_image_endpoints(): void
    {
        $brand = Brand::create(['name' => 'Brand Test']);
        $product = Product::create([
            'sku' => 'SKU-GUEST',
            'name' => 'Product Guest',
            'category' => 'General',
            'sale_price' => 10,
        ]);

        $this->getJson("/api/v1/brands/{$brand->id}/images")->assertUnauthorized();
        $this->postJson("/api/v1/brands/{$brand->id}/images")->assertUnauthorized();
        $this->getJson("/api/v1/products/{$product->id}/images")->assertUnauthorized();
        $this->postJson("/api/v1/products/{$product->id}/images")->assertUnauthorized();
    }

    public function test_user_can_upload_list_set_primary_and_delete_brand_images(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $brand = Brand::create(['name' => 'Samsung']);

        $file1 = UploadedFile::fake()->image('logo.png', 400, 400);

        $uploadResponse1 = $this->postJson("/api/v1/brands/{$brand->id}/images", [
            'image' => $file1,
        ])->assertCreated();

        $image1Id = $uploadResponse1->json('id');
        $image1Path = $uploadResponse1->json('path');

        $this->assertTrue($uploadResponse1->json('is_primary'));
        $this->assertSame('logo.png', $uploadResponse1->json('original_name'));
        $this->assertNotEmpty($uploadResponse1->json('url'));
        Storage::disk('s3')->assertExists($image1Path);

        // Upload second image
        $file2 = UploadedFile::fake()->image('banner.jpg', 800, 600);
        $uploadResponse2 = $this->postJson("/api/v1/brands/{$brand->id}/images", [
            'image' => $file2,
            'is_primary' => false,
        ])->assertCreated();

        $image2Id = $uploadResponse2->json('id');
        $image2Path = $uploadResponse2->json('path');
        $this->assertFalse($uploadResponse2->json('is_primary'));
        Storage::disk('s3')->assertExists($image2Path);

        // List images
        $listResponse = $this->getJson("/api/v1/brands/{$brand->id}/images")->assertOk();
        $this->assertCount(2, $listResponse->json('data'));

        // Brand index/show includes images
        $brandResponse = $this->getJson("/api/v1/brands/{$brand->id}")->assertOk();
        $this->assertCount(2, $brandResponse->json('images'));

        // Set second image as primary
        $this->patchJson("/api/v1/brands/{$brand->id}/images/{$image2Id}/primary")
            ->assertOk()
            ->assertJsonPath('is_primary', true);

        $this->assertFalse(Image::find($image1Id)->is_primary);
        $this->assertTrue(Image::find($image2Id)->is_primary);

        // Delete primary image and verify remaining image becomes primary
        $this->deleteJson("/api/v1/brands/{$brand->id}/images/{$image2Id}")->assertNoContent();
        Storage::disk('s3')->assertMissing($image2Path);
        $this->assertSoftDeleted('images', ['id' => $image2Id]);

        $this->assertTrue(Image::find($image1Id)->is_primary);
    }

    public function test_user_can_upload_list_and_delete_product_images(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $product = Product::create([
            'sku' => 'PHONE-IMG-001',
            'name' => 'Smartphone Galaxy',
            'category' => 'Smartphones',
            'sale_price' => 1999.90,
        ]);

        $file = UploadedFile::fake()->image('galaxy.png', 500, 500);

        $response = $this->postJson("/api/v1/products/{$product->id}/images", [
            'file' => $file,
        ])->assertCreated();

        $imageId = $response->json('id');
        $path = $response->json('path');

        $this->assertTrue($response->json('is_primary'));
        Storage::disk('s3')->assertExists($path);

        // Product show includes images
        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('images.0.id', $imageId);

        // Direct image delete via /api/v1/images/{image}
        $this->deleteJson("/api/v1/images/{$imageId}")->assertNoContent();
        Storage::disk('s3')->assertMissing($path);
        $this->assertSoftDeleted('images', ['id' => $imageId]);
    }

    public function test_image_upload_validation_rejects_invalid_files_and_sizes(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $brand = Brand::create(['name' => 'LG']);

        // Missing file
        $this->postJson("/api/v1/brands/{$brand->id}/images", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');

        // Not an image (e.g. text file)
        $textDoc = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
        $this->postJson("/api/v1/brands/{$brand->id}/images", ['image' => $textDoc])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');

        // Too large (> 10MB)
        $hugeImage = UploadedFile::fake()->create('huge.png', 11000, 'image/png');
        $this->postJson("/api/v1/brands/{$brand->id}/images", ['image' => $hugeImage])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');
    }

    public function test_cross_entity_image_operations_are_rejected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $brand1 = Brand::create(['name' => 'Brand 1']);
        $brand2 = Brand::create(['name' => 'Brand 2']);

        $file = UploadedFile::fake()->image('logo.png');
        $res = $this->postJson("/api/v1/brands/{$brand1->id}/images", ['image' => $file])->assertCreated();
        $imageId = $res->json('id');

        // Trying to delete brand1's image via brand2 URL returns 404
        $this->deleteJson("/api/v1/brands/{$brand2->id}/images/{$imageId}")->assertNotFound();
        $this->patchJson("/api/v1/brands/{$brand2->id}/images/{$imageId}/primary")->assertNotFound();
    }

    public function test_image_upload_always_uses_s3_disk_even_when_default_disk_is_local(): void
    {
        config(['filesystems.default' => 'local']);
        config(['filesystems.images_disk' => 's3']);
        Storage::fake('s3');
        Storage::fake('local');

        $user = User::factory()->create();
        $this->actingAs($user);

        $brand = Brand::create(['name' => 'Apple Inc']);
        $file = UploadedFile::fake()->image('apple.png', 200, 200);

        $response = $this->postJson("/api/v1/brands/{$brand->id}/images", [
            'image' => $file,
        ])->assertCreated();

        $path = $response->json('path');
        $this->assertSame('s3', $response->json('disk'));

        Storage::disk('s3')->assertExists($path);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_image_url_accessor_generates_url_from_disk_and_path(): void
    {
        $brand = Brand::create(['name' => 'Brand Test']);
        $image = Image::create([
            'imageable_type' => $brand->getMorphClass(),
            'imageable_id' => $brand->getKey(),
            'disk' => 's3',
            'path' => 'images/brands/logo.png',
            'url' => '/images/brands/logo.png',
        ]);

        $expectedUrl = Storage::disk('s3')->url('images/brands/logo.png');
        $this->assertSame($expectedUrl, $image->url);
        $this->assertSame($expectedUrl, $image->toArray()['url']);
    }
}

