<?php
 
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plans extends Model
{
    protected $table = 'plans';
    protected $guarded = ['id'];
    protected $casts = [
        'roles' => 'array',
    ];
}
