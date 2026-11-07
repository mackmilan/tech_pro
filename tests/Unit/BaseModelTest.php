<?php

namespace Tests\Unit;

use App\Models\BaseModel;
use PHPUnit\Framework\TestCase;

class BaseModelTest extends TestCase
{
    public function test_base_model_exists(): void
    {
        $this->assertTrue(class_exists(BaseModel::class));
    }
}
