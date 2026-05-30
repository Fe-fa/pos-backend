<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    protected $table = 'permissions';
    protected $primaryKey = 'id';
    protected $fillable = ['name', 'guard_name'];
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class, 
            'permission_user', 
            'permission_id', 
            'user_id'
        );
    }
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class, 
            'role_permission', 
            'permission_id', 
            'role_id'
        );
    }
}