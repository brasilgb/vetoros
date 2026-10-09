<?php

namespace App\Models\App;

use App\Observers\CompanyIdentityObserver;
use App\Tenantable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(CompanyIdentityObserver::class)]
class Company extends Model
{
    use HasFactory, Tenantable;

    protected $guarded = ['_method', 'id'];
}
