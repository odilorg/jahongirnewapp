<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Chatid extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'chatid', 'scheduled_message_id'];
}
