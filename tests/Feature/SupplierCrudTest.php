<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_supplier_crud_api(): void
    {
        $this->getJson('/api/v1/suppliers')->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_list_show_update_and_soft_delete_suppliers(): void
    {
        $this->actingAs(User::factory()->create());

        $supplier = $this->postJson('/api/v1/suppliers', [
            'name' => 'Fornecedor Alfa',
            'document' => '12.345.678/0001-90',
            'email' => 'contato@alfa.test',
            'phone' => '(11) 99999-0000',
        ])->assertCreated()
            ->assertJsonPath('name', 'Fornecedor Alfa')
            ->assertJsonPath('document', '12.345.678/0001-90')
            ->json();

        $this->getJson('/api/v1/suppliers')->assertOk()
            ->assertJsonPath('data.0.id', $supplier['id'])
            ->assertJsonPath('data.0.name', 'Fornecedor Alfa');
        $this->getJson('/api/v1/suppliers/'.$supplier['id'])
            ->assertOk()->assertJsonPath('id', $supplier['id']);
        $this->putJson('/api/v1/suppliers/'.$supplier['id'], [
            'name' => 'Fornecedor Alfa Atualizado',
            'document' => '98.765.432/0001-10',
            'email' => 'novo@alfa.test',
            'phone' => '(21) 98888-0000',
        ])->assertOk()->assertJsonPath('name', 'Fornecedor Alfa Atualizado')
            ->assertJsonPath('email', 'novo@alfa.test');
        $this->deleteJson('/api/v1/suppliers/'.$supplier['id'])->assertNoContent();
        $this->assertSoftDeleted('suppliers', ['id' => $supplier['id']]);
        $this->getJson('/api/v1/suppliers/'.$supplier['id'])->assertNotFound();
    }

    public function test_supplier_requires_name_and_valid_email(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/v1/suppliers', [])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/suppliers', ['name' => 'Fornecedor Beta', 'email' => 'invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }
}
