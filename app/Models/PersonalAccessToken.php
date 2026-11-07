<?php

namespace App\Models;

use Laravel\Sanctum\Contracts\HasAbilities;

class PersonalAccessToken extends BaseModel implements HasAbilities
{
    protected $casts = [
        'abilities' => 'json',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected $fillable = ['name', 'token', 'abilities', 'expires_at'];

    protected $hidden = ['token'];

    public function tokenable()
    {
        return $this->morphTo('tokenable');
    }

    public static function findToken($token)
    {
        if (strpos($token, '|') === false) {
            return static::where('token', hash('sha256', $token))->first();
        }

        [$id, $token] = explode('|', $token, 2);

        if ($instance = static::find($id)) {
            return hash_equals($instance->token, hash('sha256', $token)) ? $instance : null;
        }
    }

    public function can($ability)
    {
        return in_array('*', $this->abilities, true) || in_array($ability, $this->abilities, true);
    }

    public function cant($ability)
    {
        return ! $this->can($ability);
    }
}
