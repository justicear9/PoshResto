<?php

namespace App\Models;

use App\Traits\HasBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\BaseModel;

class Tax extends BaseModel
{
    use HasFactory;
    use HasBranch;

    protected $guarded = ['id'];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function menuItems(): BelongsToMany
    {
        return $this->belongsToMany(MenuItem::class, 'menu_item_tax', 'tax_id', 'menu_item_id');
    }

    public function orderTaxes(): HasMany
    {
        return $this->hasMany(OrderTax::class);
    }
}
