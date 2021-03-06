<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $fillable =[

        "name", 'image', "parent_id", "is_active"
    ];

    public function product()
    {
    	return $this->hasMany('App\Models\Product');
    }
}
