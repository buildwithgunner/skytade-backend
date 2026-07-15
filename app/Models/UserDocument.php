<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class UserDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'reviewed_by',
        'type',
        'document_name',
        'file_path',
        'mime_type',
        'file_size',
        'status',
        'review_notes',
        'reviewed_at',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    protected $appends = [
        'file_url',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by');
    }

    public function getFileUrlAttribute(): string
    {
        return url(Storage::url($this->file_path));
    }
}
