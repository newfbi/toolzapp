<?php

namespace App\Models\Traits;

trait UserACLTrait
{

    public function Role()
    {
        return $this->hasOne(Role::class, 'user_id', 'uuid');
    }
    
    //União entre regras e planos
    public function permissions(): array
    {
        $permissions = $this->permissionsRole();
        return $permissions;
    }

    //Regras atribuida aos usuarios
    public function permissionsRole(): array
    {
        $roles = $this->roles()->with('permissions')->get();
        $permissions = [];
        foreach ($roles as $role) {
            foreach ($role->permissions as $permission) {
                array_push($permissions, $permission->name);
            }
        }

        return $permissions;
    }

    public function hasPermission(string $permissionName): bool
    {
        return in_array($permissionName, $this->permissions());
    }

    public function hasAnyPermission(array $permissions)
    {
        // Compara se existe alguma permissão em comum entre as listas
        return !empty(array_intersect($permissions, $this->permissions()));
    }

    public function isAdmin(): bool
    {
        return in_array($this->email, config('acl.admins'));
    }

    /**
     * Get Roles
     */
    public function roles()
    {
        return $this->belongsToMany(
            Role::class,         // Modelo relacionado
            'role_user',         // Tabela intermediária (pivot table)
            'user_id',         // Chave estrangeira na pivot table para este modelo (users)
            'role_id',           // Chave estrangeira na pivot table para o modelo Role
            'uuid'              // Chave local neste modelo
        );
    }

    /**
     * Roles not linked with this user
     */
    public function rolesAvailable()
    {
        $roles = Role::whereNotIn('roles.id', function ($query) {
            $query->select('role_user.role_id')
                ->from('role_user')
                ->where('role_user.user_id', $this->uuid); // Usa o UUID dinamicamente
        })->get();

        return $roles;
    }
}
