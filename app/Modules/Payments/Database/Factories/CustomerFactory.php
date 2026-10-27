<?php

namespace App\Modules\Payments\Database\Factories;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\Payments\Models\Customer;
use App\Modules\Payments\Services\CustomerAccess;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return ['location_id' => fn () => Location::create(['name' => fake()->city(), 'type' => 'branch', 'is_active' => true])->id,
            'name' => fake()->name(), 'phone' => fake()->phoneNumber(), 'email' => fake()->safeEmail(), 'is_active' => true, 'created_by' => User::factory()];
    }

    public function create($attributes = [], ?Model $parent = null): mixed
    {
        return CustomerAccess::write(fn () => parent::create($attributes, $parent));
    }
}
