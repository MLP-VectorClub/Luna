<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class PcgSlotHistory extends Model
{
    protected $table = 'pcg_slot_history';

    protected $fillable = ['user_id', 'change_type', 'change_amount', 'change_data'];

    protected $casts = ['change_data' => 'array'];

    /** Change type => whether it adds slots (true) or takes them away (false) */
    public const VALID_CHANGE_TYPES = [
        'post_approved' => true,
        'post_unapproved' => false,
        'staff_member' => true,
        'staff_join' => true,
        'staff_leave' => false,
        'appearance_del' => true,
        'appearance_add' => false,
        'free_trial' => true,
        'manual_give' => true,
        'manual_take' => false,
    ];

    public const CHANGE_DESC = [
        'post_approved' => 'Post approved',
        'post_unapproved' => 'Post un-approved',
        'staff_member' => 'Being staff',
        'staff_join' => 'Joined staff',
        'staff_leave' => 'Left staff',
        'appearance_del' => 'Appearance deleted',
        'appearance_add' => 'Appearance created',
        'free_trial' => 'Free slot',
        'manual_give' => 'Manually given',
        'manual_take' => 'Manually taken',
    ];

    public const DEFAULT_CHANGE = [
        'post' => 1,
        'staff' => 10,
        'appearance' => 10,
        'free' => 10,
    ];

    public static function record(int $user_id, string $change_type, ?int $change_amount = null, ?array $change_data = null, $created_at = null): self
    {
        if (!isset(self::VALID_CHANGE_TYPES[$change_type])) {
            throw new RuntimeException("Invalid change type: $change_type");
        }

        if ($change_amount === null) {
            $key = strtok($change_type, '_');
            if (!isset(self::DEFAULT_CHANGE[$key])) {
                throw new RuntimeException("No default change amount specified for type: $change_type");
            }
            $change_amount = self::DEFAULT_CHANGE[$key];
            if ($key === 'post' && $change_data !== null) {
                $change_data['type'] = 'post';
            }
        }
        if (self::VALID_CHANGE_TYPES[$change_type] === false) {
            $change_amount *= -1;
        }

        $entry = new self(compact('user_id', 'change_type', 'change_amount', 'change_data'));
        if ($created_at !== null) {
            $entry->created_at = $created_at;
            $entry->updated_at = $created_at;
        }
        $entry->save();

        return $entry;
    }

    public static function sumFor(int $user_id): int
    {
        return (int) self::where('user_id', $user_id)->sum('change_amount');
    }
}
