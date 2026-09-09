<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    use HasFactory;

    protected $table = 'platform_settings';

    protected $fillable = [
        'key',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    public static function appSettings(): array
    {
        $value = static::query()->where('key', 'app')->value('value');

        return is_array($value) ? $value : [];
    }

    public static function updateAppSettings(array $settings): array
    {
        $record = static::query()->firstOrNew(['key' => 'app']);
        $record->value = $settings;
        $record->save();

        return $settings;
    }
}
