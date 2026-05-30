<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo; 
use Illuminate\Database\Eloquent\Relations\HasMany;
use Exception;

class Category extends Model
{
    use HasUuid;

    protected $table = 'categories';
    protected $primaryKey = 'category_id';

    protected $fillable = [
        'uuid',
        'store_id',
        'category_name',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id', 'store_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id', 'category_id');
    }

    protected static function boot()
    {
        parent::boot();

        // Intercept the deleting event
        static::deleting(function ($category) {
            if ($category->products()->exists()) {
                throw new Exception("Cannot delete category because it contains products.");
            }
        });
    }
}