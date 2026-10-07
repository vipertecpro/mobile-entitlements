<?php

namespace Vipertecpro\MobileEntitlements\Tests\Support;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Vipertecpro\MobileEntitlements\Concerns\HasEntitlements;

class User extends Authenticatable
{
    use HasEntitlements;

    protected $table = 'users';

    protected $guarded = [];

    public static function newUser(array $attributes = []): self
    {
        static $counter = 0;
        $counter++;

        return self::query()->create(array_merge([
            'name' => "User {$counter}",
            'email' => "user{$counter}-".uniqid().'@example.test',
        ], $attributes));
    }
}
