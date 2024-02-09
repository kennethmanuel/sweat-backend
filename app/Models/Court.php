<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Court extends Model
{
    use HasFactory;

    protected $guarded = [
        'id'
    ];

    public function venue() {
        return $this->belongsTo(Venue::class);
    }

    public function images() {
        return $this->hasMany(CourtImage::class)->where('court_images.status', '<>', 0);
    }
}
