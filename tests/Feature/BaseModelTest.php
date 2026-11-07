<?php

namespace Tests\Feature;

use App\Models\BaseModel;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\FiscalDocument;
use App\Models\Image;
use App\Models\Lead;
use App\Models\PersonalAccessToken;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Service;
use App\Models\ServiceChecklist;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderChecklist;
use App\Models\ServiceOrderItem;
use App\Models\Stock;
use App\Models\StockItem;
use App\Models\StockItemAttributeValue;
use App\Models\StockUnit;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\ProductCondition;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BaseModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('base_model_fixtures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('base_model_fixtures');

        parent::tearDown();
    }

    public function test_all_application_models_extend_base_model(): void
    {
        foreach ([
            Branch::class => 'branches',
            Brand::class => 'brands',
            Category::class => 'categories',
            Customer::class => 'customers',
            FiscalDocument::class => 'fiscal_documents',
            Image::class => 'images',
            Lead::class => 'leads',
            PersonalAccessToken::class => 'personal_access_tokens',
            ProductCondition::class => 'product_conditions',
            PurchaseOrder::class => 'purchase_orders',
            PurchaseOrderItem::class => 'purchase_order_items',
            Sale::class => 'sales',
            SaleItem::class => 'sale_items',
            Service::class => 'services',
            ServiceChecklist::class => 'service_checklists',
            ServiceOrder::class => 'service_orders',
            ServiceOrderChecklist::class => 'service_order_checklists',
            ServiceOrderItem::class => 'service_order_items',
            Stock::class => 'stocks',
            StockItem::class => 'stock_items',
            StockItemAttributeValue::class => 'stock_item_attribute_values',
            StockUnit::class => 'stock_units',
            StockLocation::class => 'stock_locations',
            StockMovement::class => 'stock_movements',
            Supplier::class => 'suppliers',
            \App\Models\Technician::class => 'technicians',
            User::class => 'users',
        ] as $model => $table) {
            $this->assertTrue(is_subclass_of($model, BaseModel::class), $model.' must extend BaseModel');
            $this->assertTrue(Schema::hasColumn($table, 'active'), $table.' must have an active column');
        }
    }

    public function test_active_is_true_by_default_and_can_be_disabled(): void
    {
        $model = new BaseModelFixture(['name' => 'active']);
        $model->save();

        $this->assertTrue($model->active);

        $model->active = false;
        $model->save();

        $this->assertFalse($model->fresh()->active);
    }

    public function test_base_model_generates_uuid_and_manages_timestamps_and_soft_deletes(): void
    {
        $model = new BaseModelFixture(['name' => 'fixture']);
        $model->save();

        $this->assertTrue(Str::isUuid($model->getKey()));
        $this->assertNotNull($model->created_at);
        $this->assertNotNull($model->updated_at);

        $model->delete();

        $this->assertNotNull($model->deleted_at);
        $this->assertSame(0, BaseModelFixture::query()->count());
        $this->assertSame(1, BaseModelFixture::withTrashed()->count());
    }
}

class BaseModelFixture extends BaseModel
{
    protected $table = 'base_model_fixtures';

    protected $fillable = ['name', 'active'];
}
