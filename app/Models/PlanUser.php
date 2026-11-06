<?php
 
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanUser extends Model
{
    protected $table = 'plan_user';
    protected $guarded = ['id'];

    public function user()
    {
        return $this->belongsTo(User::class, 'uuid', 'user_id');
    }
}
