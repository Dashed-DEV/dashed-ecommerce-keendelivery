<?php

namespace Dashed\DashedEcommerceKeendelivery\Models;

use Illuminate\Database\Eloquent\Model;
use Dashed\DashedEcommerceCore\Models\Order;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class KeendeliveryOrder extends Model
{
    use LogsActivity;

    protected static $logFillable = true;

    protected $table = 'dashed__order_keendelivery';

    protected $fillable = [
        'order_id',
        'shipment_id',
        'label',
        'label_url',
        'track_and_trace',
        'label_printed',
    ];

    protected $casts = [
        'track_and_trace' => 'array',
        'label_printed' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults();
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
