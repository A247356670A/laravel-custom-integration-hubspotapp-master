<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Workers\HubspotWorker;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    public $incrementing = false;
    protected $primaryKey = 'id';
    protected $keyType = 'string';

    protected $fillable = [
        'hubspot_account_id',
        'hubspot_user_id',
        'hubspot_access_token',
        'hubspot_refresh_token',
        'hubspot_access_token_expires_in',
        'hubspot_state',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];


    public function updateHubspotTokens()
    {
        $tokens = HubspotWorker::getRefreshToken($this->hubspot_refresh_token);
        $this->update([
            'hubspot_refresh_token' => $tokens->refresh_token,
        ]);
        return $tokens->access_token;
    }
}
