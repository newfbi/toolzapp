<?php

namespace App\Models\Traits;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class RoleUser extends Model
{
    public $timestamps = false;
    protected $table = 'role_user';
    protected $fillable = ['role_id', 'user_id'];

    /**
     * Get Permissions
     */
    public function permissions()
    {
        return $this->belongsToMany(Permission::class);
    }

    /**
     * Get Users
     */
    public function users()
    {
        return $this->belongsToMany(
            User::class,         // Modelo relacionado
            'role_user',         // Tabela intermediária (pivot table)
            'role_id',           // Chave estrangeira na pivot table para este modelo (roles)
            'user_id',         // Chave estrangeira na pivot table para o modelo User
            'id',                // Chave local neste modelo
            'uuid'               // Chave local no modelo User
        );
    }

    /**
     * Permission not linked with this profile
     */
    public function permissionsAvailable($filter = null)
    {
        $permissions = Permission::whereNotIn('permissions.id', function($query) {
            $query->select('permission_role.permission_id');
            $query->from('permission_role');
            $query->whereRaw("permission_role.role_id={$this->id}");
        })
        ->where(function ($queryFilter) use ($filter) {
            if ($filter)
                $queryFilter->where('permissions.name', 'LIKE', "%{$filter}%");
        })
        ->paginate();
        return $permissions;
    }
}
